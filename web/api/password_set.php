<?php declare(strict_types=1);
/**
 * パスワード設定の公開 API(ログイン前。セッションも CSRF も使わず、トークンだけで動く)。
 *
 *   POST password_set.php?action=check  {"token": ...}                 リンクが使えるかと、設定するアカウントのメール
 *   POST password_set.php?action=set    {"token": ..., "password": ...} パスワードを設定する
 *
 * トークンは URL(set_password.php?token=...)で受け取り、ここへは本文で送る(アクセスログに残りにくくする)。
 * 正しくないトークンの試行は IP ごとに数え、直近30分に10回で 429 にする(ログインの IP 制限と同じ仕組み)。
 * 受講者のマイページ(sat.cojp.online)でも同じ API を使えるよう、管理画面のセッションに頼らない。
 */

require_once __DIR__ . '/../lib/UserPasswordTokens.php';

function pwset_json(array $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function pwset_body(): array
{
    $raw = file_get_contents('php://input');
    $data = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
    return is_array($data) ? $data : [];
}

/** メールアドレスの一部を伏せる(リンクを見た第三者に全部は見せない)。 */
function pwset_mask_email(string $email): string
{
    [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
    $head = mb_substr($local, 0, 2, 'UTF-8');
    return $head . str_repeat('*', max(1, mb_strlen($local, 'UTF-8') - 2)) . '@' . $domain;
}

/**
 * @return array{0:int,1:array<string,mixed>} [HTTP コード, 応答]
 */
function pwset_handle(string $action, array $body, string $ip): array
{
    if (UserPasswordTokens::ipRateLimited($ip)) {
        UserPasswordTokens::audit(null, null, 'password_set.ratelimited', 'ip=' . $ip, $ip);
        return [429, ['success' => false, 'error' => UserPasswordTokens::MSG_RATE_LIMITED, 'reason' => 'rate_limited']];
    }
    $token = isset($body['token']) && is_string($body['token']) ? trim($body['token']) : '';
    try {
        if ($action === 'check') {
            ['token' => $row, 'user' => $user] = UserPasswordTokens::inspect($token);
            return [200, [
                'success' => true,
                'purpose' => (string) $row['purpose'],
                'email' => pwset_mask_email((string) $user['email']),
                'expires_at' => substr((string) $row['expires_at'], 0, 16),
                'policy' => PasswordPolicy::DESCRIPTION,
            ]];
        }
        $password = isset($body['password']) && is_string($body['password']) ? $body['password'] : '';
        UserPasswordTokens::consume($token, $password, $ip);
        return [200, ['success' => true]];
    } catch (PasswordTokenException $e) {
        // 決まりに合わないパスワードは、正しいトークンでの入力の誤りなので数えない
        if ($e->reason !== 'policy') {
            UserPasswordTokens::recordFailure($ip, $e->reason);
        }
        return [$e->httpCode, ['success' => false, 'error' => $e->getMessage(), 'reason' => $e->reason]];
    }
}

try {
    $action = $_GET['action'] ?? '';
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (in_array($action, ['check', 'set'], true) && $method === 'POST') {
        [$code, $data] = pwset_handle($action, pwset_body(), (string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        pwset_json($data, $code);
    }
    pwset_json(['success' => false, 'error' => '不正なアクションです'], 400);
} catch (Throwable $e) {
    error_log('password_set: ' . $e->getMessage());
    pwset_json(['success' => false, 'error' => 'サーバエラー'], 500);
}
