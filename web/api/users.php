<?php declare(strict_types=1); require __DIR__."/../lib/bootstrap.php";
require_once __DIR__ . '/../lib/PasswordPolicy.php';
require_once __DIR__ . '/../lib/UserPasswordTokens.php';

const USER_ROLES = ['viewer', 'operator', 'tenant_admin', 'superadmin'];
const TENANT_ADMIN_ROLES = ['viewer', 'operator', 'tenant_admin'];
const USER_STATUSES = ['active', 'suspended'];
/** 一覧と詳細で返す列。password_hash は返さない。 */
const USER_PUBLIC_COLUMNS = 'id, tenant_id, email, name, role, status, failed_count, locked_until, last_login_at, created_at, password_pending';
/** CSV の一括登録の上限の行数。 */
const USER_CSV_MAX_ROWS = 1000;

function users_string(array $body, string $key): string
{
    if (!array_key_exists($key, $body) || !is_string($body[$key]) || trim($body[$key]) === '') {
        json_error($key . ' は必須です', 400);
    }
    return trim($body[$key]);
}

function users_optional_string(array $body, string $key): ?string
{
    if (!array_key_exists($key, $body)) {
        return null;
    }
    if (!is_string($body[$key]) || trim($body[$key]) === '') {
        json_error($key . ' が不正です', 400);
    }
    return trim($body[$key]);
}

function users_int(array $body, string $key): int
{
    if (!array_key_exists($key, $body) || !is_int($body[$key]) || $body[$key] < 1) {
        json_error($key . ' が不正です', 400);
    }
    return $body[$key];
}

