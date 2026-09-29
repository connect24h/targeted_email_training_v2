<?php declare(strict_types=1); require __DIR__."/../lib/bootstrap.php";
require_once __DIR__ . '/../lib/PasswordPolicy.php';
require_once __DIR__ . '/../lib/UserPasswordTokens.php';
require_once __DIR__ . '/../lib/AdminSecurityPolicy.php';
require_once __DIR__ . '/../lib/AdminMfa.php';

const USER_ROLES = ['viewer', 'operator', 'tenant_admin', 'superadmin'];
const TENANT_ADMIN_ROLES = ['viewer', 'operator', 'tenant_admin'];
const USER_STATUSES = ['active', 'suspended'];
/** 一覧と詳細で返す列。password_hash は返さない。 */
const USER_PUBLIC_COLUMNS = 'id, tenant_id, email, name, role, status, failed_count, locked_until, last_login_at, created_at, password_pending, mfa_enabled_at';
/** 本人の多要素認証の操作(必須化で止められていても使える。ほかの操作は組織管理者以上)。 */
const USER_MFA_SELF_ACTIONS = ['mfa_status', 'mfa_setup', 'mfa_enable', 'mfa_disable', 'mfa_recovery_regenerate'];
/** 本人の操作でコードかパスワードを続けて間違えた時に、セッションを切る回数(盗まれたセッションでの総当たりを止める)。 */
const USER_MFA_SELF_MAX_FAILURES = 5;
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
                u.created_at, u.password_pending, u.mfa_enabled_at,
                (SELECT MAX(t.expires_at) FROM user_password_tokens t
                  WHERE t.user_id = u.id AND t.used_at IS NULL AND t.revoked_at IS NULL
                    AND t.expires_at > datetime('now','localtime')) AS password_link_expires_at
         FROM users u
         WHERE u.tenant_id = ? AND u.role != 'learner'
         ORDER BY u.id",
        [$tenantId]
    );
    json_out([
        'success' => true,
        'users' => $users,
        'password_policy' => AdminSecurityPolicy::description($tenantId),
        'security_policy' => AdminSecurityPolicy::effective($tenantId),
    ]);
}

/**
 * パスワードの決まり(A4)。PasswordPolicy(全テナント共通の下限)に、所属テナントと全体の方針(段階1)を重ねる。
 * $tenantId が null はテナントに属さないユーザ(システム管理者)で、全体の方針だけを使う。
 */
