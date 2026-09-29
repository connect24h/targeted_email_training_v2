<?php
declare(strict_types=1);

/**
 * 管理画面の保護(段階1、G43 と G44)のテスト。合成 DB と .test ドメインだけを使う。
 *
 *   TOTP-*  RFC 6238 の値、時刻のずれの許容(±1窓)、同じ時刻窓とそれより前のコードの再利用の拒否
 *   ENC-*   秘密鍵は暗号文で保存し、ほかの利用者の行へ写しても復号できない
 *   RC-*    回復コード: 10個、ハッシュだけを保存、1回だけ使える、表記の揺れ
 *   LG-*    ログインの2段目: パスワードの後はコード待ち(uid を持たない)、コードの失敗もロックに数える、回復コード
 *   GT-*    本物の bootstrap: コード待ちのセッションは API を使えない、必須の組織で未登録なら登録の API だけ
 *   SELF-*  本人の登録、解除、回復コードの作り直し、間違いが続いた時のログアウト
 *   ADM-*   管理者による解除(自組織だけ、強い役割は不可)、一覧の状態の列
 *   POL-*   方針: PasswordPolicy より弱くできない、弱いパスワードの拒否、全体の方針はシステム管理者だけ
 *   CLI-*   web/db/mfa_reset.php(確認だけ、解除、監査ログ)
 */

ini_set('session.use_cookies', '0');
ini_set('session.use_only_cookies', '0');
ini_set('session.use_trans_sid', '0');
ini_set('session.save_path', sys_get_temp_dir());
session_start();
ob_start();

require_once __DIR__ . '/helpers.php';
$dbPath = tet2_test_boot();
putenv('TET2_EDU_MAIL_DISABLE=1');

// 暗号鍵(テスト専用の乱数)。本番の secrets.ini には触れない
$secretsFile = sys_get_temp_dir() . '/tet2-mfa-secrets-' . getmypid() . '.ini';
file_put_contents($secretsFile, "[mfa]\nsecret_key = " . base64_encode(random_bytes(32)) . "\n");
chmod($secretsFile, 0600);
register_shutdown_function(static fn () => @unlink($secretsFile));
putenv('TET2_SECRETS_FILE=' . $secretsFile);

require_once __DIR__ . '/../lib/PasswordPolicy.php';
require_once __DIR__ . '/../lib/AdminSecurityPolicy.php';
require_once __DIR__ . '/../lib/AdminMfa.php';
require_once __DIR__ . '/../lib/UserPasswordTokens.php';
Secrets::reset();
load_api('auth');
load_api('users');

