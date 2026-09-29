<?php declare(strict_types=1);
/**
 * 受講者のマイページの API(受講者のサイト sat.cojp.online で公開する)。
 *
 *   GET  my.php?action=me               ログイン中の人(未ログインなら user=null)と CSRF トークン
 *   POST my.php?action=login            {email, password}
 *   POST my.php?action=logout           (CSRF)
 *   POST my.php?action=change_password  {current_password, new_password} (CSRF)
 *   POST my.php?action=forgot           {email}  登録の有無にかかわらず同じ応答
 *   GET  my.php?action=home             期限つきの ToDo(未受講・受講中の教育、未回答のアンケート)と社内の問い合わせ先
 *   GET  my.php?action=grades           自分の成績(配信ごと、回ごと、答え合わせ、アウェアネスの推移、自分の受講完了率、自分の分野ごとの正答率)
 *   POST my.php?action=retake           {delivery_id} (CSRF) もう一度受講する → 受講の画面の URL
 *   GET  my.php?action=surveys          自分のアンケート(未回答と回答の履歴)
 *
 * 管理画面の bootstrap.php(セッション TET2SESID と $_SESSION['uid'])は読まない。セッションは LearnerAuth
 * (TET2MYSESID と $_SESSION['my'])。対象者はセッションの人から毎回引き、画面から target_id や user_id を受け取らない。
 */

require_once __DIR__ . '/../lib/LearnerAuth.php';
require_once __DIR__ . '/../lib/LearnerPortal.php';

function my_json(array $data, int $code = 200): never
{
    // テストでは応答を捕まえる json_out が定義されている(本番のこの API は bootstrap を読まないので未定義)
    if (function_exists('json_out')) {
        json_out($data, $code);
    }
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function my_error(string $message, int $code = 400): never
{
    my_json(['success' => false, 'error' => $message], $code);
}

function my_body(): array
{
    if (function_exists('json_body')) {
        return json_body();
    }
    $raw = file_get_contents('php://input');
    $data = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
    return is_array($data) ? $data : [];
}

function my_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '');
}

function my_body_string(array $body, string $key): string
{
    return isset($body[$key]) && is_string($body[$key]) ? $body[$key] : '';
}

/** ログイン中の人。いなければ 401(テナントの停止で切った時はその理由)。 */
function my_require_login(): array
{
    $me = LearnerAuth::current();
    if ($me === null) {
        my_error(LearnerAuth::$tenantBlocked ? TenantStatus::SESSION_BLOCKED_MESSAGE : 'ログインしてください', 401);
    }
    return $me;
}

function my_require_csrf(): void
{
    $sent = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!LearnerAuth::csrfValid($sent)) {
        my_error('CSRF トークンが不正です。画面を読み込み直してください', 403);
    }
}

/** 画面に出す本人の情報(対象者の id やユーザの id は出さない)。 */
function my_profile(array $me): array
{
    return [
        'name' => (string) ($me['target']['name'] ?? $me['user']['name'] ?? ''),
        'email' => (string) $me['user']['email'],
        'department' => (string) ($me['target']['department'] ?? ''),
        'is_admin_account' => (string) $me['user']['role'] !== 'learner',
    ];
}

function my_handle_me(): never
{
    $me = LearnerAuth::current();
    if ($me === null) {
        my_json(['success' => true, 'user' => null, 'notice' => LearnerAuth::$tenantBlocked ? TenantStatus::SESSION_BLOCKED_MESSAGE : null]);
    }
    my_json(['success' => true, 'user' => my_profile($me), 'csrf' => LearnerAuth::csrfToken(),
        'password_policy' => PasswordPolicy::DESCRIPTION]);
}

function my_handle_login(): never
{
    $body = my_body();
    $email = trim(my_body_string($body, 'email'));
    $password = my_body_string($body, 'password');
    if ($email === '' || $password === '') {
        my_error('メールアドレスとパスワードを入力してください', 400);
    }
    try {
        $me = LearnerAuth::login($email, $password, my_ip());
    } catch (LearnerAuthException $e) {
        my_error($e->getMessage(), $e->httpCode);
    }
    my_json(['success' => true, 'user' => my_profile($me), 'csrf' => LearnerAuth::csrfToken(),
        'password_policy' => PasswordPolicy::DESCRIPTION]);
}

function my_handle_logout(): never
{
    my_require_csrf();
    LearnerAuth::clear();
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
    my_json(['success' => true]);
}

function my_handle_change_password(): never
{
    my_require_csrf();
    $me = my_require_login();
    $body = my_body();
    try {
        LearnerAuth::changePassword($me, my_body_string($body, 'current_password'), my_body_string($body, 'new_password'), my_ip());
    } catch (LearnerAuthException $e) {
        my_error($e->getMessage(), $e->httpCode);
    }
    my_json(['success' => true, 'csrf' => LearnerAuth::csrfToken()]);
}

function my_handle_forgot(): never
{
    $email = trim(my_body_string(my_body(), 'email'));
    try {
        LearnerAuth::forgot($email, my_ip());
    } catch (LearnerAuthException $e) {
        my_error($e->getMessage(), $e->httpCode);
    }
    // 登録の有無、送ったかどうかで応答を変えない
    my_json(['success' => true, 'message' => LearnerAuth::MSG_FORGOT_SENT]);
}

function my_handle_home(): never
{
    $me = my_require_login();
    $tenantId = (int) $me['target']['tenant_id'];
    my_json(['success' => true, 'user' => my_profile($me),
        'todos' => LearnerPortal::todos($tenantId, (int) $me['target']['id']),
        'contact' => LearnerPortal::contact($tenantId)]);
}

function my_handle_grades(): never
{
    $me = my_require_login();
    my_json(['success' => true] + LearnerPortal::grades((int) $me['target']['tenant_id'], (int) $me['target']['id']));
}

function my_handle_retake(): never
{
    my_require_csrf();
    $me = my_require_login();
    $deliveryId = my_body()['delivery_id'] ?? null;
    if (!is_int($deliveryId) || $deliveryId < 1) {
        my_error('delivery_id が不正です', 400);
    }
    try {
        $url = LearnerPortal::retake((int) $me['target']['tenant_id'], (int) $me['target']['id'], $deliveryId);
    } catch (OutOfBoundsException $e) {
        my_error($e->getMessage(), 404);
    } catch (DomainException $e) {
        my_error($e->getMessage(), 409);
    }
    UserPasswordTokens::audit((int) $me['user']['tenant_id'], (int) $me['user']['id'], 'my_retake', 'delivery_id=' . $deliveryId, my_ip());
    my_json(['success' => true, 'url' => $url]);
}

function my_handle_surveys(): never
{
    $me = my_require_login();
    my_json(['success' => true] + LearnerPortal::surveys((int) $me['target']['tenant_id'], (int) $me['target']['id']));
}

try {
    LearnerAuth::initSession();
    $action = $_GET['action'] ?? '';
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $routes = [
        'GET' => ['me' => 'my_handle_me', 'home' => 'my_handle_home', 'grades' => 'my_handle_grades', 'surveys' => 'my_handle_surveys'],
        'POST' => ['login' => 'my_handle_login', 'logout' => 'my_handle_logout', 'change_password' => 'my_handle_change_password',
            'forgot' => 'my_handle_forgot', 'retake' => 'my_handle_retake'],
    ];
    $handler = is_string($action) ? ($routes[$method][$action] ?? null) : null;
    if ($handler === null) {
        my_error('不正なアクションです', 400);
    }
    $handler();
} catch (Throwable $e) {
    error_log('my: ' . $e->getMessage());
    my_error('サーバエラー', 500);
}
