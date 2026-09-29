<?php
/**
 * パスワードの禁止語(G44 の残り)。
 *
 * - よく使われるパスワードの短い一覧(COMMON)。12文字以上の決まりがあるので完全一致ではなく「含む」で拒む
 *   (例: Password2026!)。@→a、0→o などの置き換えも戻して比べる(例: P@ssw0rd2026!)
 * - 組織の語: テナント名、テナントの slug、メールのドメイン(最後のラベルと汎用の語を除く)。4文字未満の語は無視する
 * - テナントごとに足した禁止語(tenant_security_policies.banned_words、改行区切り。AdminSecurityPolicy が読む)
 *
 * 大文字と小文字は区別しない。拒む時のメッセージ(MESSAGE)には、どの語に当たったかを出さない(禁止語の一覧を漏らさない)。
 */
declare(strict_types=1);

require_once __DIR__ . '/Db.php';

final class PasswordDenyList
{
    public const MIN_TOKEN_LENGTH = 4;
    public const MAX_WORDS = 50;
    public const MAX_WORD_LENGTH = 64;
    public const MESSAGE = 'パスワードに、よく使われる語句や、組織名・ドメイン名などの推測されやすい語を含めないでください';

    /** よく使われるパスワード(漏えいした一覧の上位から、12文字以上でも含まれやすいもの)。短く保つ。 */
    private const COMMON = [
        'password', 'passwd', 'qwerty', 'asdfgh', 'zxcvbn', '1qaz2wsx', 'qazwsx', '123456', '654321', '111111', '000000',
        'abc123', 'abcdef', 'letmein', 'welcome', 'iloveyou', 'admin', 'changeme', 'default', 'monkey', 'dragon',
        'sunshine', 'princess', 'football', 'baseball', 'trustno1', 'master', 'login', 'guest', 'secret',
    ];

    /** メールのドメインのうち、組織を表さない汎用のラベル。 */
    private const GENERIC_DOMAIN_LABELS = ['www', 'mail', 'email', 'smtp', 'corp', 'group', 'info', 'mx'];

    /** 置き換え文字を戻す(小文字にした後に使う)。 */
    private const LEET = ['@' => 'a', '4' => 'a', '0' => 'o', '1' => 'i', '!' => 'i', '3' => 'e', '$' => 's', '5' => 's', '7' => 't'];

    /**
     * よく使われる語か、$words のどれかを含めば MESSAGE、含まなければ null。
     * @param list<string> $words 組織の語やテナントの禁止語(小文字でなくてよい)
     */
    public static function violation(string $password, array $words = []): ?string
    {
        $lower = mb_strtolower($password, 'UTF-8');
        $normalized = strtr($lower, self::LEET);
        foreach (array_merge(self::COMMON, $words) as $word) {
            $word = mb_strtolower(trim((string) $word), 'UTF-8');
            if (mb_strlen($word, 'UTF-8') < self::MIN_TOKEN_LENGTH) {
                continue;
            }
            if (str_contains($lower, $word) || str_contains($normalized, strtr($word, self::LEET))) {
                return self::MESSAGE;
            }
        }
        return null;
    }

    /**
     * 組織の語(テナント名、slug、メールのドメイン)。4文字未満の語は入れない。
     * @return list<string>
     */
    public static function organizationWords(?string $tenantName, ?string $tenantSlug, ?string $email): array
    {
        $words = [];
        foreach ([$tenantName, $tenantSlug] as $value) {
            $value = mb_strtolower(trim((string) $value), 'UTF-8');
            if ($value === '') {
                continue;
            }
            $words[] = (string) preg_replace('/\s+/u', '', $value);
            foreach (preg_split('/[\s\-_.・,、()（）]+/u', $value) ?: [] as $part) {
                $words[] = $part;
            }
        }
        $domain = self::emailDomain($email);
        if ($domain !== null) {
            $labels = explode('.', $domain);
            array_pop($labels); // 最後のラベル(jp、com、test など)は組織を表さない
            foreach ($labels as $label) {
                if (in_array($label, self::GENERIC_DOMAIN_LABELS, true)) {
                    continue;
                }
                $words[] = $label;
                foreach (explode('-', $label) as $part) {
                    $words[] = $part;
                }
            }
        }
        $words = array_filter($words, static fn(string $w): bool => mb_strlen($w, 'UTF-8') >= self::MIN_TOKEN_LENGTH
            && !in_array($w, self::GENERIC_DOMAIN_LABELS, true));
        return array_values(array_unique($words));
    }

    /**
     * 既存のテナントの組織の語。テナントがなければメールのドメインだけ。
     * @return list<string>
     */
    public static function tenantWords(?int $tenantId, ?string $email): array
    {
        $tenant = $tenantId === null ? null : Db::one('SELECT name, slug FROM tenants WHERE id = ?', [$tenantId]);
        return self::organizationWords($tenant['name'] ?? null, $tenant['slug'] ?? null, $email);
    }

    /**
     * 受講者(マイページ)のパスワード: よく使われる語と組織の語だけ(テナントが足した禁止語は管理画面のユーザ向け)。
     */
    public static function learnerViolation(string $password, ?int $tenantId, ?string $email): ?string
    {
        return self::violation($password, self::tenantWords($tenantId, $email));
    }

    /**
     * 画面で入力された禁止語(改行区切り)を検証して並べ直す。大文字と小文字を区別せずに重複を除く。
     * @return list<string>
     * @throws InvalidArgumentException 語の長さ、数、使えない文字
     */
    public static function parseCustom(string $text): array
    {
        $words = [];
        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $line => $word) {
            $word = trim($word);
            if ($word === '') {
                continue;
            }
            if (preg_match('/[\x00-\x1F\x7F]/', $word) === 1) {
                throw new InvalidArgumentException(($line + 1) . '行目の禁止語に使えない文字が含まれています');
            }
            $length = mb_strlen($word, 'UTF-8');
            if ($length < self::MIN_TOKEN_LENGTH || $length > self::MAX_WORD_LENGTH) {
                throw new InvalidArgumentException(($line + 1) . '行目: 禁止語は' . self::MIN_TOKEN_LENGTH . '〜' . self::MAX_WORD_LENGTH . '文字にしてください');
            }
            $words[mb_strtolower($word, 'UTF-8')] ??= $word;
        }
        if (count($words) > self::MAX_WORDS) {
            throw new InvalidArgumentException('禁止語は' . self::MAX_WORDS . '語までにしてください');
        }
        return array_values($words);
    }

    /** @return list<string> 保存した値(改行区切り)を語の一覧にする。 */
    public static function decode(?string $stored): array
    {
        if ($stored === null || trim($stored) === '') {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode("\n", $stored)), static fn(string $w): bool => $w !== ''));
    }

    private static function emailDomain(?string $email): ?string
    {
        if ($email === null || !str_contains($email, '@')) {
            return null;
        }
        $domain = strtolower(trim(substr($email, strrpos($email, '@') + 1)));
        return $domain === '' ? null : $domain;
    }
}
