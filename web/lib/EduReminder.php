<?php
/**
 * 未完了受講者への自動リマインド。
 * 全テナントの running 配信の未完了者(status assigned/started)のうち、
 * 前回リマインドから REMIND_INTERVAL_DAYS 日以上経過した者へ受講催促メールを送る。
 *
 * 冪等/スパム防止: edu_assignments.last_reminded_at を送信後に更新し、間隔未満は送らない。
 *   → 日次で回しても同じ人に毎日は送らない(既定3日おき)。
 *
 * deadline を過ぎた配信はリマインド対象外(締切後に催促しても無意味)。
 * deadline が NULL の配信は期限なしとして対象に含める。
 */
declare(strict_types=1);

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/EduMailer.php';
require_once __DIR__ . '/NotificationTemplates.php';
require_once __DIR__ . '/TenantStatus.php';

final class EduReminder
{
    /** 前回リマインドからこの日数以上空いていれば再送する。 */
    private const REMIND_INTERVAL_DAYS = 3;

    /**
     * @return array{targets:int, sent:int, failed:int, skipped_recent:int}
     */
    public static function run(): array
    {
        $intervalDays = self::intervalDays();

        // 対象: running配信 かつ 未完了 かつ (締切なし or 締切未到来) かつ
        //        (未リマインド or 前回から intervalDays 日以上経過)
        $rows = Db::all(
            "SELECT a.id AS assignment_id, a.access_token, a.last_reminded_at, a.tenant_id,
                    d.title, d.deadline, t.email, t.name
             FROM edu_assignments a
             INNER JOIN edu_deliveries d ON d.id = a.delivery_id
             INNER JOIN targets t ON t.id = a.target_id
             WHERE d.status = 'running'
               AND " . TenantStatus::operationalSql('d.tenant_id') . "
               AND a.status IN ('assigned','started')
               AND (d.deadline IS NULL OR d.deadline >= datetime('now','localtime'))
               AND (
                     a.last_reminded_at IS NULL
                     OR a.last_reminded_at <= datetime('now','localtime','-' || ? || ' days')
                   )",
            [$intervalDays]
        );

        $sent = 0;
        $failed = 0;
        foreach ($rows as $r) {
            // 文面はテナントの上書きがあればそれ、なければ既定(NotificationTemplates の edu_reminder_auto)
            $mail = NotificationTemplates::render((int) $r['tenant_id'], 'edu_reminder_auto', NotificationTemplates::eduVars(
                (string) ($r['name'] ?? ''), (string) $r['title'], (string) $r['access_token'], $r['deadline'] ?? null));

            if (EduMailer::send((string) $r['email'], $mail['subject'], $mail['body'])) {
                $sent++;
                Db::run(
                    "UPDATE edu_assignments SET last_reminded_at = datetime('now','localtime') WHERE id = ?",
                    [(int) $r['assignment_id']]
                );
            } else {
                $failed++;
            }
        }

        return [
            'targets' => count($rows),
            'sent' => $sent,
            'failed' => $failed,
            'skipped_recent' => 0, // クエリ側で除外済み(参考値)
        ];
    }

    private static function intervalDays(): int
    {
        $env = getenv('TET2_REMIND_INTERVAL_DAYS');
        if ($env !== false && ctype_digit((string) $env) && (int) $env >= 0) {
            return (int) $env;
        }
        return self::REMIND_INTERVAL_DAYS;
    }
}