function users_query_int(string $key): ?int
{
    if (!array_key_exists($key, $_GET) || $_GET[$key] === '') {
        return null;
    }
    $value = filter_var($_GET[$key], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($value === false) {
        json_error($key . ' が不正です', 400);
    }
    return (int) $value;
}

function users_body_optional_int(array $body, string $key): ?int
{
    if (!array_key_exists($key, $body) || $body[$key] === null) {
        return null;
    }
    if (!is_int($body[$key]) || $body[$key] < 1) {
        json_error($key . ' が不正です', 400);
    }
    return $body[$key];
}

function users_is_unique_error(Throwable $e): bool
{
    return $e instanceof PDOException && str_contains($e->getMessage(), 'UNIQUE');
}

function users_role_rank(string $role): int
{
    return ['viewer' => 1, 'operator' => 2, 'tenant_admin' => 3, 'superadmin' => 4][$role] ?? 0;
}

function users_assert_manageable(array $actor, int $id): array
{
    // 受講者のマイページのアカウント(learner)は、ここ(管理画面のユーザ)では扱わない(api/learners.php)
    if ($actor['role'] === 'superadmin') {
        $user = Db::one(
            'SELECT ' . USER_PUBLIC_COLUMNS . " FROM users WHERE id = ? AND role != 'learner'",
            [$id]
        );
    } else {
        $user = Db::one(
            'SELECT ' . USER_PUBLIC_COLUMNS . " FROM users WHERE id = ? AND tenant_id = ? AND role != 'learner'",
            [$id, (int) $actor['tenant_id']]
        );
    }
    if ($user === null) {
        json_error('ユーザが見つかりません', 404);
    }
    return $user;
}

function users_handle_list(array $actor): never
{
    $tenantId = effective_tenant_id($actor, users_query_int('tenant_id'));
    $users = Db::all(
        "SELECT u.id, u.tenant_id, u.email, u.name, u.role, u.status, u.failed_count, u.locked_until, u.last_login_at,
                u.created_at, u.password_pending,
                (SELECT MAX(t.expires_at) FROM user_password_tokens t
                  WHERE t.user_id = u.id AND t.used_at IS NULL AND t.revoked_at IS NULL
                    AND t.expires_at > datetime('now','localtime')) AS password_link_expires_at
         FROM users u
         WHERE u.tenant_id = ? AND u.role != 'learner'
         ORDER BY u.id",
        [$tenantId]
    );
    json_out(['success' => true, 'users' => $users, 'password_policy' => PasswordPolicy::DESCRIPTION]);
}

/** パスワードの決まり(A4)。全テナント共通で PasswordPolicy に1つにまとめる。 */
function users_validate_password(string $password): void
{
    $violation = PasswordPolicy::violation($password);
    if ($violation !== null) {
        json_error($violation, 400);
    }
}

/** 作成する人が付けられる役割か(既存の決まり: superadmin は全部、それ以外は組織管理者以下)。 */
function users_assert_assignable_role(array $actor, string $role): void
{
    if (!in_array($role, USER_ROLES, true)) {
        json_error('role が不正です', 400);
    }
    if ($actor['role'] !== 'superadmin' && !in_array($role, TENANT_ADMIN_ROLES, true)) {
        json_error('role が不正です', 400);
    }
}

/** 自分のパスワードを変えた時は、自分のセッションを切らないようログイン時の値を合わせる。 */
function users_refresh_own_session_epoch(array $actor, int $userId): void
{
    if ((int) $actor['id'] !== $userId || session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }
    $row = Db::one('SELECT session_epoch FROM users WHERE id = ?', [$userId]);
    $_SESSION['pw_epoch'] = (int) ($row['session_epoch'] ?? 0);
}

/**
 * 招待・再設定のメールを送り、監査ログを残す。送れなかった時は理由を返す(トークンは無効にしてある)。
 * @return array{sent: bool, purpose?: string, expires_at?: string, error?: string, code?: int}
 */
function users_send_password_mail(array $actor, array $user): array
{
    try {
        $r = UserPasswordTokens::issueAndSend($user, (int) $actor['id']);
    } catch (PasswordTokenException $e) {
        audit(((int) ($user['password_pending'] ?? 0) === 1 ? 'user.invite' : 'user.password_reset_request'),
            'user_id=' . (int) $user['id'] . ',sent=0,reason=' . $e->reason);
        return ['sent' => false, 'error' => $e->getMessage(), 'code' => $e->httpCode];
    }
    audit($r['purpose'] === 'invite' ? 'user.invite' : 'user.password_reset_request', 'user_id=' . (int) $user['id'] . ',sent=1');
    return ['sent' => true, 'purpose' => $r['purpose'], 'expires_at' => $r['expires_at']];
}

function users_handle_create(array $actor): never
{
    tet2_require_csrf();
    $body = json_body();
    $email = users_string($body, 'email');
    $name = users_string($body, 'name');
    $role = users_string($body, 'role');
    // send_invite=true: 初期パスワードを入れず、パスワード設定のメール(招待)を送る
    $sendInvite = ($body['send_invite'] ?? false) === true;
    $password = $sendInvite ? null : users_string($body, 'password');

    if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        json_error('email が不正です', 400);
    }
    if ($password !== null) {
        users_validate_password($password);
    }
    users_assert_assignable_role($actor, $role);

    if ($actor['role'] === 'superadmin') {
        $tenantId = $role === 'superadmin' ? null : users_body_optional_int($body, 'tenant_id');
        if ($role !== 'superadmin' && $tenantId === null) {
            json_error('tenant_id は必須です', 400);
        }
    } else {
        $tenantId = (int) $actor['tenant_id'];
    }
    if ($sendInvite && !TenantStatus::userAllowed($tenantId, $role)) {
        json_error('停止中・削除済みのテナントのユーザには招待を送れません', 409);
    }

    $id = Db::insert(
        'INSERT INTO users (tenant_id, email, password_hash, name, role, status, password_pending) VALUES (?, ?, ?, ?, ?, ?, ?)',
        [$tenantId, $email, $password !== null ? password_hash($password, PASSWORD_DEFAULT) : UserPasswordTokens::unusableHash(),
            $name, $role, 'active', $password === null ? 1 : 0]
    );
    audit('user.create', 'user_id=' . $id . ($sendInvite ? ',invite=1' : ''));
    $user = Db::one('SELECT ' . USER_PUBLIC_COLUMNS . ' FROM users WHERE id = ?', [$id]);
    $out = ['success' => true, 'user' => $user];
    if ($sendInvite) {
        // 送れなくてもユーザは残す(パスワード未設定)。一覧の「パスワード設定のメールを送る」で送り直せる
        $out['invite'] = users_send_password_mail($actor, $user);
    }
    json_out($out, 201);
}

/** 1人に招待(パスワード未設定の時)またはパスワード再設定のメールを送る。同じユーザの古いリンクは無効になる。 */
function users_handle_send_password_mail(array $actor): never
{
    tet2_require_csrf();
    $body = json_body();
    $id = users_int($body, 'id');
    $user = users_assert_manageable($actor, $id);
    $r = users_send_password_mail($actor, $user);
    if (!$r['sent']) {
        json_error((string) $r['error'], (int) $r['code']);
    }
    json_out(['success' => true, 'purpose' => $r['purpose'], 'expires_at' => $r['expires_at']]);
}

