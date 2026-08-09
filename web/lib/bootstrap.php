<?php
/**
 * TET v2 共通ブートストラップ。全 API の冒頭で require する。
 * セッション初期化・JSON レスポンス・認証/ロール/テナント分離・CSRF を提供する。
 */
declare(strict_types=1);

require_once __DIR__ . '/Db.php';

/**
 * 役職カテゴリの正規値。targets.position_category と position_masters.category が取り得る値。
 *
 * 複数の API から参照するため bootstrap に単一定義する(api/*.php 側で個別に const を
 * 定義すると、テストの load_api() が各ファイルを eval する際に再定義エラーになる)。
 */
const TET2_POSITION_CATEGORIES = ['役員', '管理職', '一般従業員'];

/**
 * 旧称・表記ゆれを正規値へ寄せる対応表。
 * 「社員」は 2026-08-10 以前の正規値。過去に出力した CSV を再取込しても
 * 値が失われないよう、入力側では引き続き受け付ける。
 */
const TET2_POSITION_CATEGORY_ALIASES = [
    '社員'     => '一般従業員',
    '一般社員' => '一般従業員',
];

/**
 * 役職カテゴリを正規値へ正規化する。正規値でもエイリアスでもなければ null を返す。
 * (呼び出し側が「不正値として弾く」か「NULL として取り込む」かを選べるようにする)
 */
function tet2_normalize_position_category(?string $value): ?string
{
    if ($value === null) {
        return null;
    }
    $value = trim($value);
    if ($value === '') {
        return null;
    }
    $value = TET2_POSITION_CATEGORY_ALIASES[$value] ?? $value;
    return in_array($value, TET2_POSITION_CATEGORIES, true) ? $value : null;
}

// ---- セッション ----
function tet2_init_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name('TET2SESID');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/tet2',
        'httponly' => true,
        'samesite' => 'Lax',
        // HTTPS 化済み。/tet2 への HTTP アクセスは Apache が HTTPS へ強制リダイレクトする。
        'secure'   => true,
    ]);
    session_start();
}

// ---- セキュリティヘッダ ----
function tet2_security_headers(): void
{
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
}

// ---- JSON レスポンス ----
function json_out($data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function json_error(string $message, int $code = 400): never
{
    json_out(['success' => false, 'error' => $message], $code);
}

/**
 * CSVインジェクション対策。CSVの1セルとして出力する値を無害化する。
 * 先頭が =, +, -, @, TAB, CR で始まる値は、Excel/Sheets等が数式・コマンドとして
 * 評価し得るため、先頭にシングルクオートを付与してテキスト扱いに強制する。
 * (logs.php weblog_handle_csv の既存 $san と同一ロジックを共通化したもの。)
 */
function tet2_csv_sanitize($v): string
{
    $v = (string) $v;
    if ($v !== '' && in_array($v[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
        return "'" . $v;
    }
    return $v;
}

function json_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

// ---- CSRF ----
function tet2_csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function tet2_require_csrf(): void
{
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf'] ?? '');
    if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], (string) $sent)) {
        json_error('CSRF トークンが不正です', 403);
    }
}

// ---- 認証・ロール・テナント ----
function current_user(): ?array
{
    if (empty($_SESSION['uid'])) {
        return null;
    }
    return [
        'id'        => (int) $_SESSION['uid'],
        'tenant_id' => $_SESSION['tenant_id'] !== null ? (int) $_SESSION['tenant_id'] : null,
        'role'      => (string) $_SESSION['role'],
        'email'     => (string) ($_SESSION['email'] ?? ''),
    ];
}

function require_auth(): array
{
    $u = current_user();
    if ($u === null) {
        json_error('認証が必要です', 401);
    }
    return $u;
}

/** superadmin / tenant_admin / operator / viewer の順で強い。 */
function require_role(string $minRole): array
{
    $u = require_auth();
    $rank = ['viewer' => 1, 'operator' => 2, 'tenant_admin' => 3, 'superadmin' => 4];
    $have = $rank[$u['role']] ?? 0;
    $need = $rank[$minRole] ?? 99;
    if ($have < $need) {
        json_error('権限がありません', 403);
    }
    return $u;
}

/**
 * このリクエストが操作対象とすべき tenant_id を返す。
 * superadmin は ?tenant_id= 指定で他テナントを操作できる。それ以外は自テナント固定。
 */
function effective_tenant_id(array $user, ?int $requested = null): int
{
    if ($user['role'] === 'superadmin') {
        if ($requested !== null) {
            return $requested;
        }
        if ($user['tenant_id'] !== null) {
            return $user['tenant_id'];
        }
        json_error('superadmin は tenant_id を指定してください', 400);
    }
    // 非 superadmin は自テナント固定。他テナント要求は拒否（IDOR 防止）。
    if ($requested !== null && $requested !== $user['tenant_id']) {
        json_error('他組織のデータにはアクセスできません', 403);
    }
    return (int) $user['tenant_id'];
}

/**
 * 教材(edu_*)用の実効 tenant_id。共有教材(tenant_id NULL)を扱えるように effective_tenant_id を緩める。
 *
 * superadmin かつ tenant_id 未指定のときは、テナント固有行には決してマッチしない番兵 0 を返す。
 * edu の各クエリは `WHERE ... AND (tenant_id = ? OR tenant_id IS NULL)` なので、番兵 0 では
 * 共有行(tenant_id NULL)だけがヒットし、テナント固有行は 404 になる。
 * = 「superadmin が tenant 未指定 = 共有教材だけを対象にする」という意味になり、
 *    ハイブリッド方針(superadmin は共有を編集可)を tenant_id 明示なしで実現する。
 *
 * superadmin が特定テナントの教材を操作したい場合は従来どおり tenant_id を指定する。
 * 非 superadmin は effective_tenant_id と同じ(自テナント固定)。
 */
function edu_effective_tenant_id(array $user, ?int $requested = null): int
{
    if ($user['role'] === 'superadmin' && $requested === null && $user['tenant_id'] === null) {
        return 0; // 共有スコープ番兵(どのテナントにも属さない → 共有行のみ対象)
    }
    return effective_tenant_id($user, $requested);
}

/** 指定 campaign_id がテナントに属することを確認し、行を返す（IDOR 防止）。 */
function assert_campaign_owned(int $campaignId, int $tenantId): array
{
    // 論理削除済み(deleted_at IS NOT NULL)は操作対象から除外する。
    // 物理パージ(cron)は削除済みを対象にするため、この関数を使わず直接SQLで引く。
    $c = Db::one('SELECT * FROM campaigns WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL', [$campaignId, $tenantId]);
    if ($c === null) {
        json_error('キャンペーンが見つかりません', 404);
    }
    return $c;
}

/** 指定 target_id がテナントに属することを確認する。 */
function assert_target_owned(int $targetId, int $tenantId): array
{
    $t = Db::one('SELECT * FROM targets WHERE id = ? AND tenant_id = ?', [$targetId, $tenantId]);
    if ($t === null) {
        json_error('対象者が見つかりません', 404);
    }
    return $t;
}

function audit(string $action, string $detail = ''): void
{
    $u = current_user();
    Db::run(
        'INSERT INTO audit_log (tenant_id, user_id, action, detail, ip) VALUES (?,?,?,?,?)',
        [
            $u['tenant_id'] ?? null,
            $u['id'] ?? null,
            $action,
            $detail,
            $_SERVER['REMOTE_ADDR'] ?? '',
        ]
    );
}

// 全 API 共通の初期化
tet2_init_session();
tet2_security_headers();
