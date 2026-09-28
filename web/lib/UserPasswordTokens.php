<?php
/**
 * 管理画面ユーザの招待とパスワード再設定のトークン(A1)。
 *
 * - トークンは random_bytes(32) の16進(64文字)。平文はメールの URL にだけ入れ、DB には sha256 のハッシュだけを保存する。
 * - 有効期限は72時間。1回使うと無効。同じユーザに新しいトークンを出すと、古いトークンは無効にする。
 * - パスワードを設定すると、そのユーザのほかのトークンを無効にし、session_epoch を増やして既存のセッションを切る
 *   (bootstrap.php の current_user() がログイン時の値と比べる)。
 * - 停止中のユーザ、停止中・削除済みのテナントのユーザには送らず、設定もさせない(TenantStatus)。
 * - パスワード設定の API はログイン前に使うので CSRF が使えない。トークンそのものを本人確認にし、
 *   正しくないトークンの試行を IP ごとに数えて制限する(ログインの IP 制限と同じ、audit_log の集計)。
 *
 * このページは後で受講者のマイページ(sat.cojp.online)でも使うため、セッションに頼らずトークンだけで動く。
 */
declare(strict_types=1);

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/EduMailer.php';
require_once __DIR__ . '/PasswordPolicy.php';
require_once __DIR__ . '/AdminSecurityPolicy.php';
require_once __DIR__ . '/TenantStatus.php';

final class PasswordTokenException extends RuntimeException
{
    public function __construct(string $message, public readonly int $httpCode, public readonly string $reason = 'error')
    {
        parent::__construct($message);
    }
}

final class UserPasswordTokens
{
    public const TTL_HOURS = 72;
    public const PURPOSES = ['invite', 'reset'];

    /** IP ごとの制限: 直近30分に正しくないトークンの試行がこの回数以上なら断る。 */
    public const RATE_LIMIT_FAILURES = 10;
    public const RATE_LIMIT_MINUTES = 30;

    /** 管理画面の URL の基点の既定。TET2_ADMIN_BASE_URL で上書きする。 */
    private const DEFAULT_ADMIN_BASE_URL = 'https://www.filesend.cojp.online/tet2';
    /** 受講者のサイト(マイページ)の URL の基点の既定。TET2_LEARNER_BASE_URL で上書きする。 */
    private const DEFAULT_LEARNER_BASE_URL = 'https://sat.cojp.online';

    /** リンクの行き先: 管理画面(admin)か、受講者のサイトのマイページ(my)。 */
    public const SITES = ['admin', 'my'];

    public const MSG_INVALID = 'このリンクは正しくありません。メールのリンクをもう一度お確かめいただくか、管理者に再送を依頼してください。';
    public const MSG_USED = 'このリンクは既に使われています。パスワードを忘れた場合は、管理者に再設定のメールを依頼してください。';
    public const MSG_REVOKED = 'このリンクは無効になりました（新しいメールが送られたか、パスワードが設定・変更されました）。最新のメールのリンクを開くか、管理者に依頼してください。';
    public const MSG_EXPIRED = 'このリンクは有効期限（72時間）が切れています。管理者に再送を依頼してください。';
    public const MSG_BLOCKED = 'このリンクは現在ご利用いただけません。管理者にお問い合わせください。';
    public const MSG_RATE_LIMITED = '試行が多すぎます。しばらく待ってからもう一度お試しください。';
    public const MSG_MAIL_FAILED = 'メールを送れませんでした（メールサーバーに接続できないか、宛先が受け付けられませんでした）。時間をおいてもう一度送ってください。';

    public static function adminBaseUrl(): string
    {
        $env = getenv('TET2_ADMIN_BASE_URL');
        return rtrim(is_string($env) && $env !== '' ? $env : self::DEFAULT_ADMIN_BASE_URL, '/');
    }

    public static function learnerBaseUrl(): string
    {
        $env = getenv('TET2_LEARNER_BASE_URL');
        return rtrim(is_string($env) && $env !== '' ? $env : self::DEFAULT_LEARNER_BASE_URL, '/');
    }

