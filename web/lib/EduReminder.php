<?php
/**
 * 未完了受講者への自動リマインド。
 * 全テナントの running 配信の未完了者(status assigned/started)のうち、配信の催促の設定(D1、G34)で
 * 今日送る人へ受講催促メールを送る。
 *
 * 配信ごとの設定(edu_deliveries の列。NULL と 0 は今までと同じ動き):
 *   remind_interval_days   何日ごとに送るか。NULL = 既定(TET2_REMIND_INTERVAL_DAYS か 3日)
 *   remind_start_days      期限の何日前から送るか。NULL = 開始から送る。期限のない配信では使わない
 *   remind_after_deadline  1 = 期限の後も送る(remind_max_count の回数まで)。0 = 期限を過ぎたら送らない(従来)
 *   remind_max_count       自動の催促の上限の回数(edu_assignments.remind_count で数える)。NULL = 上限なし
 *
 * 冪等/スパム防止: edu_assignments.last_reminded_at を送信後に更新し、間隔未満は送らない。
 *   → 日次で回しても同じ人に毎日は送らない(既定3日おき)。
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
        $defaultInterval = self::intervalDays();
        $now = date('Y-m-d H:i:s');

        // 候補: running配信 かつ 未完了。送るかどうかは配信の設定で isDue が決める
        $candidates = Db::all(
            "SELECT a.id AS assignment_id, a.access_token, a.last_reminded_at, a.remind_count, a.tenant_id,
                    d.title, d.deadline, d.remind_start_days, d.remind_interval_days, d.remind_after_deadline, d.remind_max_count,
                    t.email, t.name
             FROM edu_assignments a
             INNER JOIN edu_deliveries d ON d.id = a.delivery_id
             INNER JOIN targets t ON t.id = a.target_id
             WHERE d.status = 'running'
               AND " . TenantStatus::operationalSql('d.tenant_id') . "
               AND a.status IN ('assigned','started')"
        );
        $rows = array_values(array_filter($candidates, static fn(array $r): bool => self::isDue([
            'deadline' => $r['deadline'],
            'start_days' => $r['remind_start_days'] !== null ? (int) $r['remind_start_days'] : null,
            'interval_days' => $r['remind_interval_days'] !== null ? (int) $r['remind_interval_days'] : $defaultInterval,
            'after_deadline' => (int) $r['remind_after_deadline'] === 1,
            'max_count' => $r['remind_max_count'] !== null ? (int) $r['remind_max_count'] : null,
        ], $r['last_reminded_at'], (int) $r['remind_count'], $now)));

        $sent = 0;
        $failed = 0;
        foreach ($rows as $r) {
            // 文面はテナントの上書きがあればそれ、なければ既定(NotificationTemplates の edu_reminder_auto)
            $mail = NotificationTemplates::render((int) $r['tenant_id'], 'edu_reminder_auto', NotificationTemplates::eduVars(
                (string) ($r['name'] ?? ''), (string) $r['title'], (string) $r['access_token'], $r['deadline'] ?? null));

            if (EduMailer::send((string) $r['email'], $mail['subject'], $mail['body'])) {
                $sent++;
                Db::run(
                    "UPDATE edu_assignments SET last_reminded_at = datetime('now','localtime'), remind_count = remind_count + 1 WHERE id = ?",
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
            'skipped_recent' => count($candidates) - count($rows), // 設定で今日は送らない人(参考値)
        ];
    }

    /**
     * 今送るか(催促の予定の計算)。日時は 'Y-m-d H:i:s' の文字列(ローカル時刻)。
     * @param array{deadline:?string, start_days:?int, interval_days:int, after_deadline:bool, max_count:?int} $s
     */
    public static function isDue(array $s, ?string $lastRemindedAt, int $remindCount, string $now): bool
    {
        if ($s['max_count'] !== null && $remindCount >= $s['max_count']) {
            return false;
        }
        $deadline = trim((string) ($s['deadline'] ?? ''));
        if ($deadline !== '') {
            // 期限を過ぎたかは、以前の SQL(d.deadline >= datetime('now','localtime'))と同じ文字列の比べ方にする
            // (既定の動きを変えない。日付だけの期限は、その日の 0 時を過ぎたら「過ぎた」)
            // 期限の後は、上限の回数がある時だけ送る(上限なしで送り続けない)
            if (strcmp($deadline, $now) < 0 && (!$s['after_deadline'] || $s['max_count'] === null)) {
                return false;
            }
            if ($s['start_days'] !== null) {
                $deadlineTs = strtotime(strlen($deadline) === 10 ? $deadline . ' 00:00:00' : $deadline);
                if ($deadlineTs !== false && strtotime($now) < $deadlineTs - $s['start_days'] * 86400) {
                    return false;
                }
            }
        }
        if ($lastRemindedAt !== null && $lastRemindedAt !== '') {
            // 以前の SQL(last_reminded_at <= datetime('now','-N days'))と同じ: 前回から N 日以上たっていれば送る
            $threshold = date('Y-m-d H:i:s', strtotime($now . ' -' . $s['interval_days'] . ' days'));
            if (strcmp($lastRemindedAt, $threshold) > 0) {
                return false;
            }
        }
        return true;
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
