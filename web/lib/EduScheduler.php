<?php
/**
 * 教育の配信の自動の処理。db/edu_scheduler.php(CLI)から呼ぶ。冪等で、何度動かしても同じ結果になる。
 *
 *   1. 毎月の配信(F1): next_run_at が来た系列から、その回の配信を予約の状態で作る。
 *   2. 予約した配信の開始(F0): status='scheduled' かつ scheduled_at が来た配信を開始する。
 *      1. で作った回も同じ実行の中で開始する。締切を過ぎた配信は開始せず、監査ログに1回だけ記録する。
 *   3. 新入社員への出題(F5): triggered_by='new_target' の running の配信に、登録から N 日以内の対象者を入れる。
 *
 * 受講の案内メールは、配信の send_invites=1 のときだけ送る(既定は送らない)。
 * 監査ログは user_id を NULL にして、自動の処理であることが分かるようにする。
 */
declare(strict_types=1);

require_once __DIR__ . '/EduDeliveryLauncher.php';
require_once __DIR__ . '/EduDeliverySeries.php';
require_once __DIR__ . '/TenantStatus.php';

final class EduScheduler
{
    /**
     * @return array<string,int> 各処理の件数
     */
    public static function run(?DateTimeImmutable $now = null): array
    {
        $now = $now ?? new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo'));
        // 毎月の配信の回を先に作り、同じ実行の中で予約の開始まで進める(逆の順だと、作った回の開始が次の実行まで遅れる)
        $series = EduDeliverySeries::runDue($now);
        $launch = self::launchDue($now);
        $newcomers = self::enrollNewTargets($now);
        $launch['mail_sent'] += $newcomers['mail_sent'];
        return $launch + [
            'series_created' => $series['created'],
            'series_ended' => $series['ended'],
            'new_target_assigned' => $newcomers['assigned'],
        ];
    }

    /**
     * 新入社員の配信(triggered_by='new_target' かつ running)に、登録から N 日以内で
     * まだ割当のない対象者を入れる。EduAutoEnroll と同じく、割当は tx で作り、メールは tx の外で送る。
     *
     * @return array{assigned:int, mail_sent:int}
     */
    public static function enrollNewTargets(DateTimeImmutable $now): array
    {
        $result = ['assigned' => 0, 'mail_sent' => 0];
        $current = $now->format('Y-m-d H:i:s');
        // 停止中・削除済みのテナントの配信には入れない
        $rows = Db::all("SELECT * FROM edu_deliveries WHERE triggered_by = 'new_target' AND status = 'running' AND "
            . TenantStatus::operationalSql('tenant_id') . ' ORDER BY id');
        foreach ($rows as $delivery) {
            $id = (int) $delivery['id'];
            $tenantId = (int) $delivery['tenant_id'];
            $expiry = EduDeliveryLauncher::tokenExpiry($delivery);
            if ($expiry !== null && $expiry <= $current) {
                continue;
            }
            try {
                $targetIds = EduDeliveryLauncher::resolveTargets($delivery, $tenantId, $now);
            } catch (EduDeliveryError $e) {
                self::auditOnce($tenantId, 'edu_scheduler.new_target_failed', $id, 'reason=' . $e->getMessage());
                continue;
            }
            $tokens = Db::txImmediate(static fn(): array => EduDeliveryLauncher::assign($id, $tenantId, $targetIds, $expiry));
            if ($tokens === []) {
                continue;
            }
            $mailSent = (int) $delivery['send_invites'] === 1
                ? EduDeliveryLauncher::sendInvites($id, $tenantId, (string) $delivery['title'], $tokens)
                : 0;
            self::audit($tenantId, 'edu_scheduler.new_target_enroll', $id, 'assigned=' . count($tokens) . ',mail=' . $mailSent);
            $result['assigned'] += count($tokens);
            $result['mail_sent'] += $mailSent;
        }
        return $result;
    }

