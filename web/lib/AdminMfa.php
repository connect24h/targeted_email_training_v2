<?php
/**
 * 管理画面ユーザの多要素認証(段階1、G43)。TOTP と1回限りの回復コード。
 *
 * - 秘密鍵は users.mfa_secret に暗号文で置く(XChaCha20-Poly1305、鍵は secrets.ini の [mfa] secret_key = 32バイトの base64)。
 *   AAD に利用者の id を入れ、ほかの利用者の行へ暗号文を写しても復号できないようにする。
 * - 登録は2段階: setup で秘密鍵を作って暗号文を保存し(mfa_enabled_at は NULL のまま)、enable で正しいコードを確かめてから有効にする。
 * - 同じ時刻窓とそれより前のコードは受け付けない(mfa_last_step を条件つきの UPDATE で進め、同時の2回も1回だけ通す)。
 * - 回復コードは10個。平文は enable と作り直しの応答で1回だけ返し、DB には sha256 だけを置く。
 *
 * 解除は本人(コードかパスワードを確かめた後)、組織管理者(自組織)、システム管理者、CLI(web/db/mfa_reset.php)だけ。
 * DB を読めるだけでは秘密鍵も回復コードも取り出せない。
 */
declare(strict_types=1);

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Secrets.php';
require_once __DIR__ . '/Totp.php';

final class AdminMfa
{
    public const ISSUER = 'TET v2';
    public const RECOVERY_CODE_COUNT = 10;
    private const SECRET_KEY_NAME = 'mfa.secret_key';
    private const RECOVERY_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    /** 有効にしているか(登録の途中は含めない)。 */
    public static function isEnabled(int $userId): bool
    {
        $row = Db::one('SELECT mfa_enabled_at FROM users WHERE id = ?', [$userId]);
        return $row !== null && $row['mfa_enabled_at'] !== null;
    }

