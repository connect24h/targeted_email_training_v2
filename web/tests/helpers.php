<?php
declare(strict_types=1);

/**
 * TET v2 API テスト用の共通ヘルパー。
 * - schemaから合成DBを生成し、Db を TET2_DB_PATH でそこへ向ける。
 * - bootstrap.php の session/CSRF/role 依存をスタブ化し、API ハンドラ関数だけを
 *   ロードして直接呼べるようにする(HTTP/セッション不要でユニットテスト可能)。
 *
 * 使い方: require helpers.php → tet2_test_boot() → load_api('targets') → ハンドラ呼び出し。
 */

require_once __DIR__ . '/fixtures/TestDatabase.php';

$GLOBALS['__TET2_TEST_BODY'] = [];
$GLOBALS['__TET2_TEST_ROLE'] = 'operator';
$GLOBALS['__TET2_TEST_LAST'] = null; // 直近の json_out/json_error 結果
$GLOBALS['__TET2_TEST_CSRF_CALLS'] = 0;
$GLOBALS['__TET2_TEST_AUDIT'] = [];

function pass(string $message): void { echo "PASS: {$message}\n"; }

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException("FAIL: {$message}");
    }
    pass($message);
}

/** 合成DBを一時生成し、Db をそこへ向ける。$seedSql があれば実行して調整。 */
function tet2_test_boot(?string $seedSql = null): string
{
    $tmp = sys_get_temp_dir() . '/tet2-test-' . getmypid() . '-' . substr(md5((string) mt_rand()), 0, 8) . '.sqlite';
    TestDatabase::create($tmp);
    // WAL の付随ファイル(-wal、-shm)も消す。本体だけを消すと /tmp(tmpfs)に残りが溜まり、満杯になった(2026-09-28)
    register_shutdown_function(static function () use ($tmp): void {
        foreach (["", "-wal", "-shm", "-journal"] as $suffix) {
            @unlink($tmp . $suffix);
        }
    });
    putenv("TET2_DB_PATH={$tmp}");
    require_once __DIR__ . '/../lib/Db.php';
    require_once __DIR__ . '/../lib/TenantStatus.php';
    // load_api は require の行を外すので、受講の API(edu_take)が使う受講の回の処理もここで読む
    require_once __DIR__ . '/../lib/EduAttempts.php';
    if ($seedSql !== null) {
        Db::run($seedSql);
    }
    return $tmp;
}

// ---- bootstrap.php 相当のスタブ(テスト内で先に定義することで本物より優先) ----
// 役職カテゴリ定数と正規化(bootstrap.php と同一定義。load_api は bootstrap を読まないため)。
const TET2_POSITION_CATEGORIES = ['役員', '管理職', '一般従業員'];
const TET2_RETRAIN_CATEGORY_MAP = [
    'auth'             => ['phishing', 'password-management'],
    'click'            => ['phishing', 'social-engineering'],
    'click_attachment' => ['malware'],
];
const TET2_POSITION_CATEGORY_ALIASES = [
    '社員'     => '一般従業員',
    '一般社員' => '一般従業員',
];
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

function json_out($data, int $code = 200): never
{
    $GLOBALS['__TET2_TEST_LAST'] = ['code' => $code, 'data' => $data];
    throw new Tet2TestExit($code, $data);
}
function json_error(string $message, int $code = 400): never
{
    $GLOBALS['__TET2_TEST_LAST'] = ['code' => $code, 'error' => $message];
    throw new Tet2TestExit($code, ['success' => false, 'error' => $message]);
}
function json_body(): array { return $GLOBALS['__TET2_TEST_BODY']; }
function tet2_require_csrf(): void { $GLOBALS['__TET2_TEST_CSRF_CALLS']++; }
function tet2_init_session(): void {}
function tet2_security_headers(): void {}
function tet2_csrf_token(): string { return 'test-csrf'; }
function audit(string $action, string $detail = ''): void
{
    $GLOBALS['__TET2_TEST_AUDIT'][] = ['action' => $action, 'detail' => $detail];
}