function users_handle_update(array $actor): never
{
    tet2_require_csrf();
    $body = json_body();
    $id = users_int($body, 'id');
    $target = users_assert_manageable($actor, $id);
    $name = users_optional_string($body, 'name');
    $role = users_optional_string($body, 'role');
    $status = users_optional_string($body, 'status');
    $password = users_optional_string($body, 'password');

    if ($name === null && $role === null && $status === null && $password === null) {
        json_error('更新項目がありません', 400);
    }
    users_validate_update($actor, $target, $role, $status, $password);
    $passwordHash = $password !== null ? password_hash($password, PASSWORD_DEFAULT) : null;

    Db::tx(static function () use ($name, $role, $status, $passwordHash, $id): void {
        // パスワードを変えたら: 未設定を外し、session_epoch を増やしてそのユーザの既存のセッションを切り、
        // まだ使えるパスワード設定のリンクも無効にする
        Db::run(
            'UPDATE users
             SET name = COALESCE(?, name),
                 role = COALESCE(?, role),
                 status = COALESCE(?, status),
                 password_hash = COALESCE(?, password_hash),
                 failed_count = CASE WHEN ? IS NOT NULL THEN 0 ELSE failed_count END,
                 locked_until = CASE WHEN ? IS NOT NULL THEN NULL ELSE locked_until END,
                 password_pending = CASE WHEN ? IS NOT NULL THEN 0 ELSE password_pending END,
                 session_epoch = CASE WHEN ? IS NOT NULL THEN session_epoch + 1 ELSE session_epoch END,
                 tenant_id = CASE WHEN ? = ? THEN NULL ELSE tenant_id END
             WHERE id = ?',
            [$name, $role, $status, $passwordHash, $passwordHash, $passwordHash, $passwordHash, $passwordHash, $role, 'superadmin', $id]
        );
        if ($passwordHash !== null) {
            UserPasswordTokens::revokeOpen($id);
        }
    });
    if ($passwordHash !== null) {
        users_refresh_own_session_epoch($actor, $id);
    }
    audit('user.update', 'user_id=' . $id . ($passwordHash !== null ? ',password=1' : ''));
    $user = users_assert_manageable($actor, $id);
    json_out(['success' => true, 'user' => $user]);
}

function users_validate_update(array $actor, array $target, ?string $role, ?string $status, ?string $password): void
{
    if ($role !== null) {
        users_assert_assignable_role($actor, $role);
    }
    if ($status !== null && !in_array($status, USER_STATUSES, true)) {
        json_error('status が不正です', 400);
    }
    if ($password !== null) {
        users_validate_password($password);
    }
    if ((int) $actor['id'] === (int) $target['id']) {
        if ($role !== null && users_role_rank($role) < users_role_rank((string) $actor['role'])) {
            json_error('自分自身の role 降格はできません', 400);
        }
        if ($status === 'suspended') {
            json_error('自分自身の status 停止はできません', 400);
        }
    }
    if ($role !== null && $role !== 'superadmin' && $target['tenant_id'] === null) {
        json_error('tenant_id が無いユーザをこの role には変更できません', 400);
    }
}

function users_handle_delete(array $actor): never
{
    tet2_require_csrf();
    $body = json_body();
    $id = users_int($body, 'id');
    if ((int) $actor['id'] === $id) {
        json_error('自分自身は削除できません', 400);
    }
    users_assert_manageable($actor, $id);
    // campaigns.created_by は users(id) への外部キー。作成者を残したまま DELETE すると
    // FK 制約違反で例外→サーバエラーになる。作成者参照を NULL 化してから削除する(原子的)。
    // キャンペーン自体は残す(履歴保持)。誰が消したかは下の audit_log で追跡可能。
    Db::tx(function () use ($id): void {
        Db::run('UPDATE campaigns SET created_by = NULL WHERE created_by = ?', [$id]);
        Db::run('DELETE FROM users WHERE id = ?', [$id]);
    });
    audit('user.delete', 'user_id=' . $id);
    json_out(['success' => true]);
}

