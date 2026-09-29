<?php
declare(strict_types=1);

/**
 * ユーザ管理の改善(A1〜A4)のテスト。合成 DB と .test ドメインだけを使い、メールは実際には送らない
 * (EduMailer::useTransport で送信口を差し替え、念のため TET2_EDU_MAIL_DISABLE=1 も立てる)。
 *
 *   PW-*  パスワードの決まり(12文字以上、4種のうち3種以上)
 *   IN-*  招待: 作成と同時にメール、トークンはハッシュだけを保存、一覧の「パスワード未設定」と最終ログイン
 *   TK-*  トークン: 1回だけ、期限切れ、別のユーザには効かない、再送で古いものは無効、セッションの世代
 *   TS-*  停止中のユーザ、停止中・削除済みのテナント、ほかのテナントのユーザ
 *   ML-*  メールを送れなかった時
 *   CSV-* 一括登録と出力
 *   RL-*  パスワード設定の API の回数の制限
 */

require_once __DIR__ . '/helpers.php';
tet2_test_boot();
putenv('TET2_EDU_MAIL_DISABLE=1');
putenv('TET2_MAIL_OUTBOX_DIR');
putenv('TET2_ADMIN_BASE_URL=https://admin.example.test/tet2');
require_once __DIR__ . '/../lib/PasswordPolicy.php';
require_once __DIR__ . '/../lib/UserPasswordTokens.php';
if (!function_exists('tet2_csv_sanitize')) {
    // bootstrap.php と同じ定義(load_api は bootstrap を読まない)。
    function tet2_csv_sanitize($v): string
    {
        $v = (string) $v;
        return ($v !== '' && in_array($v[0], ['=', '+', '-', '@', "\t", "\r"], true)) ? "'" . $v : $v;
    }
}
load_api('users');
load_api('auth');
load_api('password_set');

// ---- メールの差し替え: 送ったはずのメールを配列に貯める ----
$GLOBALS['__MAILS'] = [];
$GLOBALS['__MAIL_OK'] = true;
EduMailer::useTransport(static function (string $to, string $subject, string $body): bool {
    $GLOBALS['__MAILS'][] = ['to' => $to, 'subject' => $subject, 'body' => $body];
    return $GLOBALS['__MAIL_OK'];
});

/** 直近のメールの本文から、パスワード設定の URL のトークンを取り出す。 */
function lastToken(): string
{
    $mail = end($GLOBALS['__MAILS']);
    if ($mail === false || preg_match('#https://admin\.example\.test/tet2/set_password\.php\?token=([0-9a-f]{64})#', $mail['body'], $m) !== 1) {
        throw new RuntimeException('FAIL: メールに URL がありません');
    }
    return $m[1];
}
function userRow(string $email): array
{
    return Db::one('SELECT * FROM users WHERE email = ?', [$email]);
}
function auditActions(): array
{
    return array_column($GLOBALS['__TET2_TEST_AUDIT'], 'action');
}
function login(string $email, string $password): int
{
    $_SERVER['REMOTE_ADDR'] = '198.51.100.20';
    return call_handler('auth_handle_login', ['email' => $email, 'password' => $password])['code'];
}

const GOOD_PW = 'Str0ng-Heron-Kiwi';

// ============ PW: パスワードの決まり ============
check(PasswordPolicy::violation('Abcdefgh12!') !== null, 'PW-1: 11文字は通らない');
check(PasswordPolicy::violation('abcdefghijkl') !== null, 'PW-2: 12文字でも英小文字だけ(1種)は通らない');
check(PasswordPolicy::violation('abcdefgh1234') !== null, 'PW-3: 英小文字と数字(2種)は通らない');
check(PasswordPolicy::violation('Abcdefgh1234') === null, 'PW-4: 英大文字・英小文字・数字(3種)の12文字は通る');
check(PasswordPolicy::violation('abcdefgh123!') === null, 'PW-5: 英小文字・数字・記号(3種)も通る');
check(PasswordPolicy::violation(str_repeat('Aa1!', 19)) !== null, 'PW-6: 72バイトを超えると通らない(bcrypt が後ろを捨てるため)');
check(PasswordPolicy::violation("Abcdefgh123\n") !== null, 'PW-7: 制御文字は通らない');
$r = call_handler('users_handle_create', ['email' => 'weak@example.test', 'name' => 'Weak', 'role' => 'viewer', 'password' => 'abcdefgh1234'], 'tenant_admin');
check($r['code'] === 400 && str_contains($r['payload']['error'], '3種類以上'), 'PW-8: ユーザの作成にも同じ決まりを使う');
$r = call_handler('users_handle_update', ['id' => 1, 'password' => 'short'], 'tenant_admin');
check($r['code'] === 400, 'PW-9: パスワードの変更にも同じ決まりを使う');

