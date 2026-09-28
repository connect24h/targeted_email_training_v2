<?php
declare(strict_types=1);

/**
 * 受講者のマイページ(L1〜L5、L7)。合成 DB と .test のアドレスだけを使う。メールは EduMailer::useTransport で貯める。
 *
 *   IV-*  招待(対象者から受講者のアカウントを作り、受講者のサイトのパスワード設定の URL を送る)
 *   LG-*  ログイン(受講者、管理画面のユーザ、失敗の回数の制限、停止中のテナント、セッションの分離)
 *   PW-*  パスワードの変更と、忘れた時の再設定(応答が同じ)
 *   HM-*  ホームの ToDo(自分の分だけ、期限の近い順、ほかの人の id を無視)
 *   GR-*  成績(点数、合否、回数、答え合わせ、見せない設定、アウェアネスの推移)
 *   RT-*  再受講(新しい回、前の回が残る、レポートは最新の回、許さない設定、期限切れ、ほかの人の配信)
 *   SV-*  アンケート(未回答、履歴、匿名は中身を出さない)
 *   NT-*  訓練のデータに触れない
 */

require_once __DIR__ . '/helpers.php';
$dbPath = tet2_test_boot();
putenv('TET2_EDU_MAIL_DISABLE=1');
putenv('TET2_MAIL_OUTBOX_DIR');
putenv('TET2_LEARNER_BASE_URL=https://learner.example.test');
require_once __DIR__ . '/../lib/PasswordPolicy.php';
require_once __DIR__ . '/../lib/UserPasswordTokens.php';
require_once __DIR__ . '/../lib/LearnerAuth.php';
require_once __DIR__ . '/../lib/LearnerPortal.php';
require_once __DIR__ . '/../lib/HumanRiskScore.php';
if (!function_exists('tet2_csv_sanitize')) {
    // bootstrap.php と同じ定義(load_api は bootstrap を読まない。users.php が使う)。
    function tet2_csv_sanitize($v): string
    {
        $v = (string) $v;
        return ($v !== '' && in_array($v[0], ['=', '+', '-', '@', "\t", "\r"], true)) ? "'" . $v : $v;
    }
}
load_api('my');
load_api('learners');
load_api('users');
load_api('auth');
load_api('password_set');
load_api('edu_take');
load_api('edu_report');

$GLOBALS['__MAILS'] = [];
EduMailer::useTransport(static function (string $to, string $subject, string $body): bool {
    $GLOBALS['__MAILS'][] = ['to' => $to, 'subject' => $subject, 'body' => $body];
    return true;
});

const PW = 'Learner-Pass-2026';
$_SESSION = [];
$_SERVER['REMOTE_ADDR'] = '198.51.100.30';

function lastLearnerToken(string $to): string
{
    foreach (array_reverse($GLOBALS['__MAILS']) as $mail) {
        if ($mail['to'] === $to && preg_match('#https://learner\.example\.test/set_password\.php\?token=([0-9a-f]{64})&site=my#', $mail['body'], $m) === 1) {
            return $m[1];
        }
    }
    throw new RuntimeException('FAIL: ' . $to . ' への受講者のサイトの URL のメールがありません');
}
function myCall(string $fn, array $body = []): array
{
    return call_handler($fn, $body, 'viewer', []);
}
function myPost(string $fn, array $body = []): array
{
    $_SERVER['HTTP_X_CSRF_TOKEN'] = (string) ($_SESSION['my']['csrf'] ?? '');
    return myCall($fn, $body);
}
function myLogin(string $email, string $password): array
{
    $_SESSION = [];
    return myCall('my_handle_login', ['email' => $email, 'password' => $password]);
}

// ================================================================ IV 招待
$admin = ['id' => 1, 'tenant_id' => 1, 'role' => 'tenant_admin', 'email' => 'operator@example.test'];
$r = call_handler('learners_handle_invite', ['target_ids' => [1]], 'tenant_admin', [$admin]);
check($r['code'] === 200 && $r['payload']['sent'] === 1, 'IV-1: 対象者1件に招待を送れる');
$learner = Db::one('SELECT * FROM users WHERE target_id = 1');
check($learner !== null && $learner['role'] === 'learner' && (int) $learner['tenant_id'] === 1 && (int) $learner['password_pending'] === 1
    && $learner['email'] === 'target1@example.test', 'IV-2: 受講者のアカウントを対象者につないで作る(パスワード未設定)');
