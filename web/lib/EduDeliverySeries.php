<?php
/**
 * 毎月の教育配信(系列)。edu_delivery_series の1行が「毎月 N 日の HH:MM に、この設定の配信を作る」規則。
 *
 * edu_scheduler.php が runDue() を呼び、next_run_at の来た系列から、その回の配信を予約の状態で作る。
 * 作った配信は、同じ CLI の「予約した配信の開始」で開始される(開始の処理は EduDeliveryLauncher)。
 * 同じ系列の過去の回で出した設問は、次の回の候補から外す(EduDeliveryLauncher::resolveQuestions)。
 */
declare(strict_types=1);

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/TenantStatus.php';

final class EduDeliverySeries
{
    /** settings(JSON)から edu_deliveries へ写す列。API の edu_d_parse_config が作る列と揃える。 */
    public const SETTING_COLUMNS = [
        'delivery_type', 'question_count', 'category_ids', 'difficulty_range', 'randomize', 'pass_score',
        'material_id', 'target_type', 'target_group_id', 'triggered_by', 'phish_campaign_id', 'feedback_mode',
        'send_invites', 'target_positions', 'risk_results', 'new_target_days', 'allow_retake_after_pass',
        'shuffle_options', 'lock_material_during_test', 'allow_after_deadline', 'retake_from_test',
    ];

    /**
     * $from 以降で最初の「毎月 $day 日の $time」。$from ちょうどもその回に含める。
     */
    public static function nextOccurrence(int $day, string $time, DateTimeImmutable $from): DateTimeImmutable
    {
        [$hour, $minute] = array_map('intval', explode(':', $time));
        $candidate = $from->setDate((int) $from->format('Y'), (int) $from->format('n'), $day)->setTime($hour, $minute);
        if ($candidate < $from) {
            $candidate = $candidate->setDate((int) $candidate->format('Y'), (int) $candidate->format('n') + 1, $day);
        }
        return $candidate;
    }

    /**
     * next_run_at の来た系列から、その回の配信を作る。
     *
     * @return array{created:int, ended:int, delivery_ids:list<int>}
     */
    public static function runDue(DateTimeImmutable $now): array
    {
        $result = ['created' => 0, 'ended' => 0, 'delivery_ids' => []];
        $rows = Db::all(
            // 停止中・削除済みのテナントの系列は回を作らない(有効に戻すと次の実行で作る)
            'SELECT * FROM edu_delivery_series WHERE is_active = 1 AND next_run_at <= ? AND '
            . TenantStatus::operationalSql('tenant_id') . ' ORDER BY id',
            [$now->format('Y-m-d H:i:s')]
        );
        foreach ($rows as $series) {
            $outcome = self::runOne($series, $now);
            if ($outcome['delivery_id'] !== null) {
                $result['created']++;
                $result['delivery_ids'][] = $outcome['delivery_id'];
            }
            if ($outcome['ended']) {
                $result['ended']++;
            }
        }
        return $result;
    }

    /**
     * 1つの系列を処理する。止まっていた間の回はまとめて作らず、今より前の直近の1回だけを作る
     * (何か月分もの配信が一度に届くのを避ける)。
     *
     * @return array{delivery_id:?int, ended:bool}
     */
    private static function runOne(array $series, DateTimeImmutable $now): array
    {
        $tz = $now->getTimezone();
        $day = (int) $series['day_of_month'];
        $time = (string) $series['time_of_day'];
        $occurrence = new DateTimeImmutable((string) $series['next_run_at'], $tz);
        $following = self::nextOccurrence($day, $time, $occurrence->modify('+1 minute'));
        while ($following <= $now) {
            $occurrence = $following;
            $following = self::nextOccurrence($day, $time, $occurrence->modify('+1 minute'));
        }
        $endDate = $series['end_date'] !== null ? (string) $series['end_date'] : null;
        $withinEnd = $endDate === null || $occurrence->format('Y-m-d') <= $endDate;
        $ends = $endDate !== null && $following->format('Y-m-d') > $endDate;

        return Db::txImmediate(static function () use ($series, $occurrence, $following, $withinEnd, $ends): array {
            // 同時に動いた別の実行が先に進めていたら何もしない(同じ回を二重に作らない)。
            $moved = Db::run(
                'UPDATE edu_delivery_series SET next_run_at = ?, is_active = ?
                 WHERE id = ? AND is_active = 1 AND next_run_at = ?',
                [$following->format('Y-m-d H:i:s'), ($withinEnd && !$ends) ? 1 : 0, (int) $series['id'], $series['next_run_at']]
            );
            if ($moved === 0) {
                return ['delivery_id' => null, 'ended' => false];
            }
            $deliveryId = $withinEnd ? self::createDelivery($series, $occurrence) : null;
            if (!$withinEnd || $ends) {
                self::audit($series, 'edu_scheduler.series_end', 'end_date=' . $series['end_date']);
            }
            return ['delivery_id' => $deliveryId, 'ended' => !$withinEnd || $ends];
        });
    }

