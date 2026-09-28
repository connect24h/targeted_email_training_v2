<?php declare(strict_types=1); require __DIR__."/../lib/bootstrap.php";
require_once __DIR__ . '/../lib/AdminMfa.php';

/** パスワードを確かめた後、多要素認証のコードを待つ時間(秒)。過ぎたらパスワードから入り直す。 */
const AUTH_MFA_PENDING_TTL = 300;

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
        auth_count_failure((int) $user['id']);
        audit('login.failed', 'email=' . $email);
        json_error('メールアドレスまたはパスワードが不正です', 401);
    }
    // 受講者のマイページ専用のアカウントは、管理画面にはログインできない(パスワードの確認の後に伝える)
    if ((string) $user['role'] === 'learner') {
        audit('login.learner_denied', 'email=' . $email);
        json_error('このアカウントは受講者のマイページ用です。管理画面にはログインできません', 403);
    }
    // 所属テナントが停止・削除されていれば拒否する(superadmin は通す)。
    // パスワードの確認の後に判定し、テナントの状態を第三者に知られないようにする。
    $userTenantId = $user['tenant_id'] !== null ? (int) $user['tenant_id'] : null;
    if (!TenantStatus::userAllowed($userTenantId, (string) $user['role'])) {
        audit('login.tenant_inactive', 'email=' . $email . ',tenant_id=' . ($userTenantId ?? ''));
        json_error(TenantStatus::LOGIN_BLOCKED_MESSAGE, 403);
    }

    // 多要素認証を有効にしているユーザは、ここではログインさせず2段目(mfa_verify)を待つ。
    // 待っている間のセッションは uid を持たないので、どの API も未ログインとして扱う(bootstrap.php の current_user)。
    // 失敗の回数はここでは戻さない(パスワードを知る人がコードを推測し直せないよう、コードの失敗も同じ回数に数える)。
    if ($user['mfa_enabled_at'] !== null) {
        session_regenerate_id(true);
        // 前にログインしていた管理画面のセッションが残っていても、ここで外す(受講者のマイページの $_SESSION['my'] は残す)
        unset($_SESSION['uid'], $_SESSION['tenant_id'], $_SESSION['role'], $_SESSION['email'], $_SESSION['pw_epoch']);
        $_SESSION['mfa_pending'] = [
            'uid' => (int) $user['id'],
            'epoch' => (int) ($user['session_epoch'] ?? 0),
            'expires' => time() + AUTH_MFA_PENDING_TTL,
        ];
        audit('login.password_ok', 'email=' . $email . ',mfa=pending');
        json_out(['success' => true, 'mfa_required' => true]);
    }
    auth_complete_login($user, 'email=' . $email);
}

/** パスワードかコードの失敗を1回数え、5回目で15分ロックする(従来のパスワードの失敗と同じ決まり)。 */
function auth_count_failure(int $userId): void
{
    Db::run(
        "UPDATE users
         SET failed_count = failed_count + 1,
             locked_until = CASE
                 WHEN failed_count + 1 >= 5 THEN datetime('now','localtime','+15 minutes')
                 ELSE locked_until
             END
         WHERE id = ?",
        [$userId]
    );
}

/** ログインを完了させる(失敗の回数を戻し、セッションを作り直して uid を入れる)。 */
function auth_complete_login(array $user, string $auditDetail): never
{
    Db::run(
        "UPDATE users
         SET failed_count = 0, locked_until = NULL, last_login_at = datetime('now','localtime')
         WHERE id = ?",
        [(int) $user['id']]
    );
    session_regenerate_id(true);
    unset($_SESSION['mfa_pending']);
    $_SESSION['uid'] = (int) $user['id'];
    $_SESSION['tenant_id'] = $user['tenant_id'] !== null ? (int) $user['tenant_id'] : null;
    $_SESSION['role'] = (string) $user['role'];
    $_SESSION['email'] = (string) $user['email'];
    // パスワードが変わったらこのセッションを切るための値(bootstrap.php の current_user() が比べる)
    $_SESSION['pw_epoch'] = (int) ($user['session_epoch'] ?? 0);
    $csrf = tet2_csrf_token();
    audit('login', $auditDetail);
    $current = current_user();

    json_out([
        'success' => true,
        'user' => [
            'id' => (int) $user['id'],
            'email' => (string) $user['email'],
            'name' => $user['name'],
            'role' => (string) $user['role'],
            'tenant_id' => $user['tenant_id'] !== null ? (int) $user['tenant_id'] : null,
            'mfa_enabled' => (bool) ($current['mfa_enabled'] ?? ($user['mfa_enabled_at'] !== null)),
            'mfa_enrollment_required' => (bool) ($current['mfa_enrollment_required'] ?? false),
        ],
        'csrf' => $csrf,
    ]);
}