$token1 = lastLearnerToken('target1@example.test');
check(str_contains(end($GLOBALS['__MAILS'])['body'], 'https://learner.example.test/my.php'), 'IV-3: 招待のメールは受講者のサイトのパスワード設定とマイページを指す');
check((int) Db::one('SELECT COUNT(*) AS n FROM user_password_tokens WHERE token_hash = ?', [hash('sha256', $token1)])['n'] === 1,
    'IV-4: トークンは A と同じ表にハッシュだけを保存する');

// 管理画面のユーザで、同じテナントに同じアドレスの対象者がいる人
Db::run("INSERT INTO users (id, tenant_id, email, password_hash, name, role, status) VALUES (10, 1, 'TARGET2@example.test', ?, 'Two Admin', 'viewer', 'active')",
    [password_hash(PW, PASSWORD_DEFAULT)]);
$mailsBefore = count($GLOBALS['__MAILS']);
$r = call_handler('learners_handle_invite', ['target_ids' => [1, 2, 3]], 'tenant_admin', [$admin]);
$byTarget = array_column($r['payload']['results'], 'result', 'target_id');
check($byTarget[1] === 'sent' && $byTarget[2] === 'admin_account' && $byTarget[3] === 'error',
    'IV-5: 複数件の招待(再送、管理画面のアカウントは送らない、ほかのテナントの対象者は拒否)');
check(count($GLOBALS['__MAILS']) === $mailsBefore + 1 && (int) Db::one('SELECT COUNT(*) AS n FROM users WHERE target_id = 3')['n'] === 0,
    'IV-6: ほかのテナントの対象者にはアカウントを作らずメールも送らない');
check((int) Db::one("SELECT COUNT(*) AS n FROM users WHERE role = 'learner' AND target_id = 1")['n'] === 1, 'IV-7: 再送でアカウントを増やさない(1対象者に1つ)');
$dup = false;
try {
    Db::run("INSERT INTO users (tenant_id, email, password_hash, role, status, target_id) VALUES (1, 'dup@example.test', 'x', 'learner', 'active', 1)");
} catch (PDOException) {
    $dup = true;
}
check($dup, 'IV-8: 1対象者に learner は1つ(索引で拒否)');
$token1 = lastLearnerToken('target1@example.test');
$status = call_handler('learners_handle_status', [], 'tenant_admin', [$admin]);
$st = array_column($status['payload']['learners'], null, 'target_id');
check($st[1]['account'] === 'learner' && $st[1]['password_pending'] === true && $st[2]['account'] === 'admin' && !isset($st[3]),
    'IV-9: 対象者ごとのマイページの状態(受講者、管理画面のアカウント)を返す');
$learnersSrc = (string) file_get_contents(__DIR__ . '/../api/learners.php');
check(str_contains($learnersSrc, "require_role('tenant_admin')"), 'IV-10: 招待の API は組織管理者以上だけ');
$usersList = call_handler('users_handle_list', [], 'tenant_admin', [$admin]);
$emails = array_column($usersList['payload']['users'] ?? [], 'email');
check($usersList['code'] === 200 && !in_array('target1@example.test', $emails, true), 'IV-11: 管理画面のユーザの一覧に受講者のアカウントを出さない');

// パスワード設定(A の API をそのまま使う)
[$code] = pwset_handle('set', ['token' => $token1, 'password' => PW], '198.51.100.31');
check($code === 200 && (int) Db::one('SELECT password_pending FROM users WHERE target_id = 1')['password_pending'] === 0,
    'IV-12: 受講者も A のパスワード設定の API で設定できる');

// ================================================================ LG ログイン
$r = myLogin('target1@example.test', PW);
check($r['code'] === 200 && $r['payload']['user']['email'] === 'target1@example.test' && !isset($r['payload']['user']['id']),
    'LG-1: 受講者がメールアドレスとパスワードでログインできる(id は返さない)');
check(isset($_SESSION['my']['uid']) && !isset($_SESSION['uid']), 'LG-2: セッションは $_SESSION[my] だけに入れる(管理画面の uid を使わない)');
$learnerSession = $_SESSION;
$r = myLogin('Target1@Example.test', PW);
check($r['code'] === 200, 'LG-3: メールアドレスの大文字小文字を区別しない');

