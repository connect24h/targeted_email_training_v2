<?php
/**
 * 受講期間の終了時の集計通知(段D の D5、G63)。db/edu_delivery_summary.php(CLI)から呼ぶ。
 *
 * - テナントごとの設定(edu_summary_settings)で有効にし、社内の担当者のアドレスを登録した組織だけが対象。既定は切(行がない)。
 * - 期限(edu_deliveries.deadline)が過ぎた、開始済みの配信1件につき1回だけ送る。送る前に台帳の行を作り、二重送信を防ぐ。
 * - 有効にした日時より前に期限を過ぎた配信は送らない(有効にした時に、過去の配信の分がまとめて届かないように)。
 * - 本文は人数と率だけ。受講者の名前やアドレスは入れない。
 * - 数え方は教育レポート(api/edu_report.php)の配信一覧と同じ: テスト用と削除済みの対象者を除き、
 *   合格は最新の提出の点数が合格点以上、期限内合格は期限までに完了した合格(合格点のない配信は合格の行を出さない)。
 */
declare(strict_types=1);

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/EduMailer.php';
require_once __DIR__ . '/NotificationTemplates.php';
require_once __DIR__ . '/TenantStatus.php';

final class EduDeliverySummaryException extends RuntimeException
{
}

final class EduDeliverySummary
{
    public const MAX_RECIPIENTS = 10;

    /** 教育レポートの全社の数字と同じ、数える対象者の条件(対象者の別名 t)。 */
    private const REAL_TARGET_SQL = "t.is_test = 0 AND t.status = 'active'";

    /**
     * 期限を過ぎた配信の集計を、有効にしたテナントの担当者へ送る。冪等。
     * @return array{tenants:int, deliveries:int, sent:int, failed:int}
     */
    public static function run(?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo'));
        $current = $now->format('Y-m-d H:i:s');
        $result = ['tenants' => 0, 'deliveries' => 0, 'sent' => 0, 'failed' => 0];
        $settings = Db::all('SELECT * FROM edu_summary_settings s WHERE s.enabled = 1 AND s.enabled_at IS NOT NULL AND '
            . TenantStatus::operationalSql('s.tenant_id') . ' ORDER BY s.tenant_id');
        foreach ($settings as $setting) {
            $recipients = self::decodeRecipients((string) $setting['recipients']);
            if ($recipients === []) {
                continue;
            }
            $result['tenants']++;
            foreach (self::dueDeliveries((int) $setting['tenant_id'], (string) $setting['enabled_at'], $current) as $delivery) {
                $r = self::sendOne($delivery, $recipients);
                if ($r === null) {
                    continue;
                }
                $result['deliveries']++;
                $result['sent'] += $r['sent'];
                $result['failed'] += $r['failed'];
            }
        }
        return $result;
    }

    public static function summaryLine(array $r): string
    {
        return sprintf('edu_delivery_summary: tenants=%d deliveries=%d sent=%d failed=%d',
            $r['tenants'], $r['deliveries'], $r['sent'], $r['failed']);
    }

    /**
     * 配信1件の人数と率(教育レポートの配信一覧と同じ数え方)。
     * @return array{assigned:int, completed:int, completion_rate:float, has_pass_score:bool,
     *   passed:?int, pass_rate:?float, on_time_passed:?int, on_time_pass_rate:?float}
     */
    public static function counts(int $tenantId, int $deliveryId): array
    {
        $rows = Db::all(
            'SELECT a.status, a.completed_at, a.token_expiry, d.deadline, d.pass_score, r.percentage
             FROM edu_assignments a
             INNER JOIN edu_deliveries d ON d.id = a.delivery_id AND d.tenant_id = a.tenant_id
             INNER JOIN targets t ON t.id = a.target_id AND t.tenant_id = a.tenant_id
             LEFT JOIN edu_responses r ON r.assignment_id = a.id
             WHERE a.tenant_id = ? AND a.delivery_id = ? AND ' . self::REAL_TARGET_SQL,
            [$tenantId, $deliveryId]
        );
        $pass = Db::one('SELECT pass_score FROM edu_deliveries WHERE id = ? AND tenant_id = ?', [$deliveryId, $tenantId]);
        $hasPassScore = $pass !== null && $pass['pass_score'] !== null;
        $assigned = count($rows);
        $completed = $passed = $onTime = 0;
        foreach ($rows as $row) {
            $completed += (string) $row['status'] === 'completed' ? 1 : 0;
            $judge = self::judge($row);
            $passed += $judge['passed'] ? 1 : 0;
            $onTime += $judge['on_time'] ? 1 : 0;
        }
        return [
            'assigned' => $assigned,
            'completed' => $completed,
            'completion_rate' => self::rate($completed, $assigned),
            'has_pass_score' => $hasPassScore,
            'passed' => $hasPassScore ? $passed : null,
            'pass_rate' => $hasPassScore ? self::rate($passed, $assigned) : null,
            'on_time_passed' => $hasPassScore ? $onTime : null,
            'on_time_pass_rate' => $hasPassScore ? self::rate($onTime, $assigned) : null,
        ];
    }