    /** パスワード設定のページの URL(トークンは URL にだけ平文で入る)。my は受講者のサイトのページで、設定の後にマイページへ案内する。 */
    public static function url(string $token, string $site = 'admin'): string
    {
        if ($site === 'my') {
            return self::learnerBaseUrl() . '/set_password.php?token=' . rawurlencode($token) . '&site=my';
        }
        return self::adminBaseUrl() . '/set_password.php?token=' . rawurlencode($token);
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function isWellFormed(string $token): bool
    {
        return preg_match('/^[0-9a-f]{64}$/D', $token) === 1;
    }

    /** パスワード未設定のユーザに入れておく、誰も知らないパスワードのハッシュ(password_hash は NOT NULL のため)。 */
    public static function unusableHash(): string
    {
        return password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
    }

    /** 招待・再設定を送ってよいユーザか。だめなら理由(日本語)。 */
    public static function sendBlockReason(array $user): ?string
    {
        if ((string) $user['status'] !== 'active') {
            return '停止中のユーザにはパスワード設定のメールを送れません。先に状態を有効にしてください';
        }
        $tenantId = $user['tenant_id'] !== null ? (int) $user['tenant_id'] : null;
        if (!TenantStatus::userAllowed($tenantId, (string) $user['role'])) {
            return '停止中・削除済みのテナントのユーザにはパスワード設定のメールを送れません';
        }
        return null;
    }

    /**
     * 新しいトークンを出す。同じユーザのまだ使える古いトークンは無効にする。平文のトークンを返す(保存はハッシュだけ)。
     */
    public static function issue(int $userId, string $purpose, ?int $createdBy): string
    {
        if (!in_array($purpose, self::PURPOSES, true)) {
            throw new InvalidArgumentException('purpose が不正です');
        }
        $token = bin2hex(random_bytes(32));
        Db::tx(static function () use ($userId, $purpose, $createdBy, $token): void {
            self::revokeOpen($userId);
            Db::run(
                "INSERT INTO user_password_tokens (user_id, token_hash, purpose, expires_at, created_by)
                 VALUES (?, ?, ?, datetime('now','localtime','+" . self::TTL_HOURS . " hours'), ?)",
                [$userId, self::hashToken($token), $purpose, $createdBy]
            );
        });
        return $token;
    }

    /** そのユーザのまだ使えるトークンをすべて無効にする。$exceptId は残す。 */
    public static function revokeOpen(int $userId, ?int $exceptId = null): int
    {
        return Db::run(
            "UPDATE user_password_tokens SET revoked_at = datetime('now','localtime')
             WHERE user_id = ? AND used_at IS NULL AND revoked_at IS NULL AND id != ?",
            [$userId, $exceptId ?? 0]
        );
    }

    /**
     * トークンを確かめ、使えるならトークンとユーザの行を返す。使えなければ理由つきの例外。
     * @return array{token: array<string,mixed>, user: array<string,mixed>}
     */
    public static function inspect(string $token): array
    {
        if (!self::isWellFormed($token)) {
            throw new PasswordTokenException(self::MSG_INVALID, 404, 'invalid');
        }
        $hash = self::hashToken($token);
        $row = Db::one(
            "SELECT t.id, t.user_id, t.token_hash, t.purpose, t.expires_at, t.used_at, t.revoked_at,
                    CASE WHEN t.expires_at > datetime('now','localtime') THEN 0 ELSE 1 END AS expired
             FROM user_password_tokens t WHERE t.token_hash = ?",
            [$hash]
        );
        // 引いた行のハッシュも定数時間で比べる(索引の検索に加えた念のための照合)。
        if ($row === null || !hash_equals((string) $row['token_hash'], $hash)) {
            throw new PasswordTokenException(self::MSG_INVALID, 404, 'invalid');
        }
        if ($row['used_at'] !== null) {
            throw new PasswordTokenException(self::MSG_USED, 410, 'used');
        }
        if ($row['revoked_at'] !== null) {
            throw new PasswordTokenException(self::MSG_REVOKED, 410, 'revoked');
        }
        if ((int) $row['expired'] === 1) {
            throw new PasswordTokenException(self::MSG_EXPIRED, 410, 'expired');
        }
        $user = Db::one('SELECT id, tenant_id, email, name, role, status, password_pending FROM users WHERE id = ?', [(int) $row['user_id']]);
        if ($user === null) {
            throw new PasswordTokenException(self::MSG_INVALID, 404, 'invalid');
        }
        if (self::sendBlockReason($user) !== null) {
            throw new PasswordTokenException(self::MSG_BLOCKED, 403, 'blocked');
        }
        return ['token' => $row, 'user' => $user];
    }

    /**
     * トークンでパスワードを設定する。成功ならユーザの行を返す。
     * トークンを使用済みにし、ほかのトークンを無効にし、session_epoch を増やして既存のセッションを切る。
     * @return array<string,mixed>
     */
    /**
     * パスワードの決まり。管理画面のユーザには所属テナントと全体の方針(段階1)を重ね、受講者(learner)は PasswordPolicy のまま。
     * @param array<string,mixed> $user
     */
    public static function policyViolation(array $user, string $password): ?string
    {
        if ((string) ($user['role'] ?? '') === 'learner') {
            return PasswordPolicy::violation($password);
        }
        return AdminSecurityPolicy::violation($password, $user['tenant_id'] !== null ? (int) $user['tenant_id'] : null);
    }

    /** 画面に出す決まりの説明(policyViolation と同じ区別)。 @param array<string,mixed> $user */
    public static function policyDescription(array $user): string
    {
        if ((string) ($user['role'] ?? '') === 'learner') {
            return PasswordPolicy::DESCRIPTION;
        }
        return AdminSecurityPolicy::description($user['tenant_id'] !== null ? (int) $user['tenant_id'] : null);
    }

    public static function consume(string $token, string $password, string $ip = ''): array
    {
        ['token' => $row, 'user' => $user] = self::inspect($token);
        $violation = self::policyViolation($user, $password);
        if ($violation !== null) {
            // 決まりに合わない時はトークンを使わない(もう一度入力できる)
            throw new PasswordTokenException($violation, 400, 'policy');
        }
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $tokenId = (int) $row['id'];
        $userId = (int) $user['id'];
        Db::txImmediate(static function () use ($tokenId, $userId, $hash): void {
            // 同時に2回送られても1回だけ通す(条件つきの更新で使用済みにできた方だけが進む)
            $claimed = Db::run(
                "UPDATE user_password_tokens SET used_at = datetime('now','localtime')
                 WHERE id = ? AND used_at IS NULL AND revoked_at IS NULL AND expires_at > datetime('now','localtime')",
                [$tokenId]
            );
            if ($claimed !== 1) {
                throw new PasswordTokenException(self::MSG_USED, 410, 'used');
            }
            Db::run(
                'UPDATE users SET password_hash = ?, password_pending = 0, session_epoch = session_epoch + 1,
                        failed_count = 0, locked_until = NULL
                 WHERE id = ?',
                [$hash, $userId]
            );
            self::revokeOpen($userId, $tokenId);
        });
        self::audit($user['tenant_id'] !== null ? (int) $user['tenant_id'] : null, $userId, 'user.password_set',
            'user_id=' . $userId . ',purpose=' . $row['purpose'], $ip);
        return $user;
    }

    /**
     * 招待・再設定のメールを1通送る。成功で true。本文に URL と期限を入れる(トークンはログに書かない)。
     */
    public static function sendMail(array $user, string $token, string $purpose, string $site = 'admin'): bool
    {
        $name = trim((string) ($user['name'] ?? ''));
        $expires = Db::one('SELECT expires_at FROM user_password_tokens WHERE token_hash = ?', [self::hashToken($token)]);
        $expiresAt = $expires !== null ? substr((string) $expires['expires_at'], 0, 16) : '';
        if ($site === 'my') {
            return self::sendLearnerMail($user, $token, $purpose, $name, $expiresAt);
        }
        if ($purpose === 'invite') {
            $subject = '【TET v2】管理画面のアカウントのパスワード設定のお願い';
            $lead = "TET v2（標的型メール訓練・教育の管理画面）のアカウントが作られました。\n"
                . "下記の URL を開き、パスワードを設定してください。\n";
        } else {
            $subject = '【TET v2】パスワード再設定のご案内';
            $lead = "TET v2（標的型メール訓練・教育の管理画面）のパスワードの再設定を、管理者が受け付けました。\n"
                . "下記の URL を開き、新しいパスワードを設定してください。\n";
        }
        $body = ($name !== '' ? $name . ' 様' : 'ご担当者 様') . "\n\n"
            . $lead . "\n"
            . self::url($token) . "\n\n"
            . 'ログインに使うメールアドレス: ' . (string) $user['email'] . "\n"
            . ($expiresAt !== '' ? 'リンクの有効期限: ' . $expiresAt . '（72時間。1回だけ使えます）' . "\n" : '')
            . 'パスワードの決まり: ' . PasswordPolicy::DESCRIPTION . "\n\n"
            . "お心当たりがない場合は、このメールを破棄してください。\n"
            . "※本メールは自動送信です。ご不明点は管理者へお問い合わせください。\n";
        return EduMailer::send((string) $user['email'], $subject, $body);
    }

    /** 受講者のマイページの招待・再設定のメール(リンクは受講者のサイトのパスワード設定のページ)。 */
    private static function sendLearnerMail(array $user, string $token, string $purpose, string $name, string $expiresAt): bool
    {
        if ($purpose === 'invite') {
            $subject = '【セキュリティ教育】マイページのパスワード設定のお願い';
            $lead = "セキュリティ教育の受講者のマイページをご用意しました。\n"
                . "マイページでは、受講する教育と回答するアンケート、ご自分の成績を確認でき、教育を受け直せます。\n"
                . "下記の URL を開き、パスワードを設定してください。\n";
        } else {
            $subject = '【セキュリティ教育】マイページのパスワード再設定のご案内';
            $lead = "セキュリティ教育の受講者のマイページのパスワードの再設定を受け付けました。\n"
                . "下記の URL を開き、新しいパスワードを設定してください。\n";
        }
        $shared = (string) ($user['role'] ?? '') !== 'learner'
            ? "※このパスワードは管理画面のパスワードと共通です（同じアカウントです）。\n" : '';
        $body = ($name !== '' ? $name . ' 様' : 'ご担当者 様') . "\n\n"
            . $lead . "\n"
            . self::url($token, 'my') . "\n\n"
            . 'ログインに使うメールアドレス: ' . (string) $user['email'] . "\n"
            . ($expiresAt !== '' ? 'リンクの有効期限: ' . $expiresAt . '（72時間。1回だけ使えます）' . "\n" : '')
            . 'パスワードの決まり: ' . PasswordPolicy::DESCRIPTION . "\n"
            . 'マイページ: ' . self::learnerBaseUrl() . "/my.php\n"
            . $shared . "\n"
            . "お心当たりがない場合は、このメールを破棄してください。\n"
            . "※本メールは自動送信です。ご不明点は社内の担当者へお問い合わせください。\n";
        return EduMailer::send((string) $user['email'], $subject, $body);
    }

    /**
     * 管理画面から、ユーザに招待・再設定のメールを送る(発行と送信を1つに)。
     * 送れなかった時は、出したトークンを無効にして例外(502)にする。
     * @return array{purpose: string, expires_at: string}
     */
    public static function issueAndSend(array $user, ?int $createdBy, string $site = 'admin'): array
    {
        $reason = self::sendBlockReason($user);
        if ($reason !== null) {
            throw new PasswordTokenException($reason, 409, 'blocked');
        }
        $purpose = (int) ($user['password_pending'] ?? 0) === 1 ? 'invite' : 'reset';
        $token = self::issue((int) $user['id'], $purpose, $createdBy);
        if (!self::sendMail($user, $token, $purpose, $site)) {
            self::revokeOpen((int) $user['id']);
            throw new PasswordTokenException(self::MSG_MAIL_FAILED, 502, 'mail');
        }
        $row = Db::one('SELECT expires_at FROM user_password_tokens WHERE token_hash = ?', [self::hashToken($token)]);
        return ['purpose' => $purpose, 'expires_at' => (string) ($row['expires_at'] ?? '')];
    }

    /** IP ごとの制限。直近30分の正しくないトークンの試行が上限に達していれば true。 */
    public static function ipRateLimited(string $ip): bool
    {
        if ($ip === '') {
            return false;
        }
        $row = Db::one(
            "SELECT COUNT(*) AS c FROM audit_log
             WHERE action = 'password_set.failed' AND ip = ?
               AND occurred_at >= datetime('now','localtime','-" . self::RATE_LIMIT_MINUTES . " minutes')",
            [$ip]
        );
        return $row !== null && (int) $row['c'] >= self::RATE_LIMIT_FAILURES;
    }

    /** 正しくないトークンの試行を記録する(トークンそのものは書かない)。 */
    public static function recordFailure(string $ip, string $reason): void
    {
        self::audit(null, null, 'password_set.failed', 'reason=' . $reason, $ip);
    }

    /** ログイン前の操作の監査ログ(bootstrap の audit() はセッションのユーザを使うため、ここでは直接書く)。 */
    public static function audit(?int $tenantId, ?int $userId, string $action, string $detail, string $ip): void
    {
        Db::run(
            'INSERT INTO audit_log (tenant_id, user_id, action, detail, ip) VALUES (?,?,?,?,?)',
            [$tenantId, $userId, $action, $detail, $ip]
        );
    }
}
