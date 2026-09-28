<?php
declare(strict_types=1);

require_once __DIR__ . '/TenantStatus.php';

final class IntegrationAuthException extends RuntimeException
{
    public function __construct(public readonly int $httpCode, string $message)
    {
        parent::__construct($message);
    }
}

final class IntegrationAuth
{
    /** @param array<string,string> $server @param array<string,string>|null $env */
    public static function tenantId(array $server, ?array $env = null): int
    {
        $token = self::env('TET2_AWARENESS_TOKEN', $env);
        $tenant = self::env('TET2_AWARENESS_TENANT_ID', $env);
        if (strlen($token) < 24 || !preg_match('/^[1-9][0-9]*$/', $tenant)) {
            throw new IntegrationAuthException(503, 'integration認証が未設定です');
        }

        $authorization = $server['HTTP_AUTHORIZATION'] ?? '';
        if (!preg_match('/^Bearer ([^\s]+)$/', $authorization, $matches)
            || !hash_equals($token, $matches[1])) {
            throw new IntegrationAuthException(401, 'integration認証に失敗しました');
        }
        // 停止中・削除済みのテナントには、連携からの登録も受け付けない
        if (!TenantStatus::isOperational((int) $tenant)) {
            throw new IntegrationAuthException(403, 'このテナントは利用が停止されています');
        }
        return (int) $tenant;
    }

    /** @param array<string,string>|null $env */
    private static function env(string $key, ?array $env): string
    {
        if ($env !== null) {
            return $env[$key] ?? '';
        }
        $value = getenv($key);
        return $value === false ? '' : $value;
    }
}
