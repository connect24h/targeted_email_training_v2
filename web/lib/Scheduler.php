<?php
/**
 * キャンペーンの送信モードを send_schedule 行に展開する。
 * ワーカー(tet2-worker.py)がこの行をポーリングして send_email.py を起動する。
 *
 * 送信モード:
 *  - normal : 全件を start_at に1バッチ
 *  - split  : split_count 分割を split_interval_min 間隔で N バッチ
 *  - slow   : start_at〜end_at を営業日で割り、各日に1バッチ（なだらか配信）
 * 平日限定/営業時間はワーカー側が scheduled_at 到来時に判定し、窓外なら繰り延べる。
 */
declare(strict_types=1);

require_once __DIR__ . '/Db.php';

final class Scheduler
{
    /** キャンペーンの send_schedule を生成する。生成したバッチ数を返す。 */
    public static function expand(int $campaignId): int
    {
        $c = Db::one('SELECT * FROM campaigns WHERE id = ?', [$campaignId]);
        if ($c === null) {
            throw new RuntimeException('キャンペーンが見つかりません');
        }
        // 既存の未実行スケジュールを一旦掃除（再展開に備える）
        Db::run("DELETE FROM send_schedule WHERE campaign_id = ? AND status IN ('queued','cancelled')", [$campaignId]);

        $kobans = Db::all(
            'SELECT koban FROM campaign_targets WHERE campaign_id = ? ORDER BY koban',
            [$campaignId]
        );
        if (count($kobans) === 0) {
            return 0;
        }
        $minKoban = (int) $kobans[0]['koban'];
        $maxKoban = (int) $kobans[count($kobans) - 1]['koban'];
        $total    = count($kobans);

        $mode    = (string) ($c['send_mode'] ?? 'normal');
        $startAt = (string) ($c['start_at'] ?? '');
        $endAt   = (string) ($c['end_at'] ?? '');
        if ($startAt === '') {
            throw new RuntimeException('start_at が未設定です');
        }

        $batches = [];
        if ($mode === 'split') {
            $splitCount = max(1, min(50, (int) ($c['split_count'] ?? 1)));
            $intervalMin = (int) ($c['split_interval_min'] ?? 15);
            $perBatch = (int) ceil($total / $splitCount);
            $startTs = strtotime($startAt);
            for ($i = 0; $i < $splitCount; $i++) {
                $fromIdx = $i * $perBatch;
                if ($fromIdx >= $total) {
                    break;
                }
                $toIdx = min($fromIdx + $perBatch - 1, $total - 1);
                $batches[] = [
                    'batch_no'     => $i + 1,
                    'scheduled_at' => date('Y-m-d H:i:s', $startTs + $i * $intervalMin * 60),
                    'koban_from'   => (int) $kobans[$fromIdx]['koban'],
                    'koban_to'     => (int) $kobans[$toIdx]['koban'],
                ];
            }
        } elseif ($mode === 'slow') {
            // start〜end を日数で割り、各日 1 バッチ
            $days = max(1, (int) ceil((strtotime($endAt ?: $startAt) - strtotime($startAt)) / 86400));
            $days = min($days, $total); // 対象者数以上には割らない
            $perBatch = (int) ceil($total / $days);
            $startTs = strtotime($startAt);
            for ($i = 0; $i < $days; $i++) {
                $fromIdx = $i * $perBatch;
                if ($fromIdx >= $total) {
                    break;
                }
                $toIdx = min($fromIdx + $perBatch - 1, $total - 1);
                $batches[] = [
                    'batch_no'     => $i + 1,
                    'scheduled_at' => date('Y-m-d H:i:s', $startTs + $i * 86400),
                    'koban_from'   => (int) $kobans[$fromIdx]['koban'],
                    'koban_to'     => (int) $kobans[$toIdx]['koban'],
                ];
            }
        } else {
            // normal: 全件1バッチ
            $batches[] = [
                'batch_no'     => 1,
                'scheduled_at' => date('Y-m-d H:i:s', strtotime($startAt)),
                'koban_from'   => $minKoban,
                'koban_to'     => $maxKoban,
            ];
        }

        foreach ($batches as $b) {
            Db::run(
                'INSERT INTO send_schedule (campaign_id, batch_no, scheduled_at, koban_from, koban_to, interval_sec, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$campaignId, $b['batch_no'], $b['scheduled_at'], $b['koban_from'], $b['koban_to'], 3, 'queued']
            );
        }
        return count($batches);
    }

    /**
     * 停止点からの再開用: 未送信(send_status!='sent')の koban のみを対象に、
     * 残りを即時(now)の単一バッチとして再展開する。生成バッチ数(0 or 1)を返す。
     *
     * split/slow の元の分割は踏襲せず「残り全部を1バッチ」に単純化する。停止→再開は
     * 例外的な操作であり、残りを確実に送り切ることを優先する(再分割の複雑さを持ち込まない)。
     * 実行中(claimed/running)のバッチには触れない。
     */
    public static function expandRemaining(int $campaignId): int
    {
        // 未実行の queued/cancelled を掃除(実行中バッチは残す)
        Db::run("DELETE FROM send_schedule WHERE campaign_id = ? AND status IN ('queued','cancelled')", [$campaignId]);

        $kobans = Db::all(
            "SELECT koban FROM campaign_targets
             WHERE campaign_id = ? AND send_status != 'sent'
             ORDER BY koban",
            [$campaignId]
        );
        if (count($kobans) === 0) {
            return 0; // 全件送信済み=再開する残りがない
        }
        $minKoban = (int) $kobans[0]['koban'];
        $maxKoban = (int) $kobans[count($kobans) - 1]['koban'];

        // 既存バッチの最大 batch_no の次を採番(履歴が分かるように)
        $maxBatch = Db::one('SELECT MAX(batch_no) AS m FROM send_schedule WHERE campaign_id = ?', [$campaignId]);
        $batchNo = ($maxBatch !== null && $maxBatch['m'] !== null) ? ((int) $maxBatch['m'] + 1) : 1;

        Db::run(
            'INSERT INTO send_schedule (campaign_id, batch_no, scheduled_at, koban_from, koban_to, interval_sec, status)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$campaignId, $batchNo, date('Y-m-d H:i:s'), $minKoban, $maxKoban, 3, 'queued']
        );
        return 1;
    }
}
