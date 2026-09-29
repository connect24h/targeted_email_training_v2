<?php
declare(strict_types=1);

require_once __DIR__ . '/Db.php';

/**
 * 訓練メールの送達の状態を postfix の mail.log から取り込む(段B1、G04)。送信の処理(bin/send_email.py、v1 と共用)は変えない。
 *
 * 宛先の決め方:
 *  1. 送信の処理は訓練メールの Message-ID を <t{tracking_id}.{乱数}@{送信元のドメイン}> にしている(2026-09-05 から)。
 *     postfix の cleanup の行 "QUEUEID: message-id=<...>" でキューの番号と tracking_id を結び、同じキューの番号の
 *     "to=<...>, ..., status=bounced|sent" の行で状態を決める。宛先のアドレスが対象者と違う行は入れない
 *     (テストのキャンペーンは宛先をテスト用に振り替えるので、アドレスを問わない)。
 *  2. Message-ID から tracking_id が取れない送信(2026-09-05 より前、または v1)は、宛先のアドレスと送信の時刻(送った
 *     1日以内)と送信元(qmgr の from=)が訓練の送信元と同じで、当てはまる宛先が1件だけの時に限って入れる。
 *
 * status=bounced と status=expired は「届かない」(undeliverable)、status=sent は「送達」(delivered)。deferred は一時的
 * なので見ない。届かないを送達で上書きしない。状態が変わらない行は読むだけで書かない(毎回ログを先頭から読み直すため。
 * 書き込みの鍵を取り続けて受講画面が失敗した 2026-09-27 の教訓)。担当者が対象者を消すことはしない。
 */
final class DeliveryStateIngest
{
    private const MAIL_LOG = '/var/log/mail.log';
    /** Message-ID で結べない送信を宛先と時刻で結ぶ時の、送信から届かないの記録までの最大の間(秒) */
    private const FALLBACK_WINDOW_SEC = 86400;

    /** @return array{undeliverable:int, delivered:int, unmatched:int, lines:int, readable:bool} */
    public static function run(array $opts = []): array
    {
        $counts = ['undeliverable' => 0, 'delivered' => 0, 'unmatched' => 0, 'lines' => 0, 'readable' => false];
        $path = (string) ($opts['mail_log'] ?? (getenv('TET2_MAIL_LOG') ?: self::MAIL_LOG));
        $now = (int) ($opts['now'] ?? time());
        $queues = [];
        // 古い方から読む(ローテートで .1 に移った送信の message-id の行を、今のファイルの結果の行より先に知るため)
        foreach ([$path . '.1', $path] as $file) {
            if (!is_file($file) || !is_readable($file)) {
                continue;
            }
            $counts['readable'] = true;
            $fp = fopen($file, 'r');
            if ($fp === false) {
                continue;
            }
            while (($line = fgets($fp)) !== false) {
                $counts['lines']++;
                $result = self::handleLine($line, $queues, $now);
                if ($result !== null) {
                    $counts[$result]++;
                }
            }
            fclose($fp);
        }
        return $counts;
    }

    /**
     * 1行を読み、キューの情報を貯めるか、送達の結果なら宛先の状態を決める。
     * @param array<string, array{msgid?:string, from?:string}> $queues
     * @return 'undeliverable'|'delivered'|'unmatched'|null 状態を書いた・書けなかった時だけ返す(同じ状態のままは null)
     */
    public static function handleLine(string $line, array &$queues, int $now): ?string
    {
        if (!preg_match('/\spostfix\/[\w\/.-]+\[\d+\]:\s+([0-9A-Za-z]{6,}):\s+(.*)$/', rtrim($line), $m)) {
            return null;
        }
        [$queue, $body] = [$m[1], $m[2]];
        if (preg_match('/^message-id=(<[^>\s]*>)/', $body, $id)) {
            $queues[$queue] = ['msgid' => $id[1]];
            return null;
        }
        if (preg_match('/^from=<([^>]*)>/', $body, $from)) {
            $queues[$queue] = ($queues[$queue] ?? []) + ['from' => strtolower($from[1])];
            return null;
        }
        if (!preg_match('/^to=<([^>]+)>.*\sstatus=(bounced|expired|sent)\b\s*(.*)$/', $body, $r)) {
            return null;
        }
        $state = $r[2] === 'sent' ? 'delivered' : 'undeliverable';
        $at = self::lineTime($line, $now);
        $dsn = preg_match('/\sdsn=([0-9.]+)/', $body, $d) ? $d[1] : '';
        $detail = trim('mail.log ' . $r[2] . ($dsn !== '' ? ' dsn=' . $dsn : '') . ' ' . mb_substr(trim($r[3]), 0, 200));
        $target = self::findTarget($queues[$queue] ?? [], strtolower($r[1]), $at);
        if ($target === null) {
            return $state === 'undeliverable' ? 'unmatched' : null;
        }
        return self::setState((int) $target['id'], $state, $at === null ? date('Y-m-d H:i:s', $now) : date('Y-m-d H:i:s', $at),
            $detail . ($target['method'] === 'fallback' ? '(宛先と時刻で照合)' : '')) ? $state : null;
    }