    /** 暗号鍵が設定されているか(画面で「登録できない」理由を先に出すため)。 */
    public static function keyConfigured(): bool
    {
        try {
            self::loadKey();
            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    /**
     * 登録を始める。新しい秘密鍵を作って暗号文で保存し、画面に出す秘密鍵と URI を返す。
     * 既に有効なら DomainException。登録の途中でもう一度呼ぶと、秘密鍵を作り直す(前のものは使えなくなる)。
     * @return array{secret:string, otpauth_uri:string}
     */
    public static function beginEnrollment(int $userId, string $email): array
    {
        if (self::isEnabled($userId)) {
            throw new DomainException('多要素認証は既に有効です。作り直すには、いったん解除してください');
        }
        $secret = Totp::generateSecret();
        Db::run(
            'UPDATE users SET mfa_secret = ?, mfa_enabled_at = NULL, mfa_last_step = NULL WHERE id = ?',
            [self::encrypt($userId, $secret), $userId]
        );
        return ['secret' => $secret, 'otpauth_uri' => Totp::uri($secret, $email, self::ISSUER)];
    }

    /**
     * 登録の途中の秘密鍵でコードを確かめ、合えば有効にして回復コード(平文、1回だけ)を返す。合わなければ null。
     * @return list<string>|null
     */
    public static function completeEnrollment(int $userId, string $code, int $now): ?array
    {
        $row = Db::one('SELECT mfa_secret, mfa_enabled_at, mfa_last_step FROM users WHERE id = ?', [$userId]);
        if ($row === null || $row['mfa_secret'] === null) {
            throw new DomainException('登録を始めてからコードを入力してください');
        }
        if ($row['mfa_enabled_at'] !== null) {
            throw new DomainException('多要素認証は既に有効です');
        }
        $secret = self::decrypt($userId, (string) $row['mfa_secret']);
        $step = Totp::verify($secret, $code, $now, $row['mfa_last_step'] !== null ? (int) $row['mfa_last_step'] : null);
        if ($step === null) {
            return null;
        }
        return Db::txImmediate(static function () use ($userId, $step): array {
            $claimed = Db::run(
                "UPDATE users SET mfa_enabled_at = datetime('now','localtime'), mfa_last_step = ?
                 WHERE id = ? AND mfa_enabled_at IS NULL AND mfa_secret IS NOT NULL",
                [$step, $userId]
            );
            if ($claimed !== 1) {
                throw new DomainException('多要素認証は既に有効です');
            }
            return self::replaceRecoveryCodes($userId);
        });
    }

    /**
     * 有効な利用者の TOTP のコードを確かめる。合えば true で、時刻窓を進める(同じコードはもう通らない)。
     * 暗号鍵が使えない時は RuntimeException(回復コードは鍵なしで確かめられる)。
     */
    public static function verifyCode(int $userId, string $code, int $now): bool
    {
        $row = Db::one('SELECT mfa_secret, mfa_enabled_at, mfa_last_step FROM users WHERE id = ?', [$userId]);
        if ($row === null || $row['mfa_enabled_at'] === null || $row['mfa_secret'] === null) {
            return false;
        }
        $lastStep = $row['mfa_last_step'] !== null ? (int) $row['mfa_last_step'] : null;
        $step = Totp::verify(self::decrypt($userId, (string) $row['mfa_secret']), $code, $now, $lastStep);
        if ($step === null) {
            return false;
        }
        // 同時に同じコードが2回届いても、時刻窓を進められた方だけを通す
        return Db::run(
            'UPDATE users SET mfa_last_step = ? WHERE id = ? AND mfa_enabled_at IS NOT NULL AND (mfa_last_step IS NULL OR mfa_last_step < ?)',
            [$step, $userId, $step]
        ) === 1;
    }

    /** 回復コードを確かめ、合えば使用済みにして true。使用済みのコードは通らない。 */
    public static function useRecoveryCode(int $userId, string $code): bool
    {
        $normalized = self::normalizeRecoveryCode($code);
        if ($normalized === '' || !self::isEnabled($userId)) {
            return false;
        }
        return Db::run(
            "UPDATE user_mfa_recovery_codes SET used_at = datetime('now','localtime')
             WHERE user_id = ? AND code_hash = ? AND used_at IS NULL",
            [$userId, hash('sha256', $normalized)]
        ) === 1;
    }

    /** まだ使える回復コードの数。 */
    public static function remainingRecoveryCodes(int $userId): int
    {
        $row = Db::one('SELECT COUNT(*) AS c FROM user_mfa_recovery_codes WHERE user_id = ? AND used_at IS NULL', [$userId]);
        return (int) ($row['c'] ?? 0);
    }

    /** 回復コードを作り直す(前のものはすべて使えなくなる)。平文を1回だけ返す。 */
    public static function regenerateRecoveryCodes(int $userId): array
    {
        if (!self::isEnabled($userId)) {
            throw new DomainException('多要素認証が有効ではありません');
        }
        return Db::txImmediate(static fn (): array => self::replaceRecoveryCodes($userId));
    }

    /** 多要素認証を解除する(秘密鍵、時刻窓、回復コードを消す)。解除した(有効か登録の途中だった)なら true。 */
    public static function disable(int $userId): bool
    {
        return Db::txImmediate(static function () use ($userId): bool {
            $changed = Db::run(
                'UPDATE users SET mfa_secret = NULL, mfa_enabled_at = NULL, mfa_last_step = NULL
                 WHERE id = ? AND (mfa_secret IS NOT NULL OR mfa_enabled_at IS NOT NULL)',
                [$userId]
            );
            $codes = Db::run('DELETE FROM user_mfa_recovery_codes WHERE user_id = ?', [$userId]);
            return $changed > 0 || $codes > 0;
        });
    }

    /** 回復コードの表記の揺れ(小文字、区切りの -、空白)を除く。 */
    public static function normalizeRecoveryCode(string $code): string
    {
        $code = strtoupper(preg_replace('/[\s\-]+/', '', $code) ?? '');
        return preg_match('/^[' . self::RECOVERY_ALPHABET . ']{16}$/D', $code) === 1 ? $code : '';
    }

    /** @return list<string> */
    private static function replaceRecoveryCodes(int $userId): array
    {
        Db::run('DELETE FROM user_mfa_recovery_codes WHERE user_id = ?', [$userId]);
        $codes = [];
        while (count($codes) < self::RECOVERY_CODE_COUNT) {
            $raw = '';
            for ($i = 0; $i < 16; $i++) {
                $raw .= self::RECOVERY_ALPHABET[random_int(0, strlen(self::RECOVERY_ALPHABET) - 1)];
            }
            if (isset($codes[$raw])) {
                continue;
            }
            $codes[$raw] = implode('-', str_split($raw, 4));
            Db::run('INSERT INTO user_mfa_recovery_codes (user_id, code_hash) VALUES (?, ?)', [$userId, hash('sha256', $raw)]);
        }
        return array_values($codes);
    }

    private static function encrypt(int $userId, string $secret): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $cipher = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($secret, self::aad($userId), $nonce, self::loadKey());
        return 'v1:' . base64_encode($nonce) . ':' . base64_encode($cipher);
    }

    private static function decrypt(int $userId, string $stored): string
    {
        $parts = explode(':', $stored);
        $nonce = base64_decode($parts[1] ?? '', true);
        $cipher = base64_decode($parts[2] ?? '', true);
        if (count($parts) !== 3 || $parts[0] !== 'v1' || $nonce === false || $cipher === false
            || strlen($nonce) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES) {
            throw new RuntimeException('多要素認証の秘密鍵の形が正しくありません');
        }
        $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($cipher, self::aad($userId), $nonce, self::loadKey());
        if ($plain === false) {
            throw new RuntimeException('多要素認証の秘密鍵を復号できません');
        }
        return $plain;
    }

    private static function aad(int $userId): string
    {
        return 'tet2-mfa-v1:' . $userId;
    }

    private static function loadKey(): string
    {
        $encoded = Secrets::get(self::SECRET_KEY_NAME);
        $key = $encoded !== null ? base64_decode($encoded, true) : false;
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
            throw new RuntimeException('多要素認証の暗号鍵(secrets.ini の [mfa] secret_key)が設定されていません');
        }
        return $key;
    }
}