    // ---------------------------------------------------------------- 設定

    /** @return array{enabled:bool, recipients:list<string>, enabled_at:?string, updated_by:?string, updated_at:?string} */
    public static function setting(int $tenantId): array
    {
        $row = Db::one('SELECT * FROM edu_summary_settings WHERE tenant_id = ?', [$tenantId]);
        if ($row === null) {
            return ['enabled' => false, 'recipients' => [], 'enabled_at' => null, 'updated_by' => null, 'updated_at' => null];
        }
        return ['enabled' => (int) $row['enabled'] === 1, 'recipients' => self::decodeRecipients((string) $row['recipients']),
            'enabled_at' => $row['enabled_at'], 'updated_by' => $row['updated_by'], 'updated_at' => $row['updated_at']];
    }

    /**
     * 設定を保存する。有効にするには担当者のアドレスが1件以上要る。切から入にした時だけ enabled_at を今にする。
     * @param mixed $recipients 文字列の配列
     */
    public static function saveSetting(int $tenantId, bool $enabled, mixed $recipients, string $actor): array
    {
        $clean = self::validateRecipients($recipients);
        if ($enabled && $clean === []) {
            throw new EduDeliverySummaryException('有効にするには、送り先の担当者のアドレスを1件以上入れてください');
        }
        $before = self::setting($tenantId);
        $enabledAt = $enabled ? ($before['enabled'] ? $before['enabled_at'] : date('Y-m-d H:i:s')) : null;
        Db::run(
            "INSERT INTO edu_summary_settings (tenant_id, enabled, recipients, enabled_at, updated_by, updated_at)
             VALUES (?, ?, ?, ?, ?, datetime('now','localtime'))
             ON CONFLICT (tenant_id) DO UPDATE SET enabled = excluded.enabled, recipients = excluded.recipients,
                 enabled_at = excluded.enabled_at, updated_by = excluded.updated_by, updated_at = excluded.updated_at",
            [$tenantId, $enabled ? 1 : 0, json_encode($clean, JSON_UNESCAPED_UNICODE), $enabledAt, $actor]
        );
        return self::setting($tenantId);
    }

    /** 直近の送信の記録(画面に出す)。 */
    public static function history(int $tenantId, int $limit = 10): array
    {
        return Db::all(
            'SELECT s.delivery_id, d.title, d.deadline, s.status, s.recipients, s.sent, s.created_at, s.finished_at
             FROM edu_delivery_summaries s INNER JOIN edu_deliveries d ON d.id = s.delivery_id
             WHERE s.tenant_id = ? ORDER BY s.id DESC LIMIT ' . max(1, min(50, $limit)),
            [$tenantId]
        );
    }

    /** @return list<string> */
    public static function validateRecipients(mixed $recipients): array
    {
        if (!is_array($recipients)) {
            throw new EduDeliverySummaryException('送り先のアドレスの形式が正しくありません');
        }
        $out = [];
        foreach ($recipients as $email) {
            $email = is_string($email) ? trim($email) : '';
            if ($email === '') {
                continue;
            }
            if (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                throw new EduDeliverySummaryException('メールアドレスの形式が正しくありません: ' . mb_substr($email, 0, 100));
            }
            $out[strtolower($email)] = $email;
        }
        if (count($out) > self::MAX_RECIPIENTS) {
            throw new EduDeliverySummaryException('送り先は' . self::MAX_RECIPIENTS . '件までです');
        }
        return array_values($out);
    }

    // ---------------------------------------------------------------- 内部

    /** 期限を過ぎ(有効にした後に過ぎたもの)、まだ送っていない開始済みの配信。 */
    private static function dueDeliveries(int $tenantId, string $enabledAt, string $current): array
    {
        $rows = Db::all(
            "SELECT d.* FROM edu_deliveries d
             WHERE d.tenant_id = ? AND d.status IN ('running','done') AND d.deadline IS NOT NULL AND TRIM(d.deadline) <> ''
               AND NOT EXISTS (SELECT 1 FROM edu_delivery_summaries s WHERE s.delivery_id = d.id)
             ORDER BY d.id",
            [$tenantId]
        );
        return array_values(array_filter($rows, static function (array $d) use ($enabledAt, $current): bool {
            $deadline = self::normDeadline((string) $d['deadline']);
            return $deadline !== null && $deadline <= $current && $deadline >= $enabledAt;
        }));
    }