/** CSV の見出しの別名 → 列名。英語(email/name/role)と日本語の両方を受け付ける。 */
const USER_CSV_HEADERS = [
    'email' => 'email', 'メール' => 'email', 'メールアドレス' => 'email',
    'name' => 'name', '氏名' => 'name', '名前' => 'name',
    'role' => 'role', 'ロール' => 'role', '役割' => 'role',
];
/** 画面の表記の役割名 → role の値(CSV では日本語でも書ける)。 */
const USER_ROLE_LABELS = ['閲覧者' => 'viewer', 'オペレータ' => 'operator', '組織管理者' => 'tenant_admin', 'システム管理者' => 'superadmin'];

/**
 * CSV の1行を検証して [email, name, role] を返す。だめなら理由の例外。
 * superadmin はテナントに属さないため、CSV では作らない(1件ずつの作成で作る)。
 * @param array<string,int> $map
 * @return array{0:string,1:string,2:string}
 */
function users_csv_row(array $cells, array $map, array $seen): array
{
    $get = static fn (string $key): string => trim((string) ($cells[$map[$key]] ?? ''));
    $email = $get('email');
    $name = $get('name');
    $role = $get('role');
    $role = USER_ROLE_LABELS[$role] ?? $role;
    if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        throw new InvalidArgumentException('メールアドレスの形が正しくありません');
    }
    if ($name === '') {
        throw new InvalidArgumentException('氏名がありません');
    }
    if (mb_strlen($name, 'UTF-8') > 100 || preg_match('/[\x00-\x1F\x7F]/', $name) === 1) {
        throw new InvalidArgumentException('氏名は100文字以内で、制御文字を含めないでください');
    }
    if (!in_array($role, USER_ROLES, true)) {
        throw new InvalidArgumentException('役割が正しくありません（viewer、operator、tenant_admin のどれか）');
    }
    // テナントの中の役割だけ(組織管理者以下は既存の決まりで tenant_admin も superadmin も付けられる範囲)
    if (!in_array($role, TENANT_ADMIN_ROLES, true)) {
        throw new InvalidArgumentException($role === 'superadmin'
            ? 'システム管理者は CSV では作れません。1件ずつ作成してください'
            : 'この役割は付けられません');
    }
    $key = strtolower($email);
    if (isset($seen[$key])) {
        throw new InvalidArgumentException('同じメールアドレスが CSV の ' . $seen[$key] . ' 行目にもあります');
    }
    if (Db::one('SELECT id FROM users WHERE lower(email) = ?', [$key]) !== null) {
        throw new InvalidArgumentException('このメールアドレスは既に使われています');
    }
    return [$email, $name, $role];
}

/**
 * CSV の一括登録(A3)。列は email、name、role。パスワードは入れず、パスワード未設定で作る。
 * send_invite=true なら作った人に招待メールを送る。送らない場合は、後で一覧から送れる。
 * 正しい行だけを作り、だめな行は行番号と理由を返す(対象者の CSV 取込と同じ流儀)。
 */