$_SERVER['REMOTE_ADDR'] = '198.51.100.40';
$r = call_handler('auth_handle_login', ['email' => 'target1@example.test', 'password' => PW], 'viewer', []);
check($r['code'] === 403 && str_contains($r['payload']['error'], 'マイページ用'), 'LG-4: 受講者のアカウントは管理画面にログインできない');

// 管理画面のユーザ(同じテナントに同じアドレスの対象者)
$r = myLogin('target2@example.test', PW);
check($r['code'] === 200 && $r['payload']['user']['is_admin_account'] === true, 'LG-5: 管理画面のユーザは同じパスワードでマイページに入れる');
$adminMySession = $_SESSION;
Db::run("INSERT INTO users (id, tenant_id, email, password_hash, name, role, status) VALUES (11, 1, 'no-target@example.test', ?, 'X', 'operator', 'active')",
    [password_hash(PW, PASSWORD_DEFAULT)]);
$r = myLogin('no-target@example.test', PW);
check($r['code'] === 403 && $r['payload']['error'] === LearnerAuth::MSG_NO_TARGET && empty($_SESSION['my']),
    'LG-6: 対象者につながらない管理画面のユーザはマイページに入れない');
Db::run("INSERT INTO users (id, tenant_id, email, password_hash, name, role, status) VALUES (12, NULL, 'root@example.test', ?, 'R', 'superadmin', 'active')",
    [password_hash(PW, PASSWORD_DEFAULT)]);
check(myLogin('root@example.test', PW)['code'] === 403, 'LG-7: システム管理者はマイページに入れない');

// セッションの分離: 管理画面のセッションの中身では受講者の API を使えない
$_SESSION = ['uid' => 1, 'tenant_id' => 1, 'role' => 'operator', 'email' => 'operator@example.test', 'pw_epoch' => 0];
check(myCall('my_handle_home')['code'] === 401, 'LG-8: 管理画面のセッションでは受講者の API を使えない');
// 受講者のセッションで管理画面の API を使えない(本物の bootstrap を別プロセスで)
$probe = __DIR__ . '/fixtures/session_probe.php';
$learnerId = (int) Db::one('SELECT id FROM users WHERE target_id = 1')['id'];
$out = shell_exec('TET2_DB_PATH=' . escapeshellarg($dbPath) . ' php ' . escapeshellarg($probe) . ' my ' . $learnerId . ' 1 learner 2>&1');
check(is_string($out) && str_contains($out, '認証が必要です') && !str_contains($out, '"probe":"ok"'),
    'LG-9: 受講者のセッション($_SESSION[my])では管理画面の API は未ログイン');
$out = shell_exec('TET2_DB_PATH=' . escapeshellarg($dbPath) . ' php ' . escapeshellarg($probe) . ' auth ' . $learnerId . ' 1 learner 2>&1');
check(is_string($out) && str_contains($out, '認証が必要です') && str_contains($out, 'session_uid=none'),
    'LG-10: learner の役割のセッションは管理画面の current_user で切る');

// ログインの失敗の回数の制限(アカウント: 5回で15分ロック)
$_SERVER['REMOTE_ADDR'] = '198.51.100.50';
for ($i = 0; $i < 5; $i++) {
    myLogin('target1@example.test', 'wrong-password');
}
check(myLogin('target1@example.test', PW)['code'] === 423, 'LG-11: 5回失敗するとロックし、正しいパスワードでも入れない');
Db::run('UPDATE users SET failed_count = 0, locked_until = NULL WHERE target_id = 1');
// IP: 管理画面とマイページの失敗を合わせて直近30分に10回で 429
$_SERVER['REMOTE_ADDR'] = '198.51.100.60';
for ($i = 0; $i < 10; $i++) {
    myLogin('nobody' . $i . '@example.test', 'wrong-password');
}
check(myLogin('target1@example.test', PW)['code'] === 429, 'LG-12: 同じ IP からの失敗が続くと 429(存在しないアドレスも数える)');
$_SERVER['REMOTE_ADDR'] = '198.51.100.30';

