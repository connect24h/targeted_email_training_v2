<?php
/**
 * TOTP(RFC 6238)。HMAC-SHA1、30秒、6桁。認証アプリ(Google Authenticator、Microsoft Authenticator など)の既定に合わせる。
 * 外部のライブラリは使わない(RFC 4226 の動的切り捨てと RFC 4648 の base32 だけで足りる)。
 */
declare(strict_types=1);

final class Totp
{
    public const PERIOD = 30;
    public const DIGITS = 6;
    /** 前後に許す時刻窓の数(端末の時計のずれ)。±1 = ±30秒。 */
    public const DRIFT = 1;
    private const BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** 新しい秘密鍵(160ビット = RFC 4226 の推奨の長さ)を base32 で返す。 */
    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    /** その時刻の時刻窓の番号(1970年からの30秒ごと)。 */
    public static function stepAt(int $unixTime): int
    {
        return intdiv($unixTime, self::PERIOD);
    }

    /** 時刻窓 $step の6桁のコード。 */
    public static function codeAt(string $base32Secret, int $step): string
    {
        $key = self::base32Decode($base32Secret);
        $hash = hash_hmac('sha1', pack('J', $step), $key, true);
        $offset = ord($hash[19]) & 0x0f;
        $binary = ((ord($hash[$offset]) & 0x7f) << 24) | (ord($hash[$offset + 1]) << 16)
            | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);
        return str_pad((string) ($binary % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * コードが合えば、その時刻窓の番号を返す。合わなければ null。
     * $lastStep 以前の時刻窓は受け付けない(同じコードの使い回しと、古いコードの再送を拒むため)。
     */
    public static function verify(string $base32Secret, string $code, int $unixTime, ?int $lastStep): ?int
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if (preg_match('/^\d{' . self::DIGITS . '}$/D', $code) !== 1) {
            return null;
        }
        $now = self::stepAt($unixTime);
        $matched = null;
        // 見つかっても最後まで回し、合った位置で処理の時間が変わらないようにする
        for ($step = $now - self::DRIFT; $step <= $now + self::DRIFT; $step++) {
            if (hash_equals(self::codeAt($base32Secret, $step), $code) && $matched === null) {
                $matched = $step;
            }
        }
        if ($matched === null || ($lastStep !== null && $matched <= $lastStep)) {
            return null;
        }
        return $matched;
    }

    /** 認証アプリに登録する URI(otpauth://totp/...)。QR コードにすればそのまま読める。 */
    public static function uri(string $base32Secret, string $account, string $issuer): string
    {
        $label = rawurlencode($issuer) . ':' . rawurlencode($account);
        return 'otpauth://totp/' . $label . '?' . http_build_query([
            'secret' => $base32Secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => self::DIGITS,
            'period' => self::PERIOD,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public static function base32Encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::BASE32[bindec(str_pad($chunk, 5, '0'))];
        }
        return $out;
    }

    public static function base32Decode(string $base32): string
    {
        $base32 = strtoupper(rtrim(str_replace([' ', '-'], '', $base32), '='));
        $bits = '';
        foreach (str_split($base32) as $char) {
            $value = strpos(self::BASE32, $char);
            if ($value === false) {
                throw new InvalidArgumentException('秘密鍵の形が正しくありません');
            }
            $bits .= str_pad(decbin($value), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }
        return $out;
    }
}
