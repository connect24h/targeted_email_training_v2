<?php declare(strict_types=1); require __DIR__."/../lib/bootstrap.php";

function auth_string(array $body, string $key): string
{
    if (!array_key_exists($key, $body) || !is_string($body[$key]) || trim($body[$key]) === '') {
        json_error($key . ' は必須です', 400);
    }
    return trim($body[$key]);
}

function auth_is_locked(?string $lockedUntil): bool
{
    if ($lockedUntil === null || $lockedUntil === '') {
        return false;
    }
    $lockedAt = strtotime($lockedUntil);
    return $lockedAt !== false && $lockedAt > time();
}

/**
 * IP 単位レート制限(OWASP A04): 同一 IP から直近 30 分で login.failed が
 * 10 回以上あれば true。email/アカウントをまたいだパスワードスプレーを止める
 * (アカウント単位ロックだけでは各 email 4 回以下でロックを回避できるため)。
 * audit_log(login.failed の ip)を集計するので専用テーブル不要。
 */
function auth_ip_rate_limited(string $ip): bool
{
    if ($ip === '') {
        return false;
    }
    $row = Db::one(
        "SELECT COUNT(*) AS c FROM audit_log
         WHERE action = 'login.failed' AND ip = ?
           AND occurred_at >= datetime('now','localtime','-30 minutes')",
        [$ip]
    );
    return $row !== null && (int) $row['c'] >= 10;
}

function auth_handle_login(): never
{
    $body = json_body();
    $email = auth_string($body, 'email');
    $password = auth_string($body, 'password');

    if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        json_error('email が不正です', 400);
    }

    // IP 単位レート制限: パスワードスプレー(多数 email × 各数回)を止める。
    $clientIp = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    if (auth_ip_rate_limited($clientIp)) {
        audit('login.ratelimited', 'ip=' . $clientIp);
        json_error('ログイン試行が多すぎます。しばらく待って再度お試しください', 429);
    }

    $user = Db::one('SELECT * FROM users WHERE email = ?', [$email]);
    if ($user === null) {
        // 存在しない email への試行も login.failed として記録し、IP レート制限に
        // カウントする(存在しない email を大量に撃つ列挙型スプレーを止めるため)。
        audit('login.failed', 'email=' . $email . ',reason=unknown_user');
        json_error('メールアドレスまたはパスワードが不正です', 401);
    }
    if ($user['status'] !== 'active') {
        json_error('ユーザは停止されています', 403);
    }
    if (auth_is_locked($user['locked_until'])) {
        json_error('アカウントはロックされています', 423);
    }
    if (!password_verify($password, (string) $user['password_hash'])) {
        Db::run(
            "UPDATE users
             SET failed_count = failed_count + 1,
                 locked_until = CASE
                     WHEN failed_count + 1 >= 5 THEN datetime('now','localtime','+15 minutes')
                     ELSE locked_until
                 END
             WHERE id = ?",
            [(int) $user['id']]
        );
        audit('login.failed', 'email=' . $email);
        json_error('メールアドレスまたはパスワードが不正です', 401);
    }
    // 所属テナントが停止・削除されていれば拒否する(superadmin は通す)。
    // パスワードの確認の後に判定し、テナントの状態を第三者に知られないようにする。
    $userTenantId = $user['tenant_id'] !== null ? (int) $user['tenant_id'] : null;
    if (!TenantStatus::userAllowed($userTenantId, (string) $user['role'])) {
        audit('login.tenant_inactive', 'email=' . $email . ',tenant_id=' . ($userTenantId ?? ''));
        json_error(TenantStatus::LOGIN_BLOCKED_MESSAGE, 403);
    }

    Db::run(
        "UPDATE users
         SET failed_count = 0, locked_until = NULL, last_login_at = datetime('now','localtime')
         WHERE id = ?",
        [(int) $user['id']]
    );
    session_regenerate_id(true);
    $_SESSION['uid'] = (int) $user['id'];
    $_SESSION['tenant_id'] = $user['tenant_id'] !== null ? (int) $user['tenant_id'] : null;
    $_SESSION['role'] = (string) $user['role'];
    $_SESSION['email'] = (string) $user['email'];
    $csrf = tet2_csrf_token();
    audit('login', 'email=' . $email);

    json_out([
        'success' => true,
        'user' => [
            'id' => (int) $user['id'],
            'email' => (string) $user['email'],
            'name' => $user['name'],
            'role' => (string) $user['role'],
            'tenant_id' => $user['tenant_id'] !== null ? (int) $user['tenant_id'] : null,
        ],
        'csrf' => $csrf,
    ]);
}

function auth_handle_logout(): never
{
    if (current_user() !== null) {
        audit('logout');
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', (bool) $params['secure'], (bool) $params['httponly']);
    }
    session_destroy();
    json_out(['success' => true]);
}

function auth_handle_me(): never
{
    $user = current_user();
    if ($user === null) {
        $notice = !empty($GLOBALS['__TET2_TENANT_SESSION_BLOCKED']) ? TenantStatus::SESSION_BLOCKED_MESSAGE : null;
        json_out(['success' => true, 'user' => null, 'notice' => $notice]);
    }
    json_out(['success' => true, 'user' => $user, 'csrf' => tet2_csrf_token()]);
}

try {
    $action = $_GET['action'] ?? '';
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($action === 'login' && $method === 'POST') {
        auth_handle_login();
    }
    if ($action === 'logout' && $method === 'POST') {
        auth_handle_logout();
    }
    if ($action === 'me' && $method === 'GET') {
        auth_handle_me();
    }
    json_error('不正なアクションです', 400);
} catch (Throwable $e) {
    error_log($e->getMessage());
    json_error('サーバエラー', 500);
}