// 停止中のテナント
Db::run("UPDATE tenants SET status = 'suspended' WHERE id = 1");
$_SESSION = $learnerSession;
check(myCall('my_handle_home')['code'] === 401 && empty($_SESSION['my']), 'LG-13: テナントが停止されたらログイン中のセッションも切る');
$r = myLogin('target1@example.test', PW);
check($r['code'] === 403 && $r['payload']['error'] === TenantStatus::LOGIN_BLOCKED_MESSAGE, 'LG-14: 停止中のテナントの受講者はログインできない');
Db::run("UPDATE tenants SET status = 'active' WHERE id = 1");

// ================================================================ PW パスワード
myLogin('target1@example.test', PW);
$learnerSession = $_SESSION;
$r = myPost('my_handle_change_password', ['current_password' => 'wrong', 'new_password' => 'New-Learner-Pass-1']);
check($r['code'] === 400, 'PW-1: 今のパスワードが違えば変えない');
$r = myPost('my_handle_change_password', ['current_password' => PW, 'new_password' => 'short']);
check($r['code'] === 400 && str_contains($r['payload']['error'], '12文字'), 'PW-2: パスワードの決まり(A と同じ)');
$_SERVER['HTTP_X_CSRF_TOKEN'] = 'bad';
check(myCall('my_handle_change_password', ['current_password' => PW, 'new_password' => 'New-Learner-Pass-1'])['code'] === 403,
    'PW-3: CSRF トークンが違えば拒否');
$r = myPost('my_handle_change_password', ['current_password' => PW, 'new_password' => 'New-Learner-Pass-1']);
check($r['code'] === 200 && myCall('my_handle_home')['code'] === 200, 'PW-4: パスワードを変えても今のセッションは続く');
$_SESSION = $learnerSession;
check(myCall('my_handle_home')['code'] === 401, 'PW-5: パスワードを変えたら、ほかのセッションは切れる');
Db::run('UPDATE users SET password_hash = ? WHERE target_id = 1', [password_hash(PW, PASSWORD_DEFAULT)]);

// 忘れた時: 登録の有無で応答を変えない
$_SESSION = [];
$_SERVER['REMOTE_ADDR'] = '198.51.100.70';
$mails = count($GLOBALS['__MAILS']);
$known = myCall('my_handle_forgot', ['email' => 'target1@example.test']);
$unknown = myCall('my_handle_forgot', ['email' => 'nobody@example.test']);
$noTarget = myCall('my_handle_forgot', ['email' => 'no-target@example.test']);
check($known['code'] === 200 && $known === $unknown && $unknown === $noTarget, 'PW-6: 登録の有無で応答が同じ');
check(count($GLOBALS['__MAILS']) === $mails + 1 && str_contains(end($GLOBALS['__MAILS'])['subject'], 'マイページのパスワード再設定'),
    'PW-7: マイページを使える人にだけ再設定のメールを送る');
check(myCall('my_handle_forgot', ['email' => 'target1@example.test']) === $known && count($GLOBALS['__MAILS']) === $mails + 1,
    'PW-8: 同じ人へ続けては送らない(応答は同じ)');
$resetToken = lastLearnerToken('target1@example.test');
[$code] = pwset_handle('set', ['token' => $resetToken, 'password' => PW], '198.51.100.71');
check($code === 200, 'PW-9: 再設定のメールのリンクでパスワードを設定できる');
myCall('my_handle_forgot', ['email' => 'x1@example.test']);
check(myCall('my_handle_forgot', ['email' => 'target1@example.test'])['code'] === 429, 'PW-10: 同じ IP からの再設定の依頼の上限(5回)');
$_SERVER['REMOTE_ADDR'] = '198.51.100.30';