function users_handle_import_csv(array $actor): never
{
    tet2_require_csrf();
    $body = json_body();
    $csv = users_string($body, 'csv');
    $sendInvite = ($body['send_invite'] ?? false) === true;
    $tenantId = effective_tenant_id($actor, users_body_optional_int($body, 'tenant_id'));
    if ($sendInvite && !TenantStatus::isOperational($tenantId)) {
        json_error('停止中・削除済みのテナントのユーザには招待を送れません', 409);
    }

    $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv;
    $lines = preg_split('/\r\n|\n|\r/', $csv) ?: [];
    $headers = array_map(static fn ($h): string => trim((string) $h), str_getcsv((string) array_shift($lines), ',', '"', ''));
    $map = [];
    foreach ($headers as $index => $header) {
        $key = USER_CSV_HEADERS[strtolower($header)] ?? USER_CSV_HEADERS[$header] ?? null;
        if ($key !== null && !isset($map[$key])) {
            $map[$key] = $index;
        }
    }
    foreach (['email', 'name', 'role'] as $required) {
        if (!isset($map[$required])) {
            json_error('1行目の見出しに email、name、role の列が必要です', 400);
        }
    }
    $rows = array_filter($lines, static fn ($line): bool => trim((string) $line) !== '');
    if (count($rows) === 0) {
        json_error('登録する行がありません', 400);
    }
    if (count($rows) > USER_CSV_MAX_ROWS) {
        json_error('一度に登録できるのは ' . USER_CSV_MAX_ROWS . ' 行までです', 400);
    }

    $created = [];
    $errors = [];
    $seen = [];
    Db::tx(static function () use ($rows, $map, $tenantId, &$created, &$errors, &$seen): void {
        foreach ($rows as $index => $line) {
            $lineNumber = $index + 2;
            try {
                [$email, $name, $role] = users_csv_row(str_getcsv((string) $line, ',', '"', ''), $map, $seen);
            } catch (InvalidArgumentException $e) {
                $errors[] = ['line' => $lineNumber, 'reason' => $e->getMessage()];
                continue;
            }
            $seen[strtolower($email)] = $lineNumber;
            $id = Db::insert(
                'INSERT INTO users (tenant_id, email, password_hash, name, role, status, password_pending) VALUES (?, ?, ?, ?, ?, ?, 1)',
                [$tenantId, $email, UserPasswordTokens::unusableHash(), $name, $role, 'active']
            );
            $created[] = ['id' => $id, 'line' => $lineNumber, 'email' => $email];
        }
    });
    audit('user.import_csv', 'tenant_id=' . $tenantId . ',created=' . count($created) . ',errors=' . count($errors) . ',invite=' . ($sendInvite ? 1 : 0));

    $invited = 0;
    $inviteErrors = [];
    if ($sendInvite) {
        foreach ($created as $c) {
            $user = Db::one('SELECT ' . USER_PUBLIC_COLUMNS . ' FROM users WHERE id = ?', [$c['id']]);
            $r = users_send_password_mail($actor, $user);
            if ($r['sent']) {
                $invited++;
            } else {
                $inviteErrors[] = ['line' => $c['line'], 'email' => $c['email'], 'reason' => (string) $r['error']];
            }
        }
    }
    json_out([
        'success' => true,
        'created' => count($created),
        'skipped' => count($errors),
        'errors' => $errors,
        'invited' => $invited,
        'invite_errors' => $inviteErrors,
    ]);
}

/** ユーザの CSV(A3)。一括登録と同じ email、name、role に、状態、最終ログイン、パスワードの状態を足す(取込では無視する列)。 */
function users_build_csv(int $tenantId): string
{
    $rows = Db::all(
        "SELECT email, name, role, status, last_login_at, password_pending FROM users WHERE tenant_id = ? AND role != 'learner' ORDER BY id",
        [$tenantId]
    );
    $out = fopen('php://temp', 'r+');
    fputcsv($out, ['email', 'name', 'role', 'status', 'last_login_at', 'password'], ',', '"', '');
    foreach ($rows as $r) {
        fputcsv($out, array_map('tet2_csv_sanitize', [
            (string) $r['email'], (string) ($r['name'] ?? ''), (string) $r['role'], (string) $r['status'],
            (string) ($r['last_login_at'] ?? ''), (int) $r['password_pending'] === 1 ? '未設定' : '設定済み',
        ]), ',', '"', '');
    }
    rewind($out);
    $csv = (string) stream_get_contents($out);
    fclose($out);
    return $csv;
}

function users_handle_export_csv(array $actor): never
{
    $tenantId = effective_tenant_id($actor, users_query_int('tenant_id'));
    $csv = users_build_csv($tenantId);
    audit('user.export_csv', 'tenant_id=' . $tenantId . ',count=' . max(0, substr_count($csv, "\n") - 1));
    http_response_code(200);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="users_' . date('Ymd_His') . '.csv"');
    header('Cache-Control: no-store');
    echo "\xEF\xBB\xBF"; // UTF-8 BOM(Excel の文字化け対策)
    echo $csv;
    exit;
}

try {
    $actor = require_role('tenant_admin');
    $action = $_GET['action'] ?? '';
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($action === 'list' && $method === 'GET') {
        users_handle_list($actor);
    }
    if ($action === 'create' && $method === 'POST') {
        users_handle_create($actor);
    }
    if ($action === 'update' && $method === 'POST') {
        users_handle_update($actor);
    }
    if ($action === 'delete' && $method === 'POST') {
        users_handle_delete($actor);
    }
    if ($action === 'send_password_mail' && $method === 'POST') {
        users_handle_send_password_mail($actor);
    }
    if ($action === 'import_csv' && $method === 'POST') {
        users_handle_import_csv($actor);
    }
    if ($action === 'export_csv' && $method === 'GET') {
        users_handle_export_csv($actor);
    }
    json_error('不正なアクションです', 400);
} catch (Throwable $e) {
    if (users_is_unique_error($e)) {
        json_error('email は既に使用されています', 409);
    }
    error_log($e->getMessage());
    json_error('サーバエラー', 500);
}