// ============ IN: 招待 ============
$r = call_handler('users_handle_create', ['email' => 'invitee@example.test', 'name' => '招待 花子', 'role' => 'operator', 'send_invite' => true], 'tenant_admin');
check($r['code'] === 201 && $r['payload']['invite']['sent'] === true && $r['payload']['invite']['purpose'] === 'invite', 'IN-1: パスワードなしで作り、招待メールを送る');
check(in_array('user.create', auditActions(), true) && in_array('user.invite', auditActions(), true), 'IN-2: 監査ログに user.create と user.invite');
check(count($GLOBALS['__MAILS']) === 1 && $GLOBALS['__MAILS'][0]['to'] === 'invitee@example.test', 'IN-3: 招待メールはその人にだけ1通(差し替えた送信口で、実際には送らない)');
$invitee = userRow('invitee@example.test');
check((int) $invitee['password_pending'] === 1, 'IN-4: パスワード未設定として作る');
check(!password_verify('', (string) $invitee['password_hash']) && strlen((string) $invitee['password_hash']) > 20, 'IN-5: password_hash は誰も知らない乱数のハッシュ');
$token = lastToken();
check(strlen($token) === 64, 'IN-6: トークンは32バイト(64桁の16進)');
$stored = Db::all('SELECT token_hash, purpose, expires_at FROM user_password_tokens WHERE user_id = ?', [(int) $invitee['id']]);
check(count($stored) === 1 && $stored[0]['token_hash'] === hash('sha256', $token), 'IN-7: DB には sha256 のハッシュだけを保存する');
check(Db::one("SELECT COUNT(*) AS c FROM user_password_tokens WHERE token_hash = ? OR token_hash LIKE ?", [$token, '%' . substr($token, 0, 16) . '%'])['c'] === 0,
    'IN-8: 平文のトークンは DB のどこにもない');
$hours = (strtotime((string) $stored[0]['expires_at']) - strtotime((string) Db::one("SELECT datetime('now','localtime') AS n")['n'])) / 3600;
check($hours > 71.9 && $hours <= 72.0, 'IN-9: 有効期限は72時間');
check(!str_contains(json_encode($GLOBALS['__TET2_TEST_AUDIT'], JSON_UNESCAPED_UNICODE), $token), 'IN-10: 監査ログにトークンを書かない');

// A2: 一覧の最終ログインと未設定
Db::run("UPDATE users SET last_login_at = '2026-09-20 08:30:00' WHERE id = 1");
$r = call_handler('users_handle_list', [], 'tenant_admin');
$byEmail = array_column($r['payload']['users'], null, 'email');
check((int) $byEmail['invitee@example.test']['password_pending'] === 1 && $byEmail['invitee@example.test']['password_link_expires_at'] !== null,
    'IN-11: 一覧に「パスワード未設定」とリンクの期限を出す');
check($byEmail['operator@example.test']['last_login_at'] === '2026-09-20 08:30:00' && (int) $byEmail['operator@example.test']['password_pending'] === 0,
    'IN-12: 一覧に最終ログイン日時を出す');
check(!array_key_exists('password_hash', $byEmail['invitee@example.test']), 'IN-13: 一覧に password_hash を出さない');
check(isset($r['payload']['password_policy']), 'IN-14: 一覧はパスワードの決まりの説明も返す');

// 初期パスワードを入れる作り方も残す
$r = call_handler('users_handle_create', ['email' => 'direct@example.test', 'name' => '直接', 'role' => 'viewer', 'password' => GOOD_PW], 'tenant_admin');
check($r['code'] === 201 && !isset($r['payload']['invite']) && (int) userRow('direct@example.test')['password_pending'] === 0, 'IN-15: 初期パスワードを入れる作り方も残す(メールは送らない)');
check(count($GLOBALS['__MAILS']) === 1, 'IN-16: 初期パスワードで作った時はメールを送らない');
$r = call_handler('users_handle_create', ['email' => 'nopw@example.test', 'name' => 'x', 'role' => 'viewer'], 'tenant_admin');
check($r['code'] === 400, 'IN-17: 招待でもパスワードでもなければ 400');