    /** その回の配信を予約の状態で作る。締切は回の日付に deadline_days 日を足した日(その日いっぱい)。 */
    private static function createDelivery(array $series, DateTimeImmutable $occurrence): int
    {
        $settings = json_decode((string) $series['settings'], true);
        if (!is_array($settings)) {
            throw new RuntimeException('系列の設定が読めません(series_id=' . $series['id'] . ')');
        }
        $columns = [
            'tenant_id' => (int) $series['tenant_id'],
            'title' => (string) $series['title'] . '（' . $occurrence->format('Y年n月') . '）',
            'status' => 'scheduled',
            'scheduled_at' => $occurrence->format('Y-m-d H:i:s'),
            'deadline' => $occurrence->modify('+' . (int) $series['deadline_days'] . ' days')->format('Y-m-d'),
            'series_id' => (int) $series['id'],
            'created_by' => $series['created_by'],
        ];
        foreach (self::SETTING_COLUMNS as $column) {
            if (array_key_exists($column, $settings)) {
                $columns[$column] = $settings[$column];
            }
        }
        $names = array_keys($columns);
        $deliveryId = Db::insert(
            'INSERT INTO edu_deliveries (' . implode(', ', $names) . ')
             VALUES (' . implode(', ', array_fill(0, count($names), '?')) . ')',
            array_values($columns)
        );
        foreach ($settings['target_ids'] ?? [] as $targetId) {
            Db::run(
                'INSERT OR IGNORE INTO edu_delivery_targets (delivery_id, target_id)
                 SELECT ?, id FROM targets WHERE id = ? AND tenant_id = ?',
                [$deliveryId, (int) $targetId, (int) $series['tenant_id']]
            );
        }
        self::audit($series, 'edu_scheduler.series_create', 'delivery_id=' . $deliveryId
            . ',scheduled_at=' . $columns['scheduled_at']);
        return $deliveryId;
    }

    /**
     * 同じ系列の過去の回で出した設問。最後に出した回が古い順に並べる
     * (候補が足りないときに、古い回で出したものから戻すため)。
     *
     * @return list<int>
     */
    public static function usedQuestionIds(int $seriesId, int $tenantId, int $exceptDeliveryId): array
    {
        $rows = Db::all(
            'SELECT dq.question_id, MAX(d.id) AS last_delivery
             FROM edu_delivery_questions dq
             INNER JOIN edu_deliveries d ON d.id = dq.delivery_id
             WHERE d.series_id = ? AND d.tenant_id = ? AND d.id <> ?
             GROUP BY dq.question_id
             ORDER BY last_delivery ASC, dq.question_id ASC',
            [$seriesId, $tenantId, $exceptDeliveryId]
        );
        return array_map(static fn(array $r): int => (int) $r['question_id'], $rows);
    }

    /** 自動の処理の監査ログ(user_id は NULL)。 */
    private static function audit(array $series, string $action, string $detail): void
    {
        Db::run(
            'INSERT INTO audit_log (tenant_id, user_id, action, detail, ip) VALUES (?, NULL, ?, ?, ?)',
            [(int) $series['tenant_id'], $action, 'series_id=' . $series['id'] . ',' . $detail, '']
        );
    }
}
