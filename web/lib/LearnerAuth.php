<?php
/**
 * 受講者のマイページの認証(L1、L2)。管理画面の bootstrap.php とは別のセッションとログインの仕組み。
 *
 * - だれが使えるか: role='learner'(users.target_id で対象者につながる)と、管理画面のユーザ(viewer、operator、
 *   tenant_admin)のうち、同じテナントに同じメールアドレスの対象者がいる人(1人1アカウント。管理画面と同じパスワード)。
 *   superadmin と、対象者につながらない管理画面のユーザは使えない。削除済み(アーカイブ)の対象者も使えない。
 * - セッション: クッキーの名前は TET2MYSESID(管理画面は TET2SESID)。中身は $_SESSION['my'] だけに入れ、
 *   管理画面の $_SESSION['uid'] は使わない。クッキーを差し替えても、管理画面の API(current_user は uid を読む)と
 *   受講者の API(ここは my を読む)は互いのセッションを使えない。
 * - 対象者はセッションに入れた user の id から毎回引き直す(画面から id を受け取らない)。
 * - ログインの失敗の制限は管理画面(api/auth.php)と同じ: アカウントは5回で15分ロック(users の同じ列)、
 *   IP は直近30分に10回の失敗(管理画面とマイページの失敗を合わせて数える)で 429。
 * - 停止中・削除済みのテナントの人はログインできず、ログイン中のセッションも次の操作で切る(TenantStatus)。
 * - パスワードが変わったら(session_epoch)、それより前のセッションを切る(管理画面と同じ)。
 */
declare(strict_types=1);

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/TenantStatus.php';
require_once __DIR__ . '/PasswordPolicy.php';
require_once __DIR__ . '/UserPasswordTokens.php';

final class LearnerAuthException extends RuntimeException
{
    public function __construct(string $message, public readonly int $httpCode)
    {
        parent::__construct($message);
    }
}

final class LearnerAuth
{
    public const SESSION_NAME = 'TET2MYSESID';
    public const SESSION_KEY = 'my';
    /** マイページを使える管理画面のユーザの役割(superadmin はテナントを持たないので使えない)。 */
    public const ADMIN_ROLES = ['viewer', 'operator', 'tenant_admin'];

    public const FAILED_ACTION = 'my_login.failed';
    public const IP_LIMIT = 10;
    public const IP_WINDOW_MINUTES = 30;
    public const LOCK_AFTER = 5;
    public const LOCK_MINUTES = 15;
    /** パスワードを忘れた時のメールの、IP ごとの上限(直近30分)。 */
    public const FORGOT_IP_LIMIT = 5;
    /** 同じ人へ再設定のメールを続けて送らない間隔(分)。 */
    public const FORGOT_USER_INTERVAL_MINUTES = 5;

    public const MSG_BAD_LOGIN = 'メールアドレスまたはパスワードが正しくありません';
    public const MSG_LOCKED = 'ログインの失敗が続いたため、しばらくログインできません。15分ほど待ってからお試しください';
    public const MSG_RATE_LIMITED = 'ログインの試行が多すぎます。しばらく待ってからお試しください';
    public const MSG_NO_TARGET = 'このアカウントではマイページを使えません。社内の担当者にお問い合わせください';
    public const MSG_SUSPENDED = 'このアカウントは現在ご利用いただけません。社内の担当者にお問い合わせください';
    public const MSG_FORGOT_SENT = 'ご登録のメールアドレスであれば、パスワード再設定のメールを送りました。メールが届かない場合は、社内の担当者にお問い合わせください。';

    /** このリクエストで、テナントの停止によりセッションを切ったか。 */
    public static bool $tenantBlocked = false;