// ============ TK: トークン ============
$_SERVER['REMOTE_ADDR'] = '198.51.100.10';
[$code, $data] = pwset_handle('check', ['token' => $token], '198.51.100.10');
check($code === 200 && $data['purpose'] === 'invite' && $data['email'] === 'in*****@example.test', 'TK-1: 使えるリンクは check が通り、メールは一部を伏せる');
[$code, $data] = pwset_handle('set', ['token' => $token, 'password' => 'weakpassword'], '198.51.100.10');
check($code === 400 && $data['reason'] === 'policy', 'TK-2: 決まりに合わないパスワードは 400');
check(Db::one('SELECT used_at FROM user_password_tokens WHERE token_hash = ?', [hash('sha256', $token)])['used_at'] === null, 'TK-3: 決まりに合わない時はトークンを使わない');

$otherBefore = userRow('direct@example.test')['password_hash'];
[$code, $data] = pwset_handle('set', ['token' => $token, 'password' => GOOD_PW], '198.51.100.10');
check($code === 200 && $data['success'] === true, 'TK-4: 招待のリンクでパスワードを設定できる');
$invitee = userRow('invitee@example.test');
check((int) $invitee['password_pending'] === 0 && password_verify(GOOD_PW, (string) $invitee['password_hash']), 'TK-5: 設定したパスワードがハッシュで入り、未設定が外れる');
check(userRow('direct@example.test')['password_hash'] === $otherBefore, 'TK-6: ほかのユーザのパスワードは変わらない(トークンはそのユーザにだけ効く)');
check((int) $invitee['session_epoch'] === 1, 'TK-7: session_epoch が増え、それより前のセッションは切れる');
check((int) Db::one("SELECT COUNT(*) AS c FROM audit_log WHERE action = 'user.password_set' AND user_id = ?", [(int) $invitee['id']])['c'] === 1,
    'TK-8: 監査ログに user.password_set');
check(Db::one("SELECT COUNT(*) AS c FROM audit_log WHERE detail LIKE ?", ['%' . $token . '%'])['c'] === 0, 'TK-9: 監査ログにトークンを書かない');
check(login('invitee@example.test', GOOD_PW) === 200, 'TK-10: 設定したパスワードでログインできる');

[$code, $data] = pwset_handle('set', ['token' => $token, 'password' => 'An0ther-Heron-Kiwi'], '198.51.100.10');
check($code === 410 && $data['reason'] === 'used' && str_contains($data['error'], '既に使われています'), 'TK-11: 使ったリンクは2回目は使えず、理由を日本語で出す');
check(login('invitee@example.test', GOOD_PW) === 200, 'TK-12: 2回目の試行でパスワードは変わらない');

// 再設定: 古いリンクは無効になる
$r = call_handler('users_handle_send_password_mail', ['id' => (int) $invitee['id']], 'tenant_admin');
check($r['code'] === 200 && $r['payload']['purpose'] === 'reset', 'TK-13: 設定済みのユーザには「再設定」のメール');
check(auditActions() === ['user.password_reset_request'], 'TK-14: 監査ログに user.password_reset_request');
check(str_contains(end($GLOBALS['__MAILS'])['subject'], '再設定'), 'TK-15: 再設定の件名');
$first = lastToken();
call_handler('users_handle_send_password_mail', ['id' => (int) $invitee['id']], 'tenant_admin');
$second = lastToken();
check($first !== $second, 'TK-16: 送るたびに新しいトークン');
[$code, $data] = pwset_handle('check', ['token' => $first], '198.51.100.11');
check($code === 410 && $data['reason'] === 'revoked', 'TK-17: 同じユーザの古いリンクは無効(理由つき)');
[$code] = pwset_handle('check', ['token' => $second], '198.51.100.11');
check($code === 200, 'TK-18: 最新のリンクは使える');