    /**
     * 宛先の状態を書く。届かないを送達で上書きしない。状態が同じなら書かない。
     * DSN の戻りメール(ReplyIngest)からも使う。
     */
    public static function setState(int $campaignTargetId, string $state, string $at, string $detail): bool
    {
        $current = Db::one('SELECT delivery_state FROM campaign_targets WHERE id = ?', [$campaignTargetId]);
        if ($current === null || $current['delivery_state'] === $state
            || ($current['delivery_state'] === 'undeliverable' && $state === 'delivered')) {
            return false;
        }
        return Db::run('UPDATE campaign_targets SET delivery_state = ?, delivery_state_at = ?, delivery_detail = ?
                        WHERE id = ? AND (delivery_state IS NULL OR delivery_state <> ?)',
            [$state, $at, mb_substr($detail, 0, 300), $campaignTargetId, $state]) > 0;
    }

    /** @return array{id:int, method:string}|null */
    private static function findTarget(array $queue, string $recipient, ?int $at): ?array
    {
        $msgid = $queue['msgid'] ?? '';
        if (preg_match('/^<t([0-9]{10})\./', $msgid, $m)) {
            $row = Db::one('SELECT ct.id, t.email, c.is_test FROM campaign_targets ct
                            INNER JOIN campaigns c ON c.id = ct.campaign_id
                            INNER JOIN targets t ON t.id = ct.target_id AND t.tenant_id = c.tenant_id
                            WHERE ct.tracking_id = ?', [$m[1]]);
            if ($row === null || ((int) $row['is_test'] === 0 && strcasecmp((string) $row['email'], $recipient) !== 0)) {
                return null;
            }
            return ['id' => (int) $row['id'], 'method' => 'msgid'];
        }
        return self::fallbackTarget($queue['from'] ?? '', $recipient, $at);
    }

    /** Message-ID で結べない送信は、宛先・送った時刻・送信元の3つが合う宛先が1件だけの時だけ結ぶ。 */
    private static function fallbackTarget(string $from, string $recipient, ?int $at): ?array
    {
        if ($from === '' || $at === null) {
            return null;
        }
        $rows = Db::all(
            "SELECT ct.id FROM campaign_targets ct
             INNER JOIN campaigns c ON c.id = ct.campaign_id AND c.deleted_at IS NULL
             INNER JOIN targets t ON t.id = ct.target_id AND t.tenant_id = c.tenant_id
             LEFT JOIN campaign_contents cc ON cc.campaign_id = ct.campaign_id AND cc.content_no = ct.content_no
             WHERE t.email = ? COLLATE NOCASE AND ct.sent_at IS NOT NULL
               AND ct.sent_at BETWEEN ? AND ?
               AND lower(COALESCE(NULLIF(ct.from_address, ''), NULLIF(cc.from_address, ''), c.from_address, '')) = ?",
            [$recipient, date('Y-m-d H:i:s', $at - self::FALLBACK_WINDOW_SEC), date('Y-m-d H:i:s', $at + 600), $from]
        );
        return count($rows) === 1 ? ['id' => (int) $rows[0]['id'], 'method' => 'fallback'] : null;
    }

    /** 行頭の時刻。rsyslog の ISO 8601(2026-09-29T09:33:20.4+09:00)と、従来の "Sep 29 09:33:20" の両方を読む。 */
    private static function lineTime(string $line, int $now): ?int
    {
        if (preg_match('/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})?)/', $line, $m)) {
            $ts = strtotime($m[1]);
            return $ts === false ? null : $ts;
        }
        if (preg_match('/^([A-Z][a-z]{2})\s+(\d{1,2})\s(\d{2}:\d{2}:\d{2})/', $line, $m)) {
            $ts = strtotime($m[1] . ' ' . $m[2] . ' ' . date('Y', $now) . ' ' . $m[3]);
            if ($ts === false) {
                return null;
            }
            // 年を持たない形式。年をまたいだ直後の12月の行は前の年にする
            return $ts > $now + 86400 ? (int) strtotime('-1 year', $ts) : $ts;
        }
        return null;
    }
}