    public static function initSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        session_name(self::SESSION_NAME);
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => true,
        ]);
        session_start();
    }

    /** 受講者の CSRF トークン(管理画面と同じ仕組み: セッションに入れ、X-CSRF-Token のヘッダーで照合する)。 */
    public static function csrfToken(): string
    {
        if (empty($_SESSION[self::SESSION_KEY]['csrf'])) {
            $_SESSION[self::SESSION_KEY]['csrf'] = bin2hex(random_bytes(32));
        }
        return (string) $_SESSION[self::SESSION_KEY]['csrf'];
    }

    public static function csrfValid(string $sent): bool
    {
        $expected = $_SESSION[self::SESSION_KEY]['csrf'] ?? '';
        return is_string($expected) && $expected !== '' && hash_equals($expected, $sent);
    }

    /**
     * マイページでその人がつながる対象者。使えなければ null。
     * learner は users.target_id、管理画面のユーザは同じテナントの同じメールアドレスの対象者(大文字小文字を区別しない)。
     */
    public static function targetFor(array $user): ?array
    {
        if ($user['tenant_id'] === null) {
            return null;
        }
        $role = (string) $user['role'];
        if ($role === 'learner') {
            if ($user['target_id'] === null) {
                return null;
            }
            return Db::one(
                "SELECT id, tenant_id, email, name, department FROM targets
                 WHERE id = ? AND tenant_id = ? AND status = 'active' AND lower(email) = lower(?)",
                [(int) $user['target_id'], (int) $user['tenant_id'], (string) $user['email']]
            );
        }
        if (!in_array($role, self::ADMIN_ROLES, true)) {
            return null;
        }
        return Db::one(
            "SELECT id, tenant_id, email, name, department FROM targets
             WHERE tenant_id = ? AND status = 'active' AND lower(email) = lower(?) ORDER BY id LIMIT 1",
            [(int) $user['tenant_id'], (string) $user['email']]
        );
    }

    /**
     * ログイン中の受講者。セッションが無い、使えなくなった(停止、テナントの停止、パスワードの変更、対象者の削除)なら null。
     * @return array{user: array<string,mixed>, target: array<string,mixed>}|null
     */
    public static function current(): ?array
    {
        $session = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_array($session) || empty($session['uid'])) {
            return null;
        }
        $user = Db::one('SELECT id, tenant_id, email, name, role, status, session_epoch, target_id FROM users WHERE id = ?',
            [(int) $session['uid']]);
        $target = null;
        if ($user !== null && (string) $user['status'] === 'active'
            && (int) $user['session_epoch'] === (int) ($session['epoch'] ?? -1)
            && (int) ($user['tenant_id'] ?? 0) === (int) ($session['tenant_id'] ?? -1)) {
            if (!TenantStatus::userAllowed($user['tenant_id'] !== null ? (int) $user['tenant_id'] : null, (string) $user['role'])) {
                self::$tenantBlocked = true;
            } else {
                $target = self::targetFor($user);
            }
        }
        if ($user === null || $target === null) {
            self::clear();
            return null;
        }
        return ['user' => $user, 'target' => $target];
    }

    public static function clear(): void
    {
        unset($_SESSION[self::SESSION_KEY]);
    }

    /** IP ごとの制限。管理画面とマイページのログインの失敗を合わせて数える。 */
    public static function ipRateLimited(string $ip): bool
    {
        if ($ip === '') {
            return false;
        }
        $row = Db::one(
            "SELECT COUNT(*) AS c FROM audit_log
             WHERE action IN ('login.failed', ?) AND ip = ?
               AND occurred_at >= datetime('now','localtime','-" . self::IP_WINDOW_MINUTES . " minutes')",
            [self::FAILED_ACTION, $ip]
        );
        return $row !== null && (int) $row['c'] >= self::IP_LIMIT;
    }

    /** メールアドレスでアカウントを引く(大文字小文字を区別しない。1件に決まらなければ null)。 */
    public static function findByEmail(string $email): ?array
    {
        $rows = Db::all(
            'SELECT id, tenant_id, email, name, role, status, password_hash, password_pending, failed_count, locked_until,
                    session_epoch, target_id
             FROM users WHERE lower(email) = lower(?) LIMIT 2',
            [$email]
        );
        return count($rows) === 1 ? $rows[0] : null;
    }

    /**
     * ログイン。成功ならセッションを作り、ユーザと対象者を返す。
     * @throws LearnerAuthException
     * @return array{user: array<string,mixed>, target: array<string,mixed>}
     */
    public static function login(string $email, string $password, string $ip): array
    {
        if (self::ipRateLimited($ip)) {
            UserPasswordTokens::audit(null, null, 'my_login.ratelimited', 'ip=' . $ip, $ip);
            throw new LearnerAuthException(self::MSG_RATE_LIMITED, 429);
        }
        $user = self::findByEmail($email);
        if ($user === null) {
            // 存在しないメールアドレスも失敗として数える(IP の制限に効かせる)
            UserPasswordTokens::audit(null, null, self::FAILED_ACTION, 'reason=unknown_user', $ip);
            throw new LearnerAuthException(self::MSG_BAD_LOGIN, 401);
        }
        $userId = (int) $user['id'];
        $tenantId = $user['tenant_id'] !== null ? (int) $user['tenant_id'] : null;
        $locked = $user['locked_until'] !== null && ($t = strtotime((string) $user['locked_until'])) !== false && $t > time();
        if ($locked) {
            UserPasswordTokens::audit($tenantId, $userId, self::FAILED_ACTION, 'reason=locked', $ip);
            throw new LearnerAuthException(self::MSG_LOCKED, 423);
        }
        if (!password_verify($password, (string) $user['password_hash'])) {
            Db::run(
                "UPDATE users
                 SET failed_count = failed_count + 1,
                     locked_until = CASE
                         WHEN failed_count + 1 >= " . self::LOCK_AFTER . " THEN datetime('now','localtime','+" . self::LOCK_MINUTES . " minutes')
                         ELSE locked_until
                     END
                 WHERE id = ?",
                [$userId]
            );
            UserPasswordTokens::audit($tenantId, $userId, self::FAILED_ACTION, 'reason=password', $ip);
            throw new LearnerAuthException(self::MSG_BAD_LOGIN, 401);
        }
        // ここから先はパスワードが正しい人にだけ理由を伝える
        if ((string) $user['status'] !== 'active') {
            throw new LearnerAuthException(self::MSG_SUSPENDED, 403);
        }
        if (!TenantStatus::userAllowed($tenantId, (string) $user['role'])) {
            UserPasswordTokens::audit($tenantId, $userId, 'my_login.tenant_inactive', 'user_id=' . $userId, $ip);
            throw new LearnerAuthException(TenantStatus::LOGIN_BLOCKED_MESSAGE, 403);
        }
        $target = self::targetFor($user);
        if ($target === null) {
            UserPasswordTokens::audit($tenantId, $userId, 'my_login.no_target', 'user_id=' . $userId, $ip);
            throw new LearnerAuthException(self::MSG_NO_TARGET, 403);
        }
        Db::run(
            "UPDATE users SET failed_count = 0, locked_until = NULL, last_login_at = datetime('now','localtime') WHERE id = ?",
            [$userId]
        );
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $_SESSION[self::SESSION_KEY] = [
            'uid' => $userId,
            'tenant_id' => $tenantId,
            'epoch' => (int) $user['session_epoch'],
            'csrf' => bin2hex(random_bytes(32)),
        ];
        UserPasswordTokens::audit($tenantId, $userId, 'my_login', 'target_id=' . (int) $target['id'], $ip);
        return ['user' => $user, 'target' => $target];
    }

    /**
     * パスワードの変更(ログイン中)。今のパスワードを確かめる。ほかのセッションは切り、このセッションは続ける。
     * @throws LearnerAuthException
     */
    public static function changePassword(array $me, string $current, string $new, string $ip): void
    {
        $userId = (int) $me['user']['id'];
        $row = Db::one('SELECT password_hash FROM users WHERE id = ?', [$userId]);
        if ($row === null || !password_verify($current, (string) $row['password_hash'])) {
            UserPasswordTokens::audit((int) $me['user']['tenant_id'], $userId, 'my_password.failed', 'user_id=' . $userId, $ip);
            throw new LearnerAuthException('今のパスワードが正しくありません', 400);
        }
        $violation = PasswordPolicy::violation($new);
        if ($violation !== null) {
            throw new LearnerAuthException($violation, 400);
        }
        if (hash_equals($current, $new)) {
            throw new LearnerAuthException('今のパスワードと違うパスワードにしてください', 400);
        }
        $hash = password_hash($new, PASSWORD_DEFAULT);
        Db::tx(static function () use ($userId, $hash): void {
            Db::run('UPDATE users SET password_hash = ?, password_pending = 0, session_epoch = session_epoch + 1,
                            failed_count = 0, locked_until = NULL WHERE id = ?', [$hash, $userId]);
            UserPasswordTokens::revokeOpen($userId);
        });
        $epoch = Db::one('SELECT session_epoch FROM users WHERE id = ?', [$userId]);
        $_SESSION[self::SESSION_KEY]['epoch'] = (int) ($epoch['session_epoch'] ?? 0);
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        UserPasswordTokens::audit((int) $me['user']['tenant_id'], $userId, 'my_password.changed', 'user_id=' . $userId, $ip);
    }

    /**
     * パスワードを忘れた時。マイページを使える人にだけ再設定のメールを送る。
     * 応答は登録の有無で変えない(呼ぶ側は常に MSG_FORGOT_SENT を返す)。送ったかどうかを返す(テストと監査のため)。
     * @throws LearnerAuthException IP ごとの上限(登録の有無と関係なく数える)
     */
    public static function forgot(string $email, string $ip): bool
    {
        if ($ip !== '') {
            $row = Db::one(
                "SELECT COUNT(*) AS c FROM audit_log WHERE action = 'my_forgot' AND ip = ?
                   AND occurred_at >= datetime('now','localtime','-30 minutes')",
                [$ip]
            );
            if ((int) ($row['c'] ?? 0) >= self::FORGOT_IP_LIMIT) {
                throw new LearnerAuthException(self::MSG_RATE_LIMITED, 429);
            }
        }
        $sent = false;
        $user = filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? self::findByEmail($email) : null;
        $eligible = $user !== null && UserPasswordTokens::sendBlockReason($user) === null && self::targetFor($user) !== null;
        if ($eligible) {
            // 直前にこの画面から同じ人へ送っていれば送らない(管理者の招待・再送は数えない)
            $recent = Db::one(
                "SELECT 1 FROM audit_log WHERE action = 'my_forgot' AND user_id = ? AND detail = 'sent=1'
                   AND occurred_at >= datetime('now','localtime','-" . self::FORGOT_USER_INTERVAL_MINUTES . " minutes')",
                [(int) $user['id']]
            );
            if ($recent === null) {
                try {
                    UserPasswordTokens::issueAndSend($user, null, 'my');
                    $sent = true;
                } catch (PasswordTokenException $e) {
                    error_log('my forgot: ' . $e->reason);
                }
            }
        }
        UserPasswordTokens::audit($user !== null && $user['tenant_id'] !== null ? (int) $user['tenant_id'] : null,
            $user !== null ? (int) $user['id'] : null, 'my_forgot', 'sent=' . ($sent ? 1 : 0), $ip);
        return $sent;
    }
}