// 期限切れ
Db::run("UPDATE user_password_tokens SET expires_at = datetime('now','localtime','-1 minutes') WHERE token_hash = ?", [hash('sha256', $second)]);
[$code, $data] = pwset_handle('set', ['token' => $second, 'password' => 'An0ther-Heron-Kiwi'], '198.51.100.11');
check($code === 410 && $data['reason'] === 'expired' && str_contains($data['error'], '有効期限'), 'TK-19: 期限切れのリンクは使えず、理由を出す');
[$code, $data] = pwset_handle('check', ['token' => str_repeat('a', 64)], '198.51.100.11');
check($code === 404 && $data['reason'] === 'invalid', 'TK-20: 存在しないトークンは「正しくありません」');
[$code] = pwset_handle('check', ['token' => 'not-a-token'], '198.51.100.11');
check($code === 404, 'TK-21: 形の違うトークンも「正しくありません」');

// 別のユーザのトークンでは、そのユーザ(自分以外)のパスワードは変えられない
call_handler('users_handle_send_password_mail', ['id' => (int) userRow('direct@example.test')['id']], 'tenant_admin');
$directToken = lastToken();
$inviteeHash = userRow('invitee@example.test')['password_hash'];
pwset_handle('set', ['token' => $directToken, 'password' => 'D1rect-Heron-Kiwi'], '198.51.100.12');
check(userRow('invitee@example.test')['password_hash'] === $inviteeHash && password_verify('D1rect-Heron-Kiwi', userRow('direct@example.test')['password_hash']),
    'TK-22: トークンは発行したユーザのパスワードだけを変える');

// 管理者によるパスワードの変更も、リンクを無効にしてセッションの世代を上げる
call_handler('users_handle_send_password_mail', ['id' => (int) $invitee['id']], 'tenant_admin');
$pending = lastToken();
$epoch = (int) userRow('invitee@example.test')['session_epoch'];
$r = call_handler('users_handle_update', ['id' => (int) $invitee['id'], 'password' => 'Kiw1-Changed-Pw'], 'tenant_admin');
check($r['code'] === 200 && (int) userRow('invitee@example.test')['session_epoch'] === $epoch + 1, 'TK-23: 管理者がパスワードを変えるとセッションの世代が上がる');
[$code, $data] = pwset_handle('check', ['token' => $pending], '198.51.100.12');
check($code === 410 && $data['reason'] === 'revoked', 'TK-24: 管理者がパスワードを変えると、まだ使えたリンクは無効');

// ============ TS: 停止中のユーザ、テナント、ほかのテナント ============
$r = call_handler('users_handle_send_password_mail', ['id' => 2], 'tenant_admin');
check($r['code'] === 404, 'TS-1: ほかのテナントのユーザには送れない(404)');
$mailsBefore = count($GLOBALS['__MAILS']);
Db::run("UPDATE users SET status = 'suspended' WHERE email = 'direct@example.test'");
$r = call_handler('users_handle_send_password_mail', ['id' => (int) userRow('direct@example.test')['id']], 'tenant_admin');
check($r['code'] === 409 && count($GLOBALS['__MAILS']) === $mailsBefore, 'TS-2: 停止中のユーザには送らない');
Db::run("UPDATE users SET status = 'active' WHERE email = 'direct@example.test'");
call_handler('users_handle_send_password_mail', ['id' => (int) userRow('direct@example.test')['id']], 'tenant_admin');
$suspendToken = lastToken();
$mailsBefore = count($GLOBALS['__MAILS']);
Db::run("UPDATE tenants SET status = 'suspended' WHERE id = 1");
$r = call_handler('users_handle_send_password_mail', ['id' => (int) userRow('direct@example.test')['id']], 'tenant_admin');
check($r['code'] === 409 && count($GLOBALS['__MAILS']) === $mailsBefore, 'TS-3: 停止中のテナントのユーザには送らない');
[$code, $data] = pwset_handle('set', ['token' => $suspendToken, 'password' => 'Susp3nded-Heron-Kiwi'], '198.51.100.13');
check($code === 403 && $data['reason'] === 'blocked', 'TS-4: 停止中のテナントのユーザはパスワードを設定できない');
$r = call_handler('users_handle_create', ['email' => 'blocked@example.test', 'name' => 'x', 'role' => 'viewer', 'send_invite' => true], 'tenant_admin');
check($r['code'] === 409 && Db::one("SELECT 1 FROM users WHERE email = 'blocked@example.test'") === null, 'TS-5: 停止中のテナントでは招待つきの作成を断る');
$r = call_handler('users_handle_import_csv', ['csv' => "email,name,role\ncsv-blocked@example.test,x,viewer", 'send_invite' => true], 'tenant_admin');
check($r['code'] === 409, 'TS-6: 停止中のテナントでは招待つきの一括登録を断る');
Db::run("UPDATE tenants SET status = 'deleted', deleted_at = datetime('now','localtime') WHERE id = 1");
[$code] = pwset_handle('check', ['token' => $suspendToken], '198.51.100.13');
check($code === 403, 'TS-7: 削除済みのテナントのユーザもパスワードを設定できない');
Db::run("UPDATE tenants SET status = 'active', deleted_at = NULL WHERE id = 1");