const PW = 'Str0ng-Heron-Kiwi';
$hash = password_hash(PW, PASSWORD_DEFAULT);
Db::run("INSERT INTO users (id, tenant_id, email, password_hash, name, role, status) VALUES
    (20, 1, 'mfa-admin@example.test', ?, 'MFA Admin', 'tenant_admin', 'active'),
    (21, 1, 'mfa-op@example.test', ?, 'MFA Op', 'operator', 'active'),
    (22, 2, 'mfa-other@example.test', ?, 'Other', 'operator', 'active'),
    (23, NULL, 'mfa-root@example.test', ?, 'Root', 'superadmin', 'active'),
    (24, 1, 'mfa-admin2@example.test', ?, 'MFA Admin2', 'tenant_admin', 'active')", [$hash, $hash, $hash, $hash, $hash]);

function actor(int $id): array
{
    $u = Db::one('SELECT id, tenant_id, role, email FROM users WHERE id = ?', [$id]);
    return ['id' => (int) $u['id'], 'tenant_id' => $u['tenant_id'] !== null ? (int) $u['tenant_id'] : null,
        'role' => (string) $u['role'], 'email' => (string) $u['email']];
}
function asUser(string $fn, int $id, array $body = []): array
{
    $a = actor($id);
    return call_handler($fn, $body, $a['role'], [$a]);
}
function nowCode(string $secret, int $offset = 0): string
{
    return Totp::codeAt($secret, Totp::stepAt(time()) + $offset);
}
/** 登録を済ませ、[秘密鍵, 回復コード] を返す。 */
function enroll(int $id): array
{
    $r = asUser('users_handle_mfa_setup', $id);
    $secret = $r['payload']['secret'];
    $e = asUser('users_handle_mfa_enable', $id, ['code' => nowCode($secret, -1)]);
    if ($e['code'] !== 200) {
        throw new RuntimeException('FAIL: 登録できません ' . json_encode($e));
    }
    return [$secret, $e['payload']['recovery_codes']];
}
function login(string $email, string $password): array
{
    $_SERVER['REMOTE_ADDR'] = '198.51.100.70';
    if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
    return call_handler('auth_handle_login', ['email' => $email, 'password' => $password]);
}
function verify(array $body): array
{
    $_SERVER['REMOTE_ADDR'] = '198.51.100.70';
    return call_handler('auth_handle_mfa_verify', $body);
}
function auditHas(string $action): bool
{
    return in_array($action, array_column($GLOBALS['__TET2_TEST_AUDIT'], 'action'), true);
}

// ============ TOTP ============
$rfc = Totp::base32Encode('12345678901234567890');
check($rfc === 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 'TOTP-0: base32 は RFC 4648 の値');
foreach ([59 => '287082', 1111111109 => '081804', 1111111111 => '050471', 1234567890 => '005924', 2000000000 => '279037'] as $t => $expected) {
    check(Totp::codeAt($rfc, Totp::stepAt($t)) === $expected, "TOTP-1: RFC 6238 の T={$t} の6桁は {$expected}");
}
$t0 = 1_700_000_000;
$s0 = Totp::stepAt($t0);
check(Totp::verify($rfc, Totp::codeAt($rfc, $s0), $t0, null) === $s0, 'TOTP-2: 今の時刻窓のコードは通る');
check(Totp::verify($rfc, Totp::codeAt($rfc, $s0 - 1), $t0, null) === $s0 - 1, 'TOTP-3: 1つ前の時刻窓(30秒の遅れ)は通る');
check(Totp::verify($rfc, Totp::codeAt($rfc, $s0 + 1), $t0, null) === $s0 + 1, 'TOTP-4: 1つ後の時刻窓(30秒の進み)は通る');
check(Totp::verify($rfc, Totp::codeAt($rfc, $s0 - 2), $t0, null) === null, 'TOTP-5: 2つ前の時刻窓は通らない');
check(Totp::verify($rfc, Totp::codeAt($rfc, $s0 + 2), $t0, null) === null, 'TOTP-6: 2つ後の時刻窓は通らない');
check(Totp::verify($rfc, Totp::codeAt($rfc, $s0), $t0, $s0) === null, 'TOTP-7: 最後に使った時刻窓と同じコードは通らない');
check(Totp::verify($rfc, Totp::codeAt($rfc, $s0 - 1), $t0, $s0) === null, 'TOTP-8: 最後に使った時刻窓より前のコードは通らない');
check(Totp::verify($rfc, '12345', $t0, null) === null && Totp::verify($rfc, 'abcdef', $t0, null) === null, 'TOTP-9: 6桁の数字でなければ通らない');
$uri = Totp::uri('JBSWY3DPEHPK3PXP', 'a@example.test', 'TET v2');
check(str_starts_with($uri, 'otpauth://totp/TET%20v2:a%40example.test?secret=JBSWY3DPEHPK3PXP&issuer=TET%20v2'), 'TOTP-10: otpauth の URI');

// ============ 登録と暗号化 ============
$r = asUser('users_handle_mfa_setup', 21);
check($r['code'] === 200 && preg_match('/^[A-Z2-7]{32}$/', $r['payload']['secret']) === 1
    && str_contains($r['payload']['otpauth_uri'], 'mfa-op%40example.test'), 'SELF-1: 登録を始めると秘密鍵と URI を返す');
$pendingSecret = $r['payload']['secret'];
$row = Db::one('SELECT mfa_secret, mfa_enabled_at FROM users WHERE id = 21');
check($row['mfa_enabled_at'] === null && str_starts_with((string) $row['mfa_secret'], 'v1:')
    && !str_contains((string) $row['mfa_secret'], $pendingSecret), 'ENC-1: 秘密鍵は暗号文で保存し、まだ有効にしない');
check(AdminMfa::isEnabled(21) === false, 'SELF-2: コードを確かめるまでは有効にならない');
$r = asUser('users_handle_mfa_enable', 21, ['code' => '000000' === nowCode($pendingSecret) ? '111111' : '000000']);
check($r['code'] === 400 && AdminMfa::isEnabled(21) === false, 'SELF-3: 違うコードでは有効にならない');
$r = asUser('users_handle_mfa_enable', 21, ['code' => nowCode($pendingSecret)]);
check($r['code'] === 200 && count($r['payload']['recovery_codes']) === 10 && AdminMfa::isEnabled(21), 'SELF-4: 正しいコードで有効にし、回復コードを10個返す');
check(auditHas('user.mfa_enable'), 'SELF-4: 監査ログに残す');
$opSecret = $pendingSecret;
$opCodes = $r['payload']['recovery_codes'];
$stored = array_column(Db::all('SELECT code_hash FROM user_mfa_recovery_codes WHERE user_id = 21'), 'code_hash');
check(count($stored) === 10 && !in_array($opCodes[0], $stored, true)
    && in_array(hash('sha256', str_replace('-', '', $opCodes[0])), $stored, true), 'RC-1: 回復コードは sha256 だけを保存する');
check(preg_match('/^[A-Z2-9]{4}(-[A-Z2-9]{4}){3}$/', $opCodes[0]) === 1 && count(array_unique($opCodes)) === 10, 'RC-2: 回復コードは16文字(4文字ずつ区切る)で重ならない');
$r = asUser('users_handle_mfa_setup', 21);
check($r['code'] === 409, 'SELF-5: 有効な間は登録をやり直せない(解除が先)');
// 暗号文をほかの利用者の行へ写しても復号できない(AAD に利用者の id)
Db::run("UPDATE users SET mfa_secret = (SELECT mfa_secret FROM users WHERE id = 21), mfa_enabled_at = '2026-09-01 00:00:00' WHERE id = 22");
$threw = false;
try {
    AdminMfa::verifyCode(22, nowCode($opSecret), time());
} catch (RuntimeException) {
    $threw = true;
}
check($threw, 'ENC-2: ほかの利用者の暗号文は復号できない');
AdminMfa::disable(22);

// ============ ログインの2段目 ============
Db::run('UPDATE users SET mfa_last_step = NULL WHERE id = 21');
$r = login('mfa-op@example.test', PW);
check($r['code'] === 200 && ($r['payload']['mfa_required'] ?? false) === true && !isset($r['payload']['user']), 'LG-1: 多要素認証のユーザはパスワードの後にコードを求められる');
check(!isset($_SESSION['uid']) && ($_SESSION['mfa_pending']['uid'] ?? null) === 21, 'LG-2: コード待ちのセッションは uid を持たない');
$r = verify(['code' => nowCode($opSecret)]);
check($r['code'] === 200 && $r['payload']['user']['id'] === 21 && $r['payload']['user']['mfa_enabled'] === true, 'LG-3: 正しいコードでログインが完了する');
check(($_SESSION['uid'] ?? null) === 21 && !isset($_SESSION['mfa_pending']), 'LG-4: 完了すると uid が入り、コード待ちは消える');
$usedStep = (int) Db::one('SELECT mfa_last_step FROM users WHERE id = 21')['mfa_last_step'];
check(in_array($usedStep, [Totp::stepAt(time()), Totp::stepAt(time()) - 1], true), 'LG-5: 使った時刻窓を記録する');
$_SESSION = [];
login('mfa-op@example.test', PW);
$r = verify(['code' => Totp::codeAt($opSecret, $usedStep)]);
check($r['code'] === 401, 'LG-6: 同じ時刻窓のコードをもう一度使うと通らない');
$r = verify(['code' => nowCode($opSecret, 1)]);
check($r['code'] === 200, 'LG-7: 次の時刻窓(ずれの範囲)のコードなら通る');
$_SESSION = [];

// 回復コード
login('mfa-op@example.test', PW);
$r = verify(['recovery_code' => strtolower($opCodes[0])]);
check($r['code'] === 200 && AdminMfa::remainingRecoveryCodes(21) === 9, 'RC-3: 回復コードで入れる(小文字でも)。残りは9個');
check(auditHas('user.mfa_recovery_used'), 'RC-3: 回復コードの使用を監査ログに残す');
$_SESSION = [];
login('mfa-op@example.test', PW);
$r = verify(['recovery_code' => $opCodes[0]]);
check($r['code'] === 401, 'RC-4: 使った回復コードはもう使えない');
$r = verify(['recovery_code' => str_replace('-', ' ', $opCodes[1])]);
check($r['code'] === 200, 'RC-5: 区切りが空白でも同じコードとして扱う');
$_SESSION = [];

// コードの失敗もロックに数える(パスワードの5回の決まりは変えない)
Db::run('UPDATE users SET failed_count = 0, locked_until = NULL WHERE id = 21');
login('mfa-op@example.test', PW);
for ($i = 0; $i < 4; $i++) {
    $r = verify(['code' => '000000']);
}
check($r['code'] === 401 && (int) Db::one('SELECT failed_count FROM users WHERE id = 21')['failed_count'] === 4, 'LG-8: コードの失敗を失敗の回数に数える(パスワードが合っても0に戻さない)');
$r = verify(['code' => '000000']);
check($r['code'] === 423 && Db::one('SELECT locked_until FROM users WHERE id = 21')['locked_until'] !== null, 'LG-9: 5回目の失敗で15分ロックする');
check(!isset($_SESSION['mfa_pending']), 'LG-10: ロックしたらコード待ちの状態も消す');
$r = login('mfa-op@example.test', PW);
check($r['code'] === 423, 'LG-11: ロック中は正しいパスワードでも入れない(従来どおり)');
Db::run('UPDATE users SET failed_count = 3, locked_until = NULL, mfa_last_step = NULL WHERE id = 21');
login('mfa-op@example.test', PW);
$r = verify(['code' => nowCode($opSecret)]);
check($r['code'] === 200 && (int) Db::one('SELECT failed_count FROM users WHERE id = 21')['failed_count'] === 0, 'LG-12: 2段目まで通ると失敗の回数を0に戻す');
$_SESSION = [];
// 多要素認証のないユーザは従来どおり1段でログインする
$r = login('mfa-admin@example.test', PW);
check($r['code'] === 200 && isset($r['payload']['user']) && ($_SESSION['uid'] ?? null) === 20, 'LG-13: 多要素認証のないユーザは従来どおりパスワードだけで入れる');
$_SESSION = [];
// コード待ちの期限切れ、コード待ちなしの2段目
$r = verify(['code' => '123456']);
check($r['code'] === 401, 'LG-14: パスワードを確かめていないセッションの2段目は 401');
login('mfa-op@example.test', PW);
$_SESSION['mfa_pending']['expires'] = time() - 1;
$r = verify(['code' => nowCode($opSecret, 1)]);
check($r['code'] === 401 && !isset($_SESSION['mfa_pending']), 'LG-15: 5分を過ぎたコード待ちは使えない');
$_SESSION = [];

// ============ 本物の bootstrap でのセッションの判定 ============
function probe(string $dbPath, string $mode, int $uid, ?int $tenant, string $role): string
{
    $cmd = 'TET2_DB_PATH=' . escapeshellarg($dbPath) . ' php ' . escapeshellarg(__DIR__ . '/fixtures/session_probe.php')
        . ' ' . escapeshellarg($mode) . ' ' . $uid . ' ' . escapeshellarg($tenant === null ? '-' : (string) $tenant)
        . ' ' . escapeshellarg($role) . ' 2>&1';
    return (string) shell_exec($cmd);
}
$out = probe($dbPath, 'pending', 21, 1, 'operator');
check(str_contains($out, '認証が必要です') && !str_contains($out, '"probe":"ok"'), 'GT-1: コード待ちのセッションは管理画面の API を使えない');
$out = probe($dbPath, 'auth', 20, 1, 'tenant_admin');
check(str_contains($out, '"probe":"ok"'), 'GT-2: 方針が必須でなければ未登録でも使える');
AdminSecurityPolicy::save(1, 12, 3, true, 20);
$out = probe($dbPath, 'auth', 20, 1, 'tenant_admin');
check(str_contains($out, '多要素認証の登録が必要です') && !str_contains($out, '"probe":"ok"'), 'GT-3: 必須の組織で未登録のユーザは、登録以外の API を使えない');
$out = probe($dbPath, 'enroll', 20, 1, 'tenant_admin');
check(str_contains($out, '"probe":"ok"') && str_contains($out, '"mfa_enrollment_required":true'), 'GT-4: 登録の API の入口は通る(必須であることを返す)');
$out = probe($dbPath, 'me', 20, 1, 'tenant_admin');
check(str_contains($out, '"mfa_enrollment_required":true'), 'GT-5: me は登録が必要なことを返す(画面が登録の画面へ移す)');
$out = probe($dbPath, 'auth', 21, 1, 'operator');
check(str_contains($out, '"probe":"ok"'), 'GT-6: 必須の組織でも登録済みのユーザは使える');
$out = probe($dbPath, 'auth', 22, 2, 'operator');
check(str_contains($out, '"probe":"ok"'), 'GT-7: ほかの組織の方針は効かない');
$out = probe($dbPath, 'auth', 23, null, 'superadmin');
check(str_contains($out, '"probe":"ok"'), 'GT-8: テナントの方針はシステム管理者(テナントなし)に効かない');
AdminSecurityPolicy::save(null, 12, 3, true, 23);
$out = probe($dbPath, 'auth', 23, null, 'superadmin');
check(str_contains($out, '多要素認証の登録が必要です'), 'GT-9: 全体の方針が必須ならシステム管理者も登録が要る');
$out = probe($dbPath, 'auth', 22, 2, 'operator');
check(str_contains($out, '多要素認証の登録が必要です'), 'GT-10: 全体の方針はほかの組織にも効く');
Db::run('DELETE FROM tenant_security_policies');
$r = login('mfa-admin@example.test', PW);
check($r['code'] === 200 && $r['payload']['user']['mfa_enrollment_required'] === false, 'GT-11: 方針を外せば登録は要らない');
$_SESSION = [];

// ============ 本人の解除と回復コードの作り直し ============
[$adminSecret, $adminCodes] = enroll(20);
$r = asUser('users_handle_mfa_disable', 20, []);
check($r['code'] === 400 && AdminMfa::isEnabled(20), 'SELF-6: 解除にはコードかパスワードが要る');
$r = asUser('users_handle_mfa_disable', 20, ['password' => 'wrong-password']);
check($r['code'] === 400 && AdminMfa::isEnabled(20), 'SELF-7: 違うパスワードでは解除できない');
$r = asUser('users_handle_mfa_recovery_regenerate', 20, ['code' => nowCode($adminSecret, 1)]);
check($r['code'] === 200 && count($r['payload']['recovery_codes']) === 10 && !in_array($adminCodes[0], $r['payload']['recovery_codes'], true),
    'SELF-8: 今のコードで回復コードを作り直す');
check(AdminMfa::useRecoveryCode(20, $adminCodes[0]) === false, 'SELF-9: 作り直す前の回復コードは使えない');
$r = asUser('users_handle_mfa_disable', 20, ['password' => PW]);
check($r['code'] === 200 && !AdminMfa::isEnabled(20) && AdminMfa::remainingRecoveryCodes(20) === 0
    && Db::one('SELECT mfa_secret FROM users WHERE id = 20')['mfa_secret'] === null, 'SELF-10: パスワードで解除すると秘密鍵と回復コードを消す');
[$adminSecret] = enroll(20);
$_SESSION['mfa_self_failures'] = 0;
$_SESSION['uid'] = 20;
for ($i = 1; $i <= 5; $i++) {
    $r = asUser('users_handle_mfa_disable', 20, ['code' => '000000']);
}
check($r['code'] === 401 && $_SESSION === [] && AdminMfa::isEnabled(20), 'SELF-11: 本人の確認を5回続けて間違えるとログアウトする');
$r = asUser('users_handle_mfa_disable', 20, ['code' => nowCode($adminSecret, 1)]);
check($r['code'] === 200 && !AdminMfa::isEnabled(20), 'SELF-12: 今のコードでも解除できる');
$r = asUser('users_handle_mfa_status', 20);
check($r['code'] === 200 && $r['payload']['enabled'] === false && $r['payload']['available'] === true, 'SELF-13: 状態を返す');

// ============ 管理者による解除と一覧 ============
[$adminSecret] = enroll(24);
$r = call_handler('users_handle_list', [], 'tenant_admin', [actor(20)]);
$listed = array_column($r['payload']['users'], 'mfa_enabled_at', 'email');
check($listed['mfa-admin2@example.test'] !== null && $listed['mfa-admin@example.test'] === null, 'ADM-1: 一覧に多要素認証の状態(有効にした日時)を出す');
check(!in_array('mfa_secret', array_keys($r['payload']['users'][0]), true), 'ADM-2: 一覧に秘密鍵は出さない');
$r = asUser('users_handle_mfa_reset', 20, ['id' => 22]);
check($r['code'] === 404, 'ADM-3: 組織管理者はほかの組織のユーザを解除できない');
$r = asUser('users_handle_mfa_reset', 20, ['id' => 20]);
check($r['code'] === 400, 'ADM-4: 自分自身はこの操作では解除できない');
Db::run("UPDATE users SET tenant_id = 1 WHERE id = 23");
$r = asUser('users_handle_mfa_reset', 20, ['id' => 23]);
check($r['code'] === 403, 'ADM-5: 組織管理者はシステム管理者の多要素認証を解除できない');
Db::run("UPDATE users SET tenant_id = NULL WHERE id = 23");
$r = asUser('users_handle_mfa_reset', 20, ['id' => 24]);
check($r['code'] === 200 && $r['payload']['changed'] === true && !AdminMfa::isEnabled(24) && auditHas('user.mfa_reset'), 'ADM-6: 組織管理者は自組織のユーザを解除でき、監査ログに残す');
enroll(22);
$r = asUser('users_handle_mfa_reset', 23, ['id' => 22]);
check($r['code'] === 200 && !AdminMfa::isEnabled(22), 'ADM-7: システム管理者はどの組織のユーザも解除できる');

// ============ 方針 ============
$threw = false;
try {
    AdminSecurityPolicy::save(1, 8, 3, false, 20);
} catch (InvalidArgumentException) {
    $threw = true;
}
check($threw, 'POL-1: 12文字より短い最小の文字数は保存できない(PasswordPolicy より弱くできない)');
$r = asUser('users_handle_policy_set', 20, ['min_length' => 11, 'min_classes' => 3, 'require_mfa' => false]);
check($r['code'] === 400, 'POL-2: API でも弱い方針は拒む');
$r = asUser('users_handle_policy_set', 20, ['min_length' => 12, 'min_classes' => 2, 'require_mfa' => false]);
check($r['code'] === 400, 'POL-3: 文字の種類を3より少なくできない');
$r = asUser('users_handle_policy_set', 20, ['scope' => 'global', 'min_length' => 20, 'min_classes' => 4, 'require_mfa' => false]);
check($r['code'] === 403, 'POL-4: 組織管理者は全体の方針を変えられない');
$r = asUser('users_handle_policy_set', 20, ['min_length' => 16, 'min_classes' => 4, 'require_mfa' => false]);
check($r['code'] === 200 && $r['payload']['effective']['min_length'] === 16 && auditHas('security_policy.update'), 'POL-5: 組織管理者は自組織の方針を厳しくできる');
check(AdminSecurityPolicy::violation('Kqzwmvtr123!', 1) !== null, 'POL-6: 方針より短いパスワードは通らない');
check(AdminSecurityPolicy::violation('Kqzwmvtrjyk1234x', 1) !== null, 'POL-7: 方針が4種なら3種のパスワードは通らない');
check(AdminSecurityPolicy::violation('Kqzwmvtrjy12!xyz', 1) === null, 'POL-8: 方針に合うパスワードは通る');
check(AdminSecurityPolicy::violation('abcdefghijklmnop', 2) !== null && AdminSecurityPolicy::violation('Kqzwmvtr123!', 2) === null,
    'POL-9: ほかの組織は従来の決まり(PasswordPolicy)のまま');
$r = call_handler('users_handle_create', ['email' => 'weak-policy@example.test', 'name' => 'W', 'role' => 'viewer', 'password' => 'Kqzwmvtr123!'], 'tenant_admin', [actor(20)]);
check($r['code'] === 400 && str_contains($r['payload']['error'], '16文字以上'), 'POL-10: ユーザの作成で方針に合わないパスワードを拒む');
$r = call_handler('users_handle_update', ['id' => 21, 'password' => 'Kqzwmvtr123!'], 'tenant_admin', [actor(20)]);
check($r['code'] === 400, 'POL-11: パスワードの変更でも方針に合わないものを拒む');
$r = call_handler('users_handle_create', ['email' => 'strong-policy@example.test', 'name' => 'S', 'role' => 'viewer', 'password' => 'Kqzwmvtrjy12!xyz'], 'tenant_admin', [actor(20)]);
check($r['code'] === 201, 'POL-12: 方針に合うパスワードで作成できる');
check(UserPasswordTokens::policyViolation(['role' => 'operator', 'tenant_id' => 1], 'Kqzwmvtr123!') !== null
    && UserPasswordTokens::policyViolation(['role' => 'learner', 'tenant_id' => 1], 'Kqzwmvtr123!') === null,
    'POL-13: パスワード設定のリンクも方針を使う(受講者のマイページは従来の決まり)');
$r = call_handler('users_handle_list', [], 'tenant_admin', [actor(20)]);
check(str_contains($r['payload']['password_policy'], '16文字以上') && $r['payload']['security_policy']['min_classes'] === 4, 'POL-14: 一覧は方針の説明を返す');
$r = asUser('users_handle_policy_set', 23, ['scope' => 'global', 'min_length' => 14, 'min_classes' => 3, 'require_mfa' => false]);
check($r['code'] === 200 && AdminSecurityPolicy::effective(2)['min_length'] === 14 && AdminSecurityPolicy::effective(1)['min_length'] === 16,
    'POL-15: 全体の方針はシステム管理者が変え、厳しい方が効く');
$r = call_handler('users_handle_policy_get', [], 'tenant_admin', [actor(20)]);
check($r['code'] === 200 && $r['payload']['tenant']['min_length'] === 16 && $r['payload']['global']['min_length'] === 14
    && $r['payload']['can_edit_global'] === false, 'POL-16: 取得は自組織と全体の方針を返す');
// 暗号鍵がなければ必須にできない(誰も登録できず全員が止まるため)
putenv('TET2_SECRETS_FILE=' . sys_get_temp_dir() . '/tet2-no-such-secrets.ini');
Secrets::reset();
$r = asUser('users_handle_policy_set', 20, ['min_length' => 16, 'min_classes' => 4, 'require_mfa' => true]);
check($r['code'] === 409, 'POL-17: 暗号鍵が未設定なら多要素認証を必須にできない');
$r = asUser('users_handle_mfa_setup', 21);
check($r['code'] === 503, 'POL-18: 暗号鍵が未設定なら登録を始められない');
putenv('TET2_SECRETS_FILE=' . $secretsFile);
Secrets::reset();
Db::run('DELETE FROM tenant_security_policies');

// ============ CLI(回復の手順) ============
[$cliSecret] = enroll(20);
$cli = static fn (string $args): array => [
    (string) shell_exec('TET2_DB_PATH=' . escapeshellarg($dbPath) . ' php ' . escapeshellarg(__DIR__ . '/../db/mfa_reset.php') . ' ' . $args . ' 2>&1'),
];
[$out] = $cli('--email=mfa-admin@example.test');
check(str_contains($out, 'DRY-RUN') && AdminMfa::isEnabled(20), 'CLI-1: --apply なしは確認だけで変えない');
Db::run("UPDATE users SET failed_count = 5, locked_until = datetime('now','localtime','+15 minutes') WHERE id = 20");
[$out] = $cli('--email=MFA-ADMIN@example.test --apply --unlock');
$after = Db::one('SELECT mfa_secret, mfa_enabled_at, locked_until FROM users WHERE id = 20');
check(str_contains($out, '解除しました') && $after['mfa_enabled_at'] === null && $after['mfa_secret'] === null
    && $after['locked_until'] === null && AdminMfa::remainingRecoveryCodes(20) === 0, 'CLI-2: --apply で解除し、--unlock でロックも外す');
check(Db::one("SELECT COUNT(*) AS c FROM audit_log WHERE action = 'user.mfa_reset_cli' AND detail LIKE 'user_id=20,%'")['c'] === 1,
    'CLI-3: 監査ログに残す');
[$out] = $cli('--email=nobody@example.test --apply');
check(str_contains($out, '見つかりません'), 'CLI-4: いないユーザは理由を出す');

echo "ALL TESTS PASSED\n";