    /** 結果を1行にする(CLI の出力と timer のログ用)。 */
    public static function summaryLine(array $result): string
    {
        $parts = [];
        foreach ($result as $key => $value) {
            $parts[] = $key . '=' . $value;
        }
        return 'edu_scheduler: ' . implode(' ', $parts);
    }

    /**
     * 予約の日時が来た配信を開始する。
     *
     * @return array{launched:int, skipped_expired:int, failed:int, mail_sent:int}
     */
    public static function launchDue(DateTimeImmutable $now): array
    {
        $result = ['launched' => 0, 'skipped_expired' => 0, 'failed' => 0, 'mail_sent' => 0];
        $current = $now->format('Y-m-d H:i:s');
        // 停止中・削除済みのテナントの予約は開始しない(予約のまま残し、有効に戻すと次の実行で開始する)
        $rows = Db::all("SELECT * FROM edu_deliveries WHERE status = 'scheduled' AND scheduled_at IS NOT NULL AND "
            . TenantStatus::operationalSql('tenant_id') . ' ORDER BY id');
        foreach ($rows as $delivery) {
            // 画面から来た 'T' 区切りなど、保存の形が揃っていない行もあるので PHP で比べる。
            $scheduledAt = EduDeliveryLauncher::normalizeDateTime((string) $delivery['scheduled_at']);
            if ($scheduledAt === null || $scheduledAt > $current) {
                continue;
            }
            $id = (int) $delivery['id'];
            $tenantId = (int) $delivery['tenant_id'];
            $expiry = EduDeliveryLauncher::tokenExpiry($delivery);
            if ($expiry !== null && $expiry <= $current) {
                self::auditOnce($tenantId, 'edu_scheduler.skip_expired', $id, 'deadline=' . $delivery['deadline']);
                $result['skipped_expired']++;
                continue;
            }
            try {
                $launched = EduDeliveryLauncher::launch($delivery, $tenantId, $now);
            } catch (EduDeliveryError $e) {
                if ($e->getCode() === 409) {
                    // 同じ時に画面から開始された(先に開始した方が勝つ)。二重の開始を防いだ結果で、失敗ではない
                    self::auditOnce($tenantId, 'edu_scheduler.already_started', $id, 'reason=' . $e->getMessage());
                    continue;
                }
                // 対象者や設問がいない配信は予約のまま残し、理由を1回だけ記録する(直せば次の実行で開始される)。
                self::auditOnce($tenantId, 'edu_scheduler.launch_failed', $id, 'reason=' . $e->getMessage());
                $result['failed']++;
                continue;
            }
            self::audit($tenantId, 'edu_scheduler.launch', $id,
                'assigned=' . $launched['assigned'] . ',mail=' . $launched['mail_sent']);
            $result['launched']++;
            $result['mail_sent'] += $launched['mail_sent'];
        }
        return $result;
    }

    /** 自動の処理の監査ログ。user_id は NULL。detail は 'delivery_id=N,...' の形。 */
    public static function audit(int $tenantId, string $action, int $deliveryId, string $detail): void
    {
        Db::run(
            'INSERT INTO audit_log (tenant_id, user_id, action, detail, ip) VALUES (?, NULL, ?, ?, ?)',
            [$tenantId, $action, 'delivery_id=' . $deliveryId . ',' . $detail, '']
        );
    }

    /** 同じ配信の同じ記録が既にあれば足さない(実行のたびにログが増えないように)。 */
    private static function auditOnce(int $tenantId, string $action, int $deliveryId, string $detail): void
    {
        $exists = Db::one(
            'SELECT 1 FROM audit_log WHERE action = ? AND tenant_id = ? AND detail LIKE ?',
            [$action, $tenantId, 'delivery_id=' . $deliveryId . ',%']
        );
        if ($exists === null) {
            self::audit($tenantId, $action, $deliveryId, $detail);
        }
    }
}