function users_validate_password(string $password, ?int $tenantId, ?string $email): void
{
    $violation = AdminSecurityPolicy::violation($password, $tenantId, $email);
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
    users_assert_assignable_role($actor, $role);

    if ($actor['role'] === 'superadmin') {
        $tenantId = $role === 'superadmin' ? null : users_body_optional_int($body, 'tenant_id');
        if ($role !== 'superadmin' && $tenantId === null) {
            json_error('tenant_id は必須です', 400);
        }
    } else {
        $tenantId = (int) $actor['tenant_id'];
    }
    if ($password !== null) {
        users_validate_password($password, $tenantId, $email);
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
        // 役割を superadmin に変えるとテナントから外れるので、全体の方針だけを使う
        users_validate_password($password, $role === 'superadmin' || $target['tenant_id'] === null ? null : (int) $target['tenant_id'],
            (string) $target['email']);
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

// ============ 多要素認証(段階1、G43) ============

/** 本人の操作でコードかパスワードを間違えた回数を数え、上限でセッションを切る。 */
function users_mfa_self_failure(array $actor, string $what): never
{
    $count = (int) ($_SESSION['mfa_self_failures'] ?? 0) + 1;
    $_SESSION['mfa_self_failures'] = $count;
    audit('user.mfa_self_failed', 'user_id=' . (int) $actor['id'] . ',step=' . $what . ',count=' . $count);
    if ($count >= USER_MFA_SELF_MAX_FAILURES) {
        $_SESSION = [];
        json_error('間違いが続いたため、ログアウトしました。もう一度ログインしてください', 401);
    }
    json_error($what === 'password' ? 'パスワードが正しくありません' : '確認コードが正しくありません', 400);
}

function users_mfa_self_success(): void
{
    unset($_SESSION['mfa_self_failures']);
}

/** 本人の多要素認証の状態(登録の画面とアカウントの画面が使う)。 */
function users_handle_mfa_status(array $actor): never
{
    $userId = (int) $actor['id'];
    $row = Db::one('SELECT tenant_id, mfa_secret, mfa_enabled_at FROM users WHERE id = ?', [$userId]);
    $tenantId = $row !== null && $row['tenant_id'] !== null ? (int) $row['tenant_id'] : null;
    json_out([
        'success' => true,
        'enabled' => $row !== null && $row['mfa_enabled_at'] !== null,
        'enabled_at' => $row['mfa_enabled_at'] ?? null,
        'setup_pending' => $row !== null && $row['mfa_enabled_at'] === null && $row['mfa_secret'] !== null,
        'recovery_remaining' => AdminMfa::remainingRecoveryCodes($userId),
        'required' => AdminSecurityPolicy::effective($tenantId)['require_mfa'],
        'available' => AdminMfa::keyConfigured(),
    ]);
}

/** 登録を始める: 新しい秘密鍵と、認証アプリに入れる URI を返す(まだ有効にはしない)。 */
function users_handle_mfa_setup(array $actor): never
{
    tet2_require_csrf();
    if (!AdminMfa::keyConfigured()) {
        json_error('多要素認証の準備ができていません（暗号鍵が未設定です）。システム管理者に連絡してください', 503);
    }
    try {
        $r = AdminMfa::beginEnrollment((int) $actor['id'], (string) $actor['email']);
    } catch (DomainException $e) {
        json_error($e->getMessage(), 409);
    }
    audit('user.mfa_setup', 'user_id=' . (int) $actor['id']);
    json_out(['success' => true, 'secret' => $r['secret'], 'otpauth_uri' => $r['otpauth_uri'], 'issuer' => AdminMfa::ISSUER]);
}

/** 認証アプリのコードを確かめて有効にし、回復コードを1回だけ返す。 */
function users_handle_mfa_enable(array $actor): never
{
    tet2_require_csrf();
    $code = users_string(json_body(), 'code');
    try {
        $codes = AdminMfa::completeEnrollment((int) $actor['id'], $code, time());
    } catch (DomainException $e) {
        json_error($e->getMessage(), 409);
    }
    if ($codes === null) {
        users_mfa_self_failure($actor, 'enable');
    }
    users_mfa_self_success();
    audit('user.mfa_enable', 'user_id=' . (int) $actor['id']);
    json_out(['success' => true, 'recovery_codes' => $codes]);
}

/** 本人の解除。今のコード(code)かパスワード(password)で本人を確かめる。登録の途中なら確かめずに取り消す。 */
function users_handle_mfa_disable(array $actor): never
{
    tet2_require_csrf();
    $userId = (int) $actor['id'];
    if (AdminMfa::isEnabled($userId)) {
        users_mfa_confirm_self($actor, json_body());
    }
    AdminMfa::disable($userId);
    users_mfa_self_success();
    audit('user.mfa_disable', 'user_id=' . $userId);
    json_out(['success' => true]);
}

/** 回復コードを作り直す(今のコードで本人を確かめる)。前の回復コードはすべて使えなくなる。 */
function users_handle_mfa_recovery_regenerate(array $actor): never
{
    tet2_require_csrf();
    $userId = (int) $actor['id'];
    if (!AdminMfa::isEnabled($userId)) {
        json_error('多要素認証が有効ではありません', 409);
    }
    users_mfa_confirm_self($actor, ['code' => users_string(json_body(), 'code')]);
    $codes = AdminMfa::regenerateRecoveryCodes($userId);
    users_mfa_self_success();
    audit('user.mfa_recovery_regenerate', 'user_id=' . $userId);
    json_out(['success' => true, 'recovery_codes' => $codes]);
}

/** code(認証アプリ)か password のどちらかで本人を確かめる。違えば回数を数えて止める。 */
function users_mfa_confirm_self(array $actor, array $body): void
{
    $userId = (int) $actor['id'];
    $code = is_string($body['code'] ?? null) ? trim($body['code']) : '';
    $password = is_string($body['password'] ?? null) ? $body['password'] : '';
    if ($code === '' && $password === '') {
        json_error('確認コードかパスワードを入力してください', 400);
    }
    if ($code !== '') {
        try {
            $ok = AdminMfa::verifyCode($userId, $code, time());
        } catch (RuntimeException $e) {
            error_log('mfa confirm: ' . $e->getMessage());
            json_error('確認コードを確かめられません。パスワードで確かめてください', 503);
        }
        if (!$ok) {
            users_mfa_self_failure($actor, 'code');
        }
        return;
    }
    $row = Db::one('SELECT password_hash FROM users WHERE id = ?', [$userId]);
    if ($row === null || !password_verify($password, (string) $row['password_hash'])) {
        users_mfa_self_failure($actor, 'password');
    }
}

/**
 * 管理者による多要素認証の解除(端末をなくした人のため)。組織管理者は自組織、システム管理者は全部。
 * 自分より強い役割のユーザと、自分自身は解除できない(自分は本人の画面から解除する)。
 */
function users_handle_mfa_reset(array $actor): never
{
    tet2_require_csrf();
    $id = users_int(json_body(), 'id');
    if ((int) $actor['id'] === $id) {
        json_error('自分の多要素認証は、アカウントの画面から解除してください', 400);
    }
    $target = users_assert_manageable($actor, $id);
    if (users_role_rank((string) $target['role']) > users_role_rank((string) $actor['role'])) {
        json_error('権限がありません', 403);
    }
    $changed = AdminMfa::disable($id);
    audit('user.mfa_reset', 'user_id=' . $id . ',changed=' . ($changed ? 1 : 0));
    json_out(['success' => true, 'changed' => $changed]);
}

// ============ パスワードと多要素認証の方針(段階1、G44) ============

/** 方針の行を画面に返す形にする。 */
function users_policy_payload(array $row): array
{
    return [
        'min_length' => $row['min_length'],
        'min_classes' => $row['min_classes'],
        'require_mfa' => $row['require_mfa'],
        'banned_words' => $row['banned_words'],
        'configured' => $row['exists'],
        'updated_at' => $row['updated_at'],
    ];
}

/** 方針の取得。テナントの行と全体の行(組織管理者には読むだけ)と、実際に効く方針を返す。 */
function users_handle_policy_get(array $actor): never
{
    $requested = users_query_int('tenant_id');
    $tenantId = $actor['role'] === 'superadmin' && $requested === null && $actor['tenant_id'] === null
        ? null : effective_tenant_id($actor, $requested);
    // 全体の行の禁止語はシステム管理者が決める。組織管理者には数だけを返し、語そのものは見せない
    $global = users_policy_payload(AdminSecurityPolicy::row(null));
    if ($actor['role'] !== 'superadmin') {
        $global['banned_words_count'] = count($global['banned_words']);
        $global['banned_words'] = [];
    }
    json_out([
        'success' => true,
        'tenant_id' => $tenantId,
        'tenant' => $tenantId !== null ? users_policy_payload(AdminSecurityPolicy::row($tenantId)) : null,
        'global' => $global,
        'effective' => AdminSecurityPolicy::effective($tenantId),
        'can_edit_global' => $actor['role'] === 'superadmin',
        'mfa_available' => AdminMfa::keyConfigured(),
        'limits' => ['min_length' => PasswordPolicy::MIN_LENGTH, 'max_length' => AdminSecurityPolicy::MAX_MIN_LENGTH,
            'banned_words' => PasswordDenyList::MAX_WORDS, 'banned_word_min' => PasswordDenyList::MIN_TOKEN_LENGTH,
            'banned_word_max' => PasswordDenyList::MAX_WORD_LENGTH],
    ]);
}

/** 方針の保存。scope=global は全体の行(システム管理者だけ)、それ以外は対象のテナントの行。弱くはできない。 */
function users_handle_policy_set(array $actor): never
{
    tet2_require_csrf();
    $body = json_body();
    $scope = $body['scope'] ?? 'tenant';
    if (!in_array($scope, ['tenant', 'global'], true)) {
        json_error('scope が不正です', 400);
    }
    foreach (['min_length', 'min_classes'] as $key) {
        if (!is_int($body[$key] ?? null)) {
            json_error($key . ' が不正です', 400);
        }
    }
    if (!is_bool($body['require_mfa'] ?? null)) {
        json_error('require_mfa が不正です', 400);
    }
    // 禁止語(改行区切りの文字列)。送られなければ今の値を残す
    $bannedWords = null;
    if (array_key_exists('banned_words', $body) && $body['banned_words'] !== null) {
        if (!is_string($body['banned_words']) || strlen($body['banned_words']) > 8000) {
            json_error('banned_words が不正です', 400);
        }
        try {
            $bannedWords = PasswordDenyList::parseCustom($body['banned_words']);
        } catch (InvalidArgumentException $e) {
            json_error($e->getMessage(), 400);
        }
    }
    if ($scope === 'global') {
        if ($actor['role'] !== 'superadmin') {
            json_error('権限がありません', 403);
        }
        $tenantId = null;
    } else {
        $tenantId = effective_tenant_id($actor, users_body_optional_int($body, 'tenant_id'));
    }
    // 暗号鍵がないと誰も登録できず、必須にした組織の全員が操作できなくなる
    if ($body['require_mfa'] && !AdminMfa::keyConfigured()) {
        json_error('多要素認証の準備ができていないため（暗号鍵が未設定）、必須にはできません', 409);
    }
    try {
        AdminSecurityPolicy::save($tenantId, $body['min_length'], $body['min_classes'], $body['require_mfa'], (int) $actor['id'], $bannedWords);
    } catch (InvalidArgumentException $e) {
        json_error($e->getMessage(), 400);
    }
    audit('security_policy.update', 'tenant_id=' . ($tenantId ?? 'global') . ',min_length=' . $body['min_length']
        . ',min_classes=' . $body['min_classes'] . ',require_mfa=' . ($body['require_mfa'] ? 1 : 0)
        . ($bannedWords !== null ? ',banned_words=' . count($bannedWords) : ''));
    json_out(['success' => true, 'policy' => users_policy_payload(AdminSecurityPolicy::row($tenantId)),
        'effective' => AdminSecurityPolicy::effective($tenantId)]);
}

try {
    $action = $_GET['action'] ?? '';
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    // 本人の多要素認証は、どの役割でも使える。必須化で止められているユーザも登録できるよう、専用の入口を使う
    if (in_array($action, USER_MFA_SELF_ACTIONS, true)) {
        $self = require_auth_for_mfa_enrollment();
        if ($action === 'mfa_status' && $method === 'GET') {
            users_handle_mfa_status($self);
        }
        if ($action === 'mfa_setup' && $method === 'POST') {
            users_handle_mfa_setup($self);
        }
        if ($action === 'mfa_enable' && $method === 'POST') {
            users_handle_mfa_enable($self);
        }
        if ($action === 'mfa_disable' && $method === 'POST') {
            users_handle_mfa_disable($self);
        }
        if ($action === 'mfa_recovery_regenerate' && $method === 'POST') {
            users_handle_mfa_recovery_regenerate($self);
        }
        json_error('不正なアクションです', 400);
    }

    $actor = require_role('tenant_admin');

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
    if ($action === 'mfa_reset' && $method === 'POST') {
        users_handle_mfa_reset($actor);
    }
    if ($action === 'security_policy' && $method === 'GET') {
        users_handle_policy_get($actor);
    }
    if ($action === 'security_policy' && $method === 'POST') {
        users_handle_policy_set($actor);
    }
    json_error('不正なアクションです', 400);
} catch (Throwable $e) {
    if (users_is_unique_error($e)) {
        json_error('email は既に使用されています', 409);
    }
    error_log($e->getMessage());
    json_error('サーバエラー', 500);
}
