<?php
declare(strict_types=1);

require_once __DIR__ . '/Db.php';

/** 訓練で入力された本文を、通常のイベント・ログから隔離して保管する。 */
final class CredentialVault
{
    private const MAX_CAPTURE_PER_TRACKING = 10;
    private const FIELD_SETS = [
        'box' => ['email', 'password'],
        'ms365' => ['email', 'password'],
        'digitalarts' => ['login_id'],
        'ms365_email' => ['email'],
    ];

    /** @param array<string, mixed> $entered */
    public static function record(string $trackingId, string $authType, array $entered): int
    {
        if (!preg_match('/^[0-9]{10}$/D', $trackingId) || !isset(self::FIELD_SETS[$authType])) {
            throw new DomainException('訓練識別子または画面種別が不正です');
        }
        $fields = self::validateFields($authType, $entered);
        $key = self::loadKey();
        return Db::txImmediate(static function () use ($trackingId, $authType, $fields, $key): int {
            $ref = Db::one(
                'SELECT c.id AS campaign_id, c.tenant_id, c.credential_capture_approval_ref, ct.auth_flag
                   FROM campaign_targets ct JOIN campaigns c ON c.id = ct.campaign_id
                  WHERE ct.tracking_id=? AND c.deleted_at IS NULL AND c.closed_at IS NULL', [$trackingId]
            );
            if ($ref === null || trim((string) $ref['credential_capture_approval_ref']) === '') {
                throw new DomainException('本文収集が承認された訓練ではありません');
            }
            $expectedType = [1 => 'box', 2 => 'ms365', 3 => 'digitalarts', 4 => 'ms365_email'][(int) $ref['auth_flag']] ?? null;
            if ($expectedType !== $authType) {
                throw new DomainException('配信画面と入力種別が一致しません');
            }
            $tenantId = (int) $ref['tenant_id'];
            $campaignId = (int) $ref['campaign_id'];
            $count = Db::one('SELECT COUNT(*) AS n FROM credential_captures WHERE tracking_id=?', [$trackingId]);
            if ((int) ($count['n'] ?? 0) >= self::MAX_CAPTURE_PER_TRACKING) {
                throw new DomainException('入力回数の上限に達しました');
            }
            $payload = json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
            $aad = self::aad($tenantId, $campaignId, $trackingId, $authType);
            $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($payload, $aad, $nonce, $key);
            $id = Db::insert(
                'INSERT INTO credential_captures (tenant_id, campaign_id, tracking_id, auth_type, nonce, ciphertext)
                 VALUES (?,?,?,?,?,?)',
                [$tenantId, $campaignId, $trackingId, $authType, base64_encode($nonce), base64_encode($ciphertext)]
            );
            Db::run(
                "INSERT OR IGNORE INTO events (tenant_id, campaign_id, tracking_id, event_type, auth_variant, occurred_at, source, raw)
                 VALUES (?, ?, ?, 'auth', ?, datetime('now','localtime'), 'credential_capture', NULL)",
                [$tenantId, $campaignId, $trackingId, $authType]
            );
            return $id;
        });
    }

    /** @return array<string, string>|null */
    public static function reveal(int $id, int $tenantId, int $campaignId): ?array
    {
        $row = Db::one(
            'SELECT v.tracking_id, v.auth_type, v.nonce, v.ciphertext
               FROM credential_captures v JOIN campaigns c ON c.id=v.campaign_id AND c.tenant_id=v.tenant_id
              WHERE v.id=? AND v.tenant_id=? AND v.campaign_id=? AND c.deleted_at IS NULL AND c.closed_at IS NULL',
            [$id, $tenantId, $campaignId]
        );
        if ($row === null) { return null; }
        $key = self::loadKey();
        $nonce = base64_decode((string) $row['nonce'], true);
        $ciphertext = base64_decode((string) $row['ciphertext'], true);
        if ($nonce === false || $ciphertext === false) {
            throw new RuntimeException('保存済み本文の整合性を検証できません');
        }
        $aad = self::aad($tenantId, $campaignId, (string) $row['tracking_id'], (string) $row['auth_type']);
        $json = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($ciphertext, $aad, $nonce, $key);
        if ($json === false) { throw new RuntimeException('保存済み本文の整合性を検証できません'); }
        $fields = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($fields)) { throw new RuntimeException('保存済み本文の形式が不正です'); }
        return $fields;
    }

    public static function purge(int $campaignId, int $tenantId): int
    {
        return Db::run('DELETE FROM credential_captures WHERE campaign_id=? AND tenant_id=?', [$campaignId, $tenantId]);
    }

    /** @param array<string, mixed> $entered @return array<string, string> */
    private static function validateFields(string $authType, array $entered): array
    {
        $required = self::FIELD_SETS[$authType];
        if (array_keys($entered) !== $required) { throw new DomainException('入力項目が画面種別と一致しません'); }
        $fields = [];
        foreach ($required as $name) {
            $value = $entered[$name];
            if (!is_string($value) || $value === '' || strlen($value) > 512) {
                throw new DomainException('入力値の形式または長さが不正です');
            }
            $fields[$name] = $value;
        }
        return $fields;
    }

    private static function aad(int $tenantId, int $campaignId, string $trackingId, string $authType): string
    {
        return "tet2-capture-v1:{$tenantId}:{$campaignId}:{$trackingId}:{$authType}";
    }

    private static function loadKey(): string
    {
        $path = getenv('TET2_CAPTURE_KEY_FILE');
        if ($path === false || !str_starts_with($path, '/') || is_link($path) || !is_file($path)) {
            throw new RuntimeException('本文保存用の暗号鍵が使用できません');
        }
        $resolved = realpath($path);
        $webRoot = realpath('/var/www/html');
        if ($resolved === false || ($webRoot !== false && str_starts_with($resolved, $webRoot . '/'))
            || ((fileperms($resolved) & 0027) !== 0)) {
            throw new RuntimeException('本文保存用の暗号鍵が使用できません');
        }
        $key = file_get_contents($resolved);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
            throw new RuntimeException('本文保存用の暗号鍵が使用できません');
        }
        return $key;
    }
}