    /**
     * 台帳の行を先に作ってから送る(同時に2つ動いても、行を作れた方だけが送る)。
     * @param array<string,mixed> $delivery
     * @param list<string> $recipients
     * @return array{sent:int, failed:int}|null 別の実行が先に取った時は null
     */
    private static function sendOne(array $delivery, array $recipients): ?array
    {
        $tenantId = (int) $delivery['tenant_id'];
        $deliveryId = (int) $delivery['id'];
        $counts = self::counts($tenantId, $deliveryId);
        $claimed = Db::run('INSERT OR IGNORE INTO edu_delivery_summaries (tenant_id, delivery_id, recipients, counts) VALUES (?, ?, ?, ?)',
            [$tenantId, $deliveryId, count($recipients), json_encode($counts)]);
        if ($claimed !== 1) {
            return null;
        }
        $fmt = static fn(?float $v): string => $v === null ? '' : number_format($v, 1);
        $mail = NotificationTemplates::render($tenantId, 'edu_delivery_summary', [
            '配信名' => (string) $delivery['title'],
            '期限' => NotificationTemplates::formatDeadline((string) $delivery['deadline']),
            '対象数' => (string) $counts['assigned'],
            '完了数' => (string) $counts['completed'],
            '受講率' => $fmt($counts['completion_rate']),
            '合格数' => $counts['passed'] === null ? '' : (string) $counts['passed'],
            '合格率' => $fmt($counts['pass_rate']),
            '期限内合格数' => $counts['on_time_passed'] === null ? '' : (string) $counts['on_time_passed'],
            '期限内合格率' => $fmt($counts['on_time_pass_rate']),
        ]);
        $sent = 0;
        foreach ($recipients as $to) {
            $sent += EduMailer::send($to, $mail['subject'], $mail['body']) ? 1 : 0;
        }
        $failed = count($recipients) - $sent;
        Db::run("UPDATE edu_delivery_summaries SET status = ?, sent = ?, finished_at = datetime('now','localtime') WHERE delivery_id = ?",
            [$failed === 0 ? 'sent' : 'failed', $sent, $deliveryId]);
        Db::run('INSERT INTO audit_log (tenant_id, user_id, action, detail, ip) VALUES (?, NULL, ?, ?, ?)',
            [$tenantId, 'edu_delivery.summary', 'delivery_id=' . $deliveryId . ',recipients=' . count($recipients) . ',sent=' . $sent, 'cli']);
        return ['sent' => $sent, 'failed' => $failed];
    }

    /** 割当1件の合否と期限内合格(api/edu_report.php の edu_rep_judge と同じ決まり)。 */
    private static function judge(array $row): array
    {
        if ($row['pass_score'] === null) {
            return ['passed' => false, 'on_time' => false];
        }
        $deadline = self::normDeadline($row['deadline'] ?? null) ?? self::normDeadline($row['token_expiry'] ?? null);
        $completedAt = self::normDeadline($row['completed_at'] ?? null);
        $passed = $row['percentage'] !== null && (int) $row['percentage'] >= (int) $row['pass_score'];
        // 期限のない配信は、合格をすべて期限内として数える
        $onTime = $passed && ($deadline === null || ($completedAt !== null && $completedAt <= $deadline));
        return ['passed' => $passed, 'on_time' => $onTime];
    }

    /** 期限の値をそろえる(api/edu_report.php の edu_rep_norm_deadline と同じ。日付だけならその日の終わり)。 */
    private static function normDeadline(?string $v): ?string
    {
        $v = $v === null ? '' : trim(str_replace('T', ' ', $v));
        if ($v === '') {
            return null;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1) {
            return $v . ' 23:59:59';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $v) === 1) {
            return $v . ':00';
        }
        return $v;
    }

    private static function rate(int $count, int $denom): float
    {
        return $denom === 0 ? 0.0 : round($count / $denom * 100, 1);
    }

    /** @return list<string> */
    private static function decodeRecipients(string $json): array
    {
        $list = json_decode($json, true);
        return is_array($list) ? array_values(array_filter($list, 'is_string')) : [];
    }
}