/** 2段目を待っているセッションのユーザ。なければ、または期限を過ぎていれば 401(セッションも空にする)。 */
function auth_pending_user(): array
{
    $pending = $_SESSION['mfa_pending'] ?? null;
    if (!is_array($pending) || (int) ($pending['expires'] ?? 0) < time()) {
        unset($_SESSION['mfa_pending']);
        json_error('確認の時間が過ぎました。もう一度メールアドレスとパスワードを入力してください', 401);
    }
    $user = Db::one('SELECT * FROM users WHERE id = ?', [(int) $pending['uid']]);
    // 待っている間にパスワードが変わった、停止された、多要素認証を解除された時は、入り直させる
    if ($user === null || (int) ($user['session_epoch'] ?? 0) !== (int) $pending['epoch']
        || $user['status'] !== 'active' || $user['mfa_enabled_at'] === null || (string) $user['role'] === 'learner') {
        unset($_SESSION['mfa_pending']);
        json_error('もう一度メールアドレスとパスワードを入力してください', 401);
    }
    return $user;
}

/**
 * ログインの2段目。認証アプリのコード(code)か、回復コード(recovery_code)のどちらかを受け付ける。
 * 失敗はパスワードの失敗と同じ回数に数え、5回でロックする(ロックしたら2段目の状態も消す)。
 */
function auth_handle_mfa_verify(): never
{
    $user = auth_pending_user();
    $body = json_body();
    $code = is_string($body['code'] ?? null) ? trim($body['code']) : '';
    $recovery = is_string($body['recovery_code'] ?? null) ? trim($body['recovery_code']) : '';
    if ($code === '' && $recovery === '') {
        json_error('確認コードを入力してください', 400);
    }
    $email = (string) $user['email'];
    $clientIp = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    if (auth_ip_rate_limited($clientIp)) {
        audit('login.ratelimited', 'ip=' . $clientIp);
        json_error('ログイン試行が多すぎます。しばらく待って再度お試しください', 429);
    }
    if (auth_is_locked($user['locked_until'])) {
        unset($_SESSION['mfa_pending']);
        json_error('アカウントはロックされています', 423);
    }
    $userTenantId = $user['tenant_id'] !== null ? (int) $user['tenant_id'] : null;
    if (!TenantStatus::userAllowed($userTenantId, (string) $user['role'])) {
        unset($_SESSION['mfa_pending']);
        json_error(TenantStatus::LOGIN_BLOCKED_MESSAGE, 403);
    }

    $userId = (int) $user['id'];
    if ($recovery !== '') {
        $ok = AdminMfa::useRecoveryCode($userId, $recovery);
    } else {
        try {
            $ok = AdminMfa::verifyCode($userId, $code, time());
        } catch (RuntimeException $e) {
            error_log('mfa verify: ' . $e->getMessage());
            json_error('確認コードを確かめられません。回復コードを使うか、管理者に連絡してください', 503);
        }
    }
    if (!$ok) {
        auth_count_failure($userId);
        audit('login.failed', 'email=' . $email . ',reason=' . ($recovery !== '' ? 'mfa_recovery' : 'mfa'));
        $after = Db::one('SELECT locked_until FROM users WHERE id = ?', [$userId]);
        if (auth_is_locked($after['locked_until'] ?? null)) {
            unset($_SESSION['mfa_pending']);
            json_error('アカウントはロックされています', 423);
        }
        json_error('確認コードが正しくありません', 401);
    }
    if ($recovery !== '') {
        audit('user.mfa_recovery_used', 'user_id=' . $userId . ',remaining=' . AdminMfa::remainingRecoveryCodes($userId));
    }
    auth_complete_login($user, 'email=' . $email . ',mfa=' . ($recovery !== '' ? 'recovery' : 'totp'));
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
        // 2段目を待っている間に画面を読み込み直した時は、コードの入力から続けられるようにする
        $pending = $_SESSION['mfa_pending'] ?? null;
        $mfaPending = is_array($pending) && (int) ($pending['expires'] ?? 0) >= time();
        json_out(['success' => true, 'user' => null, 'notice' => $notice, 'mfa_pending' => $mfaPending]);
    }
    json_out(['success' => true, 'user' => $user, 'csrf' => tet2_csrf_token()]);
}

try {
    $action = $_GET['action'] ?? '';
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($action === 'login' && $method === 'POST') {
        auth_handle_login();
    }
    if ($action === 'mfa_verify' && $method === 'POST') {
        auth_handle_mfa_verify();
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