// ================================================================ 教育とアンケートのデータ
$future = date('Y-m-d H:i:s', strtotime('+10 days'));
$sooner = date('Y-m-d H:i:s', strtotime('+2 days'));
$past = date('Y-m-d H:i:s', strtotime('-1 day'));
Db::run("INSERT INTO edu_categories (id, tenant_id, name, slug) VALUES (1, 1, 'カテゴリ', 'lp-cat')");
Db::run("INSERT INTO edu_questions (id, tenant_id, category_id, title, options, correct_answer, explanation, option_explanations, difficulty) VALUES
    (1, 1, 1, '問A', '[\"a\",\"b\"]', '[1]', '解説A', '[\"aは違う\",\"bが正しい\"]', 1),
    (2, 1, 1, '問B', '[\"c\",\"d\"]', '[0]', '解説B', NULL, 1)");
$deliveries = [
    // id, title, type, pass, deadline, feedback, allow
    [1, '配信A eラーニング', 'elearning', 80, $future, 'after_submit', 1],
    [2, '配信B アウェアネス', 'awareness_quiz', null, $sooner, 'immediate', 1],
    [3, '配信C 受け直し不可', 'elearning', 80, $future, 'after_submit', 0],
    [4, '配信D 期限切れ', 'elearning', 80, $past, 'after_submit', 1],
    [5, '配信E 答え合わせなし', 'awareness_quiz', null, null, 'hidden', 1],
    [6, '配信F ほかの人だけ', 'elearning', 80, $future, 'after_submit', 1],
];
foreach ($deliveries as [$id, $title, $type, $pass, $deadline, $mode, $allow]) {
    Db::run("INSERT INTO edu_deliveries (id, tenant_id, title, status, delivery_type, pass_score, deadline, feedback_mode, allow_retake_after_pass)
        VALUES (?, 1, ?, 'running', ?, ?, ?, ?, ?)", [$id, $title, $type, $pass, $deadline, $mode, $allow]);
    Db::run('INSERT INTO edu_delivery_questions (delivery_id, question_id, sort_order) VALUES (?, 1, 0), (?, 2, 1)', [$id, $id]);
}
$tok = static fn(int $target, int $delivery): string => str_pad(dechex($target * 100 + $delivery), 32, 'a', STR_PAD_LEFT);
foreach ([1, 2, 3, 4, 5] as $d) {
    Db::run("INSERT INTO edu_assignments (tenant_id, delivery_id, target_id, access_token, status, token_expiry)
        VALUES (1, ?, 1, ?, 'assigned', (SELECT deadline FROM edu_deliveries WHERE id = ?))", [$d, $tok(1, $d), $d]);
}
foreach ([1, 6] as $d) {
    Db::run("INSERT INTO edu_assignments (tenant_id, delivery_id, target_id, access_token, status, token_expiry)
        VALUES (1, ?, 2, ?, 'assigned', (SELECT deadline FROM edu_deliveries WHERE id = ?))", [$d, $tok(2, $d), $d]);
}
function takeSubmit(string $token, array $answers): array
{
    $_GET['token'] = $token;
    $start = call_handler('take_handle_start', [], 'viewer', []);
    if ($start['code'] !== 200) {
        return $start;
    }
    $items = [];
    foreach ($answers as $qid => $ans) {
        $items[] = ['question_id' => $qid, 'answer' => $ans];
    }
    return call_handler('take_handle_submit', ['token' => $token, 'answers' => $items], 'viewer', []);
}
// 対象者2(ほかの人)が配信A を 0点で出す
takeSubmit($tok(2, 1), [1 => [0], 2 => [1]]);
// 対象者1: 配信A 合格、配信B アウェアネス 50%、配信C 合格、配信E 100%
$r = takeSubmit($tok(1, 1), [1 => [1], 2 => [0]]);
check($r['code'] === 200 && $r['payload']['result']['passed'] === true, 'GR-0: 受講のトークンのリンクは今までどおり使える');
takeSubmit($tok(1, 2), [1 => [1], 2 => [1]]);
takeSubmit($tok(1, 3), [1 => [1], 2 => [0]]);
takeSubmit($tok(1, 5), [1 => [1], 2 => [0]]);

// アンケート
Db::run("INSERT INTO surveys (id, tenant_id, title, status, is_anonymous) VALUES (1, 1, '記名', 'published', 0), (2, 1, '匿名', 'published', 1), (3, 1, '未回答', 'published', 0)");
Db::run("INSERT INTO survey_questions (id, survey_id, sort_order, question_type, title, options) VALUES
    (1, 1, 0, 'single', '満足度', '[\"良い\",\"悪い\"]'), (2, 2, 0, 'text', '感想', '[]'), (3, 3, 0, 'text', '意見', '[]')");
Db::run("INSERT INTO survey_deliveries (id, tenant_id, survey_id, title, status, deadline) VALUES
    (1, 1, 1, '記名の配信', 'open', NULL), (2, 1, 2, '匿名の配信', 'open', NULL), (3, 1, 3, '未回答の配信', 'open', ?),
    (4, 1, 3, '終了した配信', 'closed', NULL)", [$future]);
Db::run("INSERT INTO survey_assignments (id, tenant_id, delivery_id, target_id, access_token, status, answered_at) VALUES
    (1, 1, 1, 1, ?, 'answered', '2026-09-20 10:00:00'), (2, 1, 2, 1, ?, 'answered', '2026-09-21'),
    (3, 1, 3, 1, ?, 'assigned', NULL), (4, 1, 4, 1, ?, 'assigned', NULL), (5, 1, 3, 2, ?, 'assigned', NULL)",
    [str_repeat('1', 32), str_repeat('2', 32), str_repeat('3', 32), str_repeat('4', 32), str_repeat('5', 32)]);
Db::run("INSERT INTO survey_responses (id, tenant_id, delivery_id, assignment_id, submitted_at) VALUES (1, 1, 1, 1, '2026-09-20 10:00:00'), (2, 1, 2, NULL, '2026-09-21')");
Db::run("INSERT INTO survey_answers (response_id, question_id, value) VALUES (1, 1, '[0]'), (2, 2, '\"匿名の感想\"')");

// ================================================================ HM ホーム
myLogin('target1@example.test', PW);
$_GET['target_id'] = '2';
$home = myCall('my_handle_home');
unset($_GET['target_id']);
check($home['code'] === 200, 'HM-0: ホームを返す');
$titles = array_column($home['payload']['todos'], 'title');
check(!in_array('配信F ほかの人だけ', $titles, true) && !in_array('配信D 期限切れ', $titles, true),
    'HM-1: ほかの人の配信と期限切れは ToDo に出さない(?target_id= を無視)');
check($titles === ['未回答の配信'], 'HM-2: 完了した教育は出さず、未回答のアンケートを出す(終了した配信は出さない)');
// 未受講の教育を足して、期限の近い順を確かめる
Db::run("INSERT INTO edu_deliveries (id, tenant_id, title, status, delivery_type, deadline) VALUES (7, 1, '配信G すぐ', 'running', 'awareness_quiz', ?), (8, 1, '配信H 期限なし', 'running', 'awareness_quiz', NULL)",
    [date('Y-m-d H:i:s', strtotime('+1 day'))]);
Db::run("INSERT INTO edu_assignments (tenant_id, delivery_id, target_id, access_token, status) VALUES (1, 7, 1, ?, 'started'), (1, 8, 1, ?, 'assigned')",
    [$tok(1, 7), $tok(1, 8)]);
$todos = myCall('my_handle_home')['payload']['todos'];
check(array_column($todos, 'title') === ['配信G すぐ', '未回答の配信', '配信H 期限なし'], 'HM-3: 期限の近い順(期限なしは最後)');
check($todos[0]['url'] === 'take.php?token=' . $tok(1, 7) && $todos[0]['status'] === 'started' && $todos[1]['url'] === 'survey.php?token=' . str_repeat('3', 32),
    'HM-4: 受講と回答のリンクは自分のトークン');

// ================================================================ GR 成績
$grades = myCall('my_handle_grades')['payload'];
$g = array_column($grades['deliveries'], null, 'title');
check(!isset($g['配信F ほかの人だけ']), 'GR-1: ほかの人の配信は出さない');
check($g['配信A eラーニング']['status'] === 'completed' && $g['配信A eラーニング']['score'] === 100 && $g['配信A eラーニング']['passed'] === true
    && $g['配信A eラーニング']['attempt_count'] === 1 && $g['配信A eラーニング']['completed_at'] !== null,
    'GR-2: 配信ごとの状態、点数、合否、受講の日時、受講回数(自分の分)');
$review = $g['配信A eラーニング']['review'];
check(count($review) === 2 && $review[0]['your_answer'] === [1] && $review[0]['correct_answer'] === [1] && $review[0]['explanation'] === '解説A'
    && $review[0]['option_explanations'] === ['aは違う', 'bが正しい'], 'GR-3: 完了した配信は問題ごとの解答、正解、解説');
check($g['配信E 答え合わせなし']['review'] === null && $g['配信E 答え合わせなし']['review_hidden'] === true,
    'GR-4: 答え合わせを見せない設定の配信は解答と正解を出さない');
check($g['配信G すぐ']['status'] === 'started' && $g['配信G すぐ']['review'] === null && $g['配信H 期限なし']['status'] === 'assigned',
    'GR-5: 受講中・未受講の配信は答え合わせを出さない');
check(count($grades['awareness_trend']) === 2 && $grades['awareness_trend'][0]['percentage'] === 50,
    'GR-6: アウェアネスの正答率の推移');
check($g['配信A eラーニング']['can_retake'] === true && $g['配信C 受け直し不可']['can_retake'] === false
    && $g['配信C 受け直し不可']['retake_block_reason'] === EduAttempts::MSG_NOT_ALLOWED, 'GR-7: 受け直せるかと理由');

// ================================================================ RT 再受講
$reportCounts = static fn(): array => edu_rep_delivery_counts(1, 1);
$reportScores = static fn(): array => edu_rep_delivery_scores(1, 1, 80);
check($reportCounts()['completed'] === 1 && $reportScores()['average_score'] === 50.0, 'RT-0: 受け直しの前のレポート(完了1、平均50)');
$_GET['token'] = $tok(1, 1);
check(call_handler('take_handle_start', [], 'viewer', [])['code'] === 409, 'RT-1: 完了した配信は、受け直しを開くまでリンクで受け直せない(今までどおり)');
$r = myPost('my_handle_retake', ['delivery_id' => 1, 'target_id' => 2]);
check($r['code'] === 200 && $r['payload']['url'] === 'take.php?token=' . $tok(1, 1), 'RT-2: もう一度受講するで、自分の受講の画面の URL を返す(target_id を無視)');
check(Db::one('SELECT status FROM edu_assignments WHERE access_token = ?', [$tok(1, 1)])['status'] === 'completed'
    && $reportCounts()['completed'] === 1, 'RT-3: 受け直しの回を開いただけではレポートは変わらない');
$completedBefore = HumanRiskScore::computeForTarget(1, 1, date('Y-m-d'))['detail']['edu_completed'];
$r = takeSubmit($tok(1, 1), [1 => [0], 2 => [1]]);
check($r['code'] === 200 && $r['payload']['result']['percentage'] === 0 && $r['payload']['result']['completed'] === false,
    'RT-4: 受け直しの回を受講の画面で提出できる');
$attempts = Db::all('SELECT * FROM edu_attempts WHERE assignment_id = (SELECT id FROM edu_assignments WHERE access_token = ?) ORDER BY attempt_no', [$tok(1, 1)]);
check(count($attempts) === 2 && (int) $attempts[0]['percentage'] === 100 && (int) $attempts[0]['passed'] === 1
    && (int) $attempts[1]['percentage'] === 0 && (int) $attempts[1]['passed'] === 0 && (int) $attempts[1]['is_retake'] === 1,
    'RT-5: 受け直すと新しい回として記録し、前の回は消さない');
check($reportCounts()['completed'] === 0 && $reportScores()['average_score'] === 0.0,
    'RT-6: 教育レポートは最新の回で数える(不合格の受け直しで完了から外れる)');
check(HumanRiskScore::computeForTarget(1, 1, date('Y-m-d'))['detail']['edu_completed'] === $completedBefore - 1,
    'RT-7: 支援優先度も最新の回で数える');
$r = takeSubmit($tok(1, 1), [1 => [1], 2 => [0]]);
$g = array_column(myCall('my_handle_grades')['payload']['deliveries'], null, 'title');
check($r['payload']['result']['passed'] === true && $g['配信A eラーニング']['attempt_count'] === 3 && $g['配信A eラーニング']['status'] === 'completed'
    && $reportCounts()['completed'] === 1 && $reportScores()['average_score'] === 50.0, 'RT-8: 合格点まで受け直すと3回目で完了、レポートも最新の回');
$r = myPost('my_handle_retake', ['delivery_id' => 3]);
check($r['code'] === 409 && $r['payload']['error'] === EduAttempts::MSG_NOT_ALLOWED, 'RT-9: 合格の後の受け直しを許さない設定の配信は受け直せない');
Db::run("UPDATE edu_assignments SET status = 'completed' WHERE access_token = ?", [$tok(1, 4)]);
$r = myPost('my_handle_retake', ['delivery_id' => 4]);
check($r['code'] === 409 && $r['payload']['error'] === EduAttempts::MSG_EXPIRED, 'RT-10: 期限切れの配信は受け直せない');
check(myPost('my_handle_retake', ['delivery_id' => 6])['code'] === 404, 'RT-11: ほかの人の配信は受け直せない(見つからない)');
$_SERVER['HTTP_X_CSRF_TOKEN'] = 'bad';
check(myCall('my_handle_retake', ['delivery_id' => 1])['code'] === 403, 'RT-12: 再受講は CSRF トークンが要る');
$r = myPost('my_handle_retake', ['delivery_id' => 2]);
$awareness = Db::one('SELECT status FROM edu_assignments WHERE access_token = ?', [$tok(1, 2)]);
$r2 = takeSubmit($tok(1, 2), [1 => [1], 2 => [0]]);
$trend = myCall('my_handle_grades')['payload']['awareness_trend'];
check($r['code'] === 200 && $awareness['status'] === 'completed' && $r2['code'] === 200 && count($trend) === 3 && end($trend)['percentage'] === 100,
    'RT-13: アウェアネスも受け直せて、推移に回が増える');
$other = Db::one('SELECT status, score FROM edu_assignments WHERE access_token = ?', [$tok(2, 1)]);
check($other['status'] === 'started' && (int) $other['score'] === 0, 'RT-14: ほかの人の割当は変わらない');

// ================================================================ SV アンケート
$sv = myCall('my_handle_surveys')['payload'];
check(array_column($sv['open'], 'title') === ['未回答の配信'] && $sv['open'][0]['url'] === 'survey.php?token=' . str_repeat('3', 32),
    'SV-1: 未回答のアンケート(回答のリンク、終了した配信は出さない)');
$h = array_column($sv['history'], null, 'title');
check(count($sv['history']) === 2 && $h['記名の配信']['answered_at'] === '2026-09-20 10:00:00'
    && $h['記名の配信']['answers'] === [['question' => '満足度', 'answer' => '良い']], 'SV-2: 記名のアンケートは回答日時と自分の回答');
check($h['匿名の配信']['is_anonymous'] === true && $h['匿名の配信']['answers'] === null
    && !str_contains(json_encode($sv, JSON_UNESCAPED_UNICODE), '匿名の感想'), 'SV-3: 匿名のアンケートは本人にも回答の中身を出さない');

// ================================================================ ログアウト
check(myPost('my_handle_logout')['code'] === 200 && myCall('my_handle_home')['code'] === 401, 'LG-15: ログアウトすると受講者の API は使えない');
$_SESSION = $adminMySession;
check(myCall('my_handle_home')['code'] === 200, 'LG-16: 管理画面のユーザのマイページのセッション(対象者2)');
$_SESSION['my']['uid'] = 999;
check(myCall('my_handle_home')['code'] === 401, 'LG-17: 存在しないユーザのセッションは切る');

// 対象者を削除(アーカイブ)したらマイページに入れない
Db::run("UPDATE targets SET status = 'archived' WHERE id = 1");
check(myLogin('target1@example.test', PW)['code'] === 403, 'LG-18: 削除済みの対象者の受講者はログインできない');
Db::run("UPDATE targets SET status = 'active' WHERE id = 1");
// 受講者のアカウントの削除(成績は残る)
$r = call_handler('learners_handle_delete', ['target_id' => 1], 'tenant_admin', [$admin]);
check($r['code'] === 200 && (int) Db::one('SELECT COUNT(*) AS n FROM users WHERE target_id = 1')['n'] === 0
    && (int) Db::one('SELECT COUNT(*) AS n FROM edu_attempts')['n'] > 0, 'IV-13: 受講者のアカウントを消しても成績は残る');

// ================================================================ NT 訓練のデータに触れない
$files = ['api/my.php', 'lib/LearnerAuth.php', 'lib/LearnerPortal.php', 'my.php'];
$forbidden = '/\b(campaigns|campaign_targets|campaign_contents|events|delivery_log|send_schedule|credential_captures|report_mail_matches|report_mails|suspicious_mails|human_risk_scores)\b/';
foreach ($files as $file) {
    $src = (string) file_get_contents(__DIR__ . '/../' . $file);
    check(preg_match($forbidden, $src) !== 1, "NT-1: {$file} は訓練の表の名前を含まない");
}
$mySrc = (string) file_get_contents(__DIR__ . '/../api/my.php');
check(preg_match('/require[^;]*bootstrap/', $mySrc) !== 1 && !str_contains($mySrc, "'target_id'"),
    'NT-2: 受講者の API は管理画面の bootstrap を読まず、target_id を受け取らない');

echo "ALL TESTS PASSED\n";
