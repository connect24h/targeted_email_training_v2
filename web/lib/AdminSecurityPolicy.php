<?php
/**
 * 管理画面のパスワードと多要素認証の方針(段階1、G44)。
 *
 * 行は tenant_security_policies。tenant_id NULL の1行が全テナント共通(システム管理者が決める)で、
 * テナントの行と両方あれば、項目ごとに厳しい方を使う。行がなければ PasswordPolicy の既定のまま。
 * PasswordPolicy(12文字以上、4種のうち3種以上)より弱くはできない。まず PasswordPolicy を通し、その上で厳しくするだけ。
 */
declare(strict_types=1);

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/PasswordPolicy.php';

final class AdminSecurityPolicy
{
    public const MAX_MIN_LENGTH = 64;

    /**
     * そのテナントの利用者に効く方針。$tenantId が null(システム管理者など)は共通の行だけ。
     * @return array{min_length:int, min_classes:int, require_mfa:bool}
     */
    public static function effective(?int $tenantId): array
    {
        $rows = Db::all(
            'SELECT min_length, min_classes, require_mfa FROM tenant_security_policies WHERE tenant_id IS NULL OR tenant_id = ?',
            [$tenantId ?? 0]
        );
        $policy = ['min_length' => PasswordPolicy::MIN_LENGTH, 'min_classes' => PasswordPolicy::MIN_CLASSES, 'require_mfa' => false];
        foreach ($rows as $row) {
            $policy['min_length'] = max($policy['min_length'], (int) $row['min_length']);
            $policy['min_classes'] = max($policy['min_classes'], (int) $row['min_classes']);
            $policy['require_mfa'] = $policy['require_mfa'] || (int) $row['require_mfa'] === 1;
        }
        return $policy;
    }

    /**
     * 1つの行(テナント、または $tenantId=null で共通)。行がなければ既定の値と exists=false。
     * @return array{tenant_id:?int, min_length:int, min_classes:int, require_mfa:bool, exists:bool, updated_at:?string}
     */
    public static function row(?int $tenantId): array
    {
        $row = $tenantId === null
            ? Db::one('SELECT * FROM tenant_security_policies WHERE tenant_id IS NULL')
            : Db::one('SELECT * FROM tenant_security_policies WHERE tenant_id = ?', [$tenantId]);
        return [
            'tenant_id' => $tenantId,
            'min_length' => $row !== null ? (int) $row['min_length'] : PasswordPolicy::MIN_LENGTH,
            'min_classes' => $row !== null ? (int) $row['min_classes'] : PasswordPolicy::MIN_CLASSES,
            'require_mfa' => $row !== null && (int) $row['require_mfa'] === 1,
            'exists' => $row !== null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    /** 1つの行を保存する。値が範囲の外なら InvalidArgumentException(PasswordPolicy より弱くはできない)。 */
    public static function save(?int $tenantId, int $minLength, int $minClasses, bool $requireMfa, int $actorId): void
    {
        if ($minLength < PasswordPolicy::MIN_LENGTH || $minLength > self::MAX_MIN_LENGTH) {
            throw new InvalidArgumentException('最小の文字数は' . PasswordPolicy::MIN_LENGTH . '〜' . self::MAX_MIN_LENGTH . 'にしてください');
        }
        if (!in_array($minClasses, [PasswordPolicy::MIN_CLASSES, 4], true)) {
            throw new InvalidArgumentException('文字の種類は3か4にしてください');
        }
        $values = [$minLength, $minClasses, $requireMfa ? 1 : 0, $actorId];
        if ($tenantId === null) {
            $updated = Db::run(
                "UPDATE tenant_security_policies SET min_length = ?, min_classes = ?, require_mfa = ?, updated_by = ?,
                        updated_at = datetime('now','localtime') WHERE tenant_id IS NULL",
                $values
            );
            if ($updated === 0) {
                Db::run('INSERT INTO tenant_security_policies (tenant_id, min_length, min_classes, require_mfa, updated_by) VALUES (NULL, ?, ?, ?, ?)', $values);
            }
            return;
        }
        Db::run(
            "INSERT INTO tenant_security_policies (tenant_id, min_length, min_classes, require_mfa, updated_by) VALUES (?, ?, ?, ?, ?)
             ON CONFLICT (tenant_id) WHERE tenant_id IS NOT NULL DO UPDATE SET min_length = excluded.min_length,
                 min_classes = excluded.min_classes, require_mfa = excluded.require_mfa, updated_by = excluded.updated_by,
                 updated_at = datetime('now','localtime')",
            [$tenantId, ...$values]
        );
    }

    /** そのテナントの利用者のパスワードとして通らない理由(日本語)。通れば null。 */
    public static function violation(string $password, ?int $tenantId): ?string
    {
        $base = PasswordPolicy::violation($password);
        if ($base !== null) {
            return $base;
        }
        $policy = self::effective($tenantId);
        if (mb_strlen($password, 'UTF-8') < $policy['min_length']) {
            return 'パスワードは' . $policy['min_length'] . '文字以上にしてください（組織の方針）';
        }
        if (PasswordPolicy::classCount($password) < $policy['min_classes']) {
            return 'パスワードには英大文字・英小文字・数字・記号をすべて含めてください（組織の方針）';
        }
        return null;
    }

    /** 画面に出す決まりの説明。 */
    public static function description(?int $tenantId): string
    {
        $policy = self::effective($tenantId);
        if ($policy['min_length'] === PasswordPolicy::MIN_LENGTH && $policy['min_classes'] === PasswordPolicy::MIN_CLASSES) {
            return PasswordPolicy::DESCRIPTION;
        }
        return $policy['min_length'] . '文字以上で、'
            . ($policy['min_classes'] >= 4 ? '英大文字・英小文字・数字・記号をすべて含めてください' : '英大文字・英小文字・数字・記号のうち3種類以上を含めてください');
    }
}
