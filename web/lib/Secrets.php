<?php
declare(strict_types=1);

/**
 * Git 管理外の秘密情報（外部 API キー等）を ini ファイルから読む。
 *
 * 既定の置き場は /opt/training/tet2-data/secrets.ini（0640 root:www-data）。
 * セクション名.キー名で引く（例: virustotal.api_key）。ファイルや値が無ければ null。
 * 値をログや API 応答に出さないこと。
 */
final class Secrets
{
    private const DEFAULT_PATH = '/opt/training/tet2-data/secrets.ini';
    private static ?array $cache = null;

    public static function get(string $key): ?string
    {
        [$section, $name] = array_pad(explode('.', $key, 2), 2, null);
        if ($section === null || $name === null) {
            return null;
        }
        $value = self::load()[$section][$name] ?? null;
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        return $value === '' ? null : $value;
    }

    public static function path(): string
    {
        $path = getenv('TET2_SECRETS_FILE');
        return is_string($path) && $path !== '' ? $path : self::DEFAULT_PATH;
    }

    /** テストで差し替えた後にキャッシュを捨てる。 */
    public static function reset(): void
    {
        self::$cache = null;
    }

    private static function load(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $path = self::path();
        self::$cache = [];
        if (is_file($path) && is_readable($path)) {
            $parsed = @parse_ini_file($path, true, INI_SCANNER_RAW);
            if (is_array($parsed)) {
                self::$cache = $parsed;
            }
        }
        return self::$cache;
    }
}