// ============ ML: メールを送れなかった時 ============
$GLOBALS['__MAIL_OK'] = false;
$r = call_handler('users_handle_create', ['email' => 'mailfail@example.test', 'name' => '失敗', 'role' => 'viewer', 'send_invite' => true], 'tenant_admin');
check($r['code'] === 201 && $r['payload']['invite']['sent'] === false && str_contains($r['payload']['invite']['error'], 'メールを送れませんでした'),
    'ML-1: 招待メールを送れなかった時は、ユーザは作り、理由を返す');
$failId = (int) userRow('mailfail@example.test')['id'];
check((int) Db::one('SELECT COUNT(*) AS c FROM user_password_tokens WHERE user_id = ? AND used_at IS NULL AND revoked_at IS NULL', [$failId])['c'] === 0,
    'ML-2: 送れなかったリンクは無効にしておく');
$r = call_handler('users_handle_send_password_mail', ['id' => $failId], 'tenant_admin');
check($r['code'] === 502 && str_contains($r['payload']['error'], 'メールを送れませんでした'), 'ML-3: 再送で送れなければ 502 と理由');
$GLOBALS['__MAIL_OK'] = true;
$r = call_handler('users_handle_send_password_mail', ['id' => $failId], 'tenant_admin');
check($r['code'] === 200 && $r['payload']['purpose'] === 'invite', 'ML-4: 未設定のユーザへの再送は「招待」になる');

// 実際の送信口(SMTP)を通らないこと: 差し替えを外しても TET2_EDU_MAIL_DISABLE=1 で投函しない
EduMailer::useTransport(null);
check(EduMailer::send('nobody@example.test', 's', 'b') === true, 'ML-5: テストでは TET2_EDU_MAIL_DISABLE=1 で SMTP へ投函しない');
EduMailer::useTransport(static function (string $to, string $subject, string $body): bool {
    $GLOBALS['__MAILS'][] = ['to' => $to, 'subject' => $subject, 'body' => $body];
    return $GLOBALS['__MAIL_OK'];
});

// ============ CSV: 一括登録と出力 ============
$mailsBefore = count($GLOBALS['__MAILS']);
$csv = "email,name,role\n"
    . "csv1@example.test,一括 一郎,viewer\n"
    . "csv2@example.test,一括 二郎,operator\n"
    . "csv3@example.test,一括 三郎,tenant_admin\n"
    . "csv4@example.test,一括 四郎,superadmin\n"
    . "csv5@example.test,一括 五郎,owner\n"
    . "not-an-email,不正,viewer\n"
    . "csv1@example.test,重複,viewer\n"
    . "operator@example.test,既存,viewer\n"
    . "csv6@example.test,,viewer\n";
$r = call_handler('users_handle_import_csv', ['csv' => $csv, 'send_invite' => false], 'tenant_admin');
check($r['code'] === 200 && $r['payload']['created'] === 3 && $r['payload']['skipped'] === 6, 'CSV-1: 正しい3行を作り、だめな6行を飛ばす');
$reasons = array_column($r['payload']['errors'], 'reason', 'line');
check(str_contains($reasons[5], 'CSV では作れません'), 'CSV-2: システム管理者の役割は付けられない(行5)');
check(str_contains($reasons[6], '役割が正しくありません'), 'CSV-3: 知らない役割は行ごとのエラー(行6)');
check(str_contains($reasons[7], 'メールアドレスの形'), 'CSV-4: 不正なメールは行ごとのエラー(行7)');
check(str_contains($reasons[8], '2 行目にもあります'), 'CSV-5: CSV の中の重複は行ごとのエラー(行8)');
check(str_contains($reasons[9], '既に使われています'), 'CSV-6: 既にいるユーザは行ごとのエラー(行9)');
check(str_contains($reasons[10], '氏名がありません'), 'CSV-7: 氏名がない行はエラー(行10)');
check(count($GLOBALS['__MAILS']) === $mailsBefore && $r['payload']['invited'] === 0, 'CSV-8: 招待を送らない選択ではメールを送らない');
$csv1 = userRow('csv1@example.test');
check((int) $csv1['password_pending'] === 1 && (int) $csv1['tenant_id'] === 1 && $csv1['role'] === 'viewer', 'CSV-9: パスワード未設定で、操作する人のテナントに作る');
check(Db::one("SELECT 1 FROM users WHERE email IN ('csv4@example.test','csv5@example.test')") === null, 'CSV-10: 範囲外の役割の行は作らない');
check(in_array('user.import_csv', auditActions(), true), 'CSV-11: 監査ログに user.import_csv');
$r = call_handler('users_handle_send_password_mail', ['id' => (int) $csv1['id']], 'tenant_admin');
check($r['code'] === 200 && $r['payload']['purpose'] === 'invite', 'CSV-12: 後から一覧で招待を送れる');

