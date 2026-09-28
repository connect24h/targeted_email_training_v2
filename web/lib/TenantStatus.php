<?php
/**
 * テナントの状態(有効、停止、削除済み)の判定を1か所にまとめる。
 *
 * - 有効(active)のテナントだけが「動く」。停止(suspended)と削除済み(deleted)のテナントは、
 *   ログイン、ログイン中のセッション、自動の処理、送信の操作のすべてで止める。
 * - superadmin は tenant_id を持たないことがあり、停止中のテナントを切り替えて閲覧できる(削除と復元のため)。
 *   ただし送信の操作は superadmin でも止める(sendBlockReason)。
 * - 送信 worker(bin/tet2-worker.py)が処理中の送信は止めない。止めるのは既存の緊急停止(stop_sending.flag)。
 */
declare(strict_types=1);

require_once __DIR__ . '/Db.php';

final class TenantStatus
{
    public const ACTIVE = 'active';
    public const SUSPENDED = 'suspended';
    public const DELETED = 'deleted';

    /** 論理削除から完全削除できるようになるまでの日数。 */
    public const RETENTION_DAYS = 90;

    /** 契約の終了日がこの日数以内なら一覧に印を出す。 */
    public const CONTRACT_WARN_DAYS = 30;

    public const LOGIN_BLOCKED_MESSAGE = 'ご所属の組織（テナント）は利用が停止されているため、ログインできません。システム管理者にお問い合わせください';
    public const SESSION_BLOCKED_MESSAGE = 'ご所属の組織（テナント）の利用が停止されたため、ログアウトしました。システム管理者にお問い合わせください';

    /** SQL の IN 句に埋める、動いているテナントの副問合せ。列名は呼び出し側の固定値だけを渡す。 */
    public static function operationalSql(string $column): string
    {
        if (preg_match('/^[a-z_]+(\.[a-z_]+)?$/', $column) !== 1) {
            throw new InvalidArgumentException('列名が不正です');
        }
        return $column . " IN (SELECT id FROM tenants WHERE status = 'active')";
    }

    public static function status(int $tenantId): ?string
    {
        $row = Db::one('SELECT status FROM tenants WHERE id = ?', [$tenantId]);
        return $row === null ? null : (string) $row['status'];
    }

    public static function isOperational(int $tenantId): bool
    {
        return self::status($tenantId) === self::ACTIVE;
    }

    /**
     * ログイン中のユーザ(または ログインしようとするユーザ)が使い続けてよいか。
     * superadmin は所属テナントの状態によらず通す。所属テナントのない superadmin 以外のユーザは通さない。
     */
    public static function userAllowed(?int $tenantId, string $role): bool
    {
        if ($role === 'superadmin') {
            return true;
        }
        return $tenantId !== null && self::isOperational($tenantId);
    }

    /** 送信の操作(キャンペーンの開始・再開、教育の配信の開始・催促、アンケートの送信)を止める理由。動いているなら null。 */
    public static function sendBlockReason(int $tenantId): ?string
    {
        $status = self::status($tenantId);
        if ($status === self::ACTIVE) {
            return null;
        }
        if ($status === self::DELETED) {
            return '削除済みのテナントでは送信の操作はできません';
        }
        if ($status === self::SUSPENDED) {
            return '停止中のテナントでは送信の操作はできません。テナントを有効にしてから操作してください';
        }
        return 'テナントが見つかりません';
    }

    /** テナントのファイルの置き場の親。テストと E2E では TET2_DATA_ROOT で一時ディレクトリに差し替える。 */
    public static function dataRoot(): string
    {
        $env = getenv('TET2_DATA_ROOT');
        return is_string($env) && $env !== '' ? rtrim($env, '/') : '/opt/training/tet2-data';
    }