function current_user(): ?array
{
    $t = Db::one('SELECT tenant_id FROM users WHERE tenant_id IS NOT NULL LIMIT 1');
    return ['id' => 1, 'tenant_id' => (int) ($t['tenant_id'] ?? 1), 'role' => $GLOBALS['__TET2_TEST_ROLE'], 'email' => 'test@t'];
}
function require_auth(): array { return current_user(); }
function require_role(string $minRole): array
{
    $rank = ['viewer' => 1, 'operator' => 2, 'tenant_admin' => 3, 'superadmin' => 4];
    $have = $rank[$GLOBALS['__TET2_TEST_ROLE']] ?? 0;
    $need = $rank[$minRole] ?? 99;
    if ($have < $need) {
        json_error('権限がありません', 403);
    }
    return current_user();
}
function effective_tenant_id(array $user, ?int $requested = null): int
{
    if ($user['role'] === 'superadmin' && $requested !== null) { return $requested; }
    if ($requested !== null && $requested !== $user['tenant_id']) { json_error('他組織のデータにはアクセスできません', 403); }
    return (int) $user['tenant_id'];
}
function edu_effective_tenant_id(array $user, ?int $requested = null): int { return effective_tenant_id($user, $requested); }
function assert_campaign_owned(int $campaignId, int $tenantId): array
{
    // 本番(bootstrap.php)と同じく論理削除済みを404にする。
    $c = Db::one('SELECT * FROM campaigns WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL', [$campaignId, $tenantId]);
    if ($c === null) { json_error('キャンペーンが見つかりません', 404); }
    return $c;
}
function assert_target_owned(int $targetId, int $tenantId): array
{
    $t = Db::one('SELECT * FROM targets WHERE id = ? AND tenant_id = ?', [$targetId, $tenantId]);
    if ($t === null) { json_error('対象者が見つかりません', 404); }
    return $t;
}

/** テスト内で json_out/json_error を throw に変えて捕捉するための例外。 */
class Tet2TestExit extends RuntimeException
{
    public int $httpCode;
    public array $payload;
    public function __construct(int $code, array $payload)
    {
        parent::__construct('tet2 test exit ' . $code);
        $this->httpCode = $code;
        $this->payload = $payload;
    }
}

/**
 * api/<name>.php から関数定義部分だけをロードする(冒頭 require と末尾 try 実行ブロックを除去)。
 * これで HTTP ディスパッチを起動せず、個別ハンドラ関数だけ呼べる。
 */
function load_api(string $name): void
{
    $src = file_get_contents(__DIR__ . "/../api/{$name}.php");
    if ($src === false) { throw new RuntimeException("api/{$name}.php 読込失敗"); }
    $src = preg_replace('/^<\?php.*?\n/', '', $src, 1);
    $src = preg_replace('/^\s*require(_once)?[^\n]*\n/m', '', $src);
    $pos = strpos($src, "\ntry {");
    if ($pos !== false) { $src = substr($src, 0, $pos); }
    eval($src);
}

/**
 * ハンドラを実行し、json_out/json_error の結果を配列で返す(throw を捕捉)。
 * $body は json_body() が返す内容。role でロールを切り替える。
 *
 * $args を明示指定すればそれをハンドラ引数に使う(例: logs 系は int $tenantId を取る)。
 * 未指定かつハンドラが引数を1つ取る場合は current_user() を自動で渡す($actor 型ハンドラ)。
 */
function call_handler(string $fn, array $body = [], string $role = 'operator', ?array $args = null): array
{
    $GLOBALS['__TET2_TEST_BODY'] = $body;
    $GLOBALS['__TET2_TEST_ROLE'] = $role;
    $GLOBALS['__TET2_TEST_LAST'] = null;
    $GLOBALS['__TET2_TEST_CSRF_CALLS'] = 0;
    $GLOBALS['__TET2_TEST_AUDIT'] = [];
    if ($args === null) {
        $ref = new ReflectionFunction($fn);
        $args = $ref->getNumberOfParameters() >= 1 ? [current_user()] : [];
    }
    try {
        $fn(...$args);
    } catch (Tet2TestExit $e) {
        return ['code' => $e->httpCode, 'payload' => $e->payload];
    }
    return ['code' => 0, 'payload' => null];
}