$r = call_handler('users_handle_import_csv', ['csv' => "\xEF\xBB\xBFメールアドレス,氏名,ロール\ncsv7@example.test,一括 七郎,閲覧者\ncsv8@example.test,一括 八郎,operator\n", 'send_invite' => true], 'tenant_admin');
check($r['code'] === 200 && $r['payload']['created'] === 2 && $r['payload']['invited'] === 2, 'CSV-13: 日本語の見出しと役割名、招待つきで2人に送る');
check(userRow('csv7@example.test')['role'] === 'viewer', 'CSV-14: 「閲覧者」は viewer として登録する');
check(count(array_filter($GLOBALS['__MAILS'], static fn ($m) => in_array($m['to'], ['csv7@example.test', 'csv8@example.test'], true))) === 2,
    'CSV-15: 招待メールは作った人にだけ届く(差し替えた送信口)');
$r = call_handler('users_handle_import_csv', ['csv' => "email,name\nx@example.test,x"], 'tenant_admin');
check($r['code'] === 400, 'CSV-16: 必要な列がなければ 400');
$r = call_handler('users_handle_import_csv', ['csv' => "email,name,role\ncsv9@example.test,x,viewer", 'tenant_id' => 2], 'tenant_admin');
check($r['code'] === 403, 'CSV-17: 組織管理者はほかのテナントへは登録できない');

Db::run("UPDATE users SET name = '=HYPERLINK(\"x\")' WHERE email = 'csv2@example.test'");
$out = users_build_csv(1);
$lines = array_map('str_getcsv', array_filter(explode("\n", $out)));
check($lines[0] === ['email', 'name', 'role', 'status', 'last_login_at', 'password'], 'CSV-19: 出力の見出しは一括登録と同じ email、name、role から始まる');
$rows = array_column(array_slice($lines, 1), null, 0);
check($rows['csv1@example.test'][5] === '未設定' && $rows['operator@example.test'][4] === '2026-09-20 08:30:00', 'CSV-20: 出力にパスワードの状態と最終ログインを出す');
check(str_starts_with($rows['csv2@example.test'][1], "'="), 'CSV-21: 出力は数式を無害化する');
check(!isset($rows['operator@other.example.test']), 'CSV-22: 出力はそのテナントのユーザだけ');

// ============ RL: パスワード設定の API の回数の制限 ============
$ip = '203.0.113.77';
for ($i = 0; $i < UserPasswordTokens::RATE_LIMIT_FAILURES; $i++) {
    pwset_handle('check', ['token' => bin2hex(random_bytes(32))], $ip);
}
[$code, $data] = pwset_handle('check', ['token' => bin2hex(random_bytes(32))], $ip);
check($code === 429 && $data['reason'] === 'rate_limited', 'RL-1: 正しくないトークンを10回試した IP は 429');
call_handler('users_handle_send_password_mail', ['id' => (int) userRow('csv2@example.test')['id']], 'tenant_admin');
[$code] = pwset_handle('check', ['token' => lastToken()], $ip);
check($code === 429, 'RL-2: 制限中はその IP からは正しいトークンでも断る');
[$code] = pwset_handle('check', ['token' => lastToken()], '203.0.113.78');
check($code === 200, 'RL-3: ほかの IP からは使える');

echo "ALL TESTS PASSED\n";