    public static function isValidSlug(string $slug): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9-]{1,30}$/D', $slug) === 1 && $slug !== '_deleted';
    }

    /**
     * 削除・完全削除を止める、実行中・予約中の送信の予定。なければ空の配列。
     * @return list<string>
     */
    public static function activeWorkBlockers(int $tenantId): array
    {
        $blockers = [];
        $campaigns = (int) (Db::one(
            "SELECT COUNT(*) AS c FROM campaigns WHERE tenant_id = ? AND status IN ('running','scheduled')",
            [$tenantId]
        )['c'] ?? 0);
        if ($campaigns > 0) {
            $blockers[] = '実行中・予約中のキャンペーンが ' . $campaigns . ' 件あります。停止してから操作してください';
        }
        $batches = (int) (Db::one(
            "SELECT COUNT(*) AS c FROM send_schedule s JOIN campaigns c ON c.id = s.campaign_id
             WHERE c.tenant_id = ? AND s.status IN ('queued','claimed','running')",
            [$tenantId]
        )['c'] ?? 0);
        if ($batches > 0) {
            $blockers[] = '送信待ち・送信中のバッチが ' . $batches . ' 件あります。キャンペーンを停止してから操作してください';
        }
        $edu = (int) (Db::one(
            "SELECT COUNT(*) AS c FROM edu_deliveries WHERE tenant_id = ? AND status IN ('running','scheduled')",
            [$tenantId]
        )['c'] ?? 0);
        if ($edu > 0) {
            $blockers[] = '実行中・予約中の教育の配信が ' . $edu . ' 件あります。終了してから操作してください';
        }
        return $blockers;
    }

    /** 上限と比べる対象者数(有効で、検証用を除く)。 */
    public static function countedTargets(int $tenantId): int
    {
        return (int) (Db::one(
            "SELECT COUNT(*) AS c FROM targets WHERE tenant_id = ? AND status = 'active' AND is_test = 0",
            [$tenantId]
        )['c'] ?? 0);
    }

    /** 対象者数がテナントの上限を超えていれば警告の文(拒否はしない)。上限がないか超えていなければ null。 */
    public static function targetLimitWarning(int $tenantId): ?string
    {
        $row = Db::one('SELECT target_limit FROM tenants WHERE id = ?', [$tenantId]);
        $limit = $row['target_limit'] ?? null;
        if ($limit === null || (int) $limit < 1) {
            return null;
        }
        $count = self::countedTargets($tenantId);
        if ($count <= (int) $limit) {
            return null;
        }
        return '対象者数(' . $count . '人)がテナントの上限(' . (int) $limit . '人)を超えています。登録はしましたが、契約の上限をご確認ください';
    }

    /** 契約の終了日までの日数(過ぎていれば負)。日付がなければ null。 */
    public static function contractDaysLeft(?string $endDate, ?DateTimeImmutable $today = null): ?int
    {
        if ($endDate === null || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $endDate) !== 1) {
            return null;
        }
        $end = DateTimeImmutable::createFromFormat('!Y-m-d', $endDate, new DateTimeZone('Asia/Tokyo'));
        if ($end === false) {
            return null;
        }
        $today ??= self::today();
        return (int) $today->diff($end)->format('%r%a');
    }

    public static function today(): DateTimeImmutable
    {
        return new DateTimeImmutable('today', new DateTimeZone('Asia/Tokyo'));
    }

    /** 削除日時から、完全削除ができるようになる日時。 */
    public static function purgeAvailableAt(string $deletedAt): ?DateTimeImmutable
    {
        try {
            $deleted = new DateTimeImmutable($deletedAt, new DateTimeZone('Asia/Tokyo'));
        } catch (Exception) {
            return null;
        }
        return $deleted->modify('+' . self::RETENTION_DAYS . ' days');
    }

    /** 保持期間の残り日数(切り上げ)。過ぎていれば 0。 */
    public static function retentionDaysLeft(?string $deletedAt, ?DateTimeImmutable $now = null): ?int
    {
        if ($deletedAt === null || $deletedAt === '') {
            return null;
        }
        $available = self::purgeAvailableAt($deletedAt);
        if ($available === null) {
            return null;
        }
        $now ??= new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo'));
        $seconds = $available->getTimestamp() - $now->getTimestamp();
        return $seconds <= 0 ? 0 : (int) ceil($seconds / 86400);
    }
}
