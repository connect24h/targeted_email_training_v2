<?php
/**
 * Apache アクセスログ・偽ログイン認証ログを走査し、tracking_id で events テーブルに冪等取込する。
 * 既存 /tet の training_history.php の突合ロジックを移植。
 *  - 開封(open) : Apache access.log の "kunren-beacon-{10桁}.png" ヒット
 *  - クリック(click) : Apache access.log の "link-{10桁}.html" ヒット
 *  - 認証(auth) : training_log_*.txt の "Random: {10桁}" + "Type: {種別}"
 * tracking_id → campaign_targets で campaign/tenant を確定し events に INSERT OR IGNORE。
 */
declare(strict_types=1);

require_once __DIR__ . '/Db.php';

final class EventIngest
{
    private const APACHE_LOG_DIR   = '/var/log/apache2';
    private const TRAINING_LOG_DIR = '/var/www/html/training_logs';

    /** 全ログを走査して events を更新。取込件数の内訳を返す。 */
    public static function ingestAll(): array
    {
        // tracking_id → [campaign_id, tenant_id] のマップを先に作る
        $map = [];
        foreach (Db::all('SELECT ct.tracking_id, ct.campaign_id, c.tenant_id
                          FROM campaign_targets ct JOIN campaigns c ON c.id = ct.campaign_id') as $r) {
            $map[$r['tracking_id']] = ['campaign_id' => (int) $r['campaign_id'], 'tenant_id' => (int) $r['tenant_id']];
        }
        if (count($map) === 0) {
            return ['open' => 0, 'click' => 0, 'auth' => 0];
        }

        $counts = ['open' => 0, 'click' => 0, 'auth' => 0];
        $counts['open']  += self::ingestApache($map, 'open',  '/kunren-beacon-(\d{10})\.png/');
        $counts['click'] += self::ingestApache($map, 'click', '/link-(\d{10})\.html/');
        $counts['auth']  += self::ingestAuthLogs($map);
        return $counts;
    }

    private static function apacheLogFiles(): array
    {
        $files = [];
        foreach (['access.log', 'access.log.1'] as $f) {
            $p = self::APACHE_LOG_DIR . '/' . $f;
            if (is_readable($p)) {
                $files[] = $p;
            }
        }
        return $files;
    }

    private static function ingestApache(array $map, string $eventType, string $pattern): int
    {
        $n = 0;
        foreach (self::apacheLogFiles() as $file) {
            $fp = fopen($file, 'r');
            if ($fp === false) {
                continue;
            }
            while (($line = fgets($fp)) !== false) {
                if (!preg_match($pattern, $line, $m)) {
                    continue;
                }
                $tid = $m[1];
                if (!isset($map[$tid])) {
                    continue;
                }
                $occurred = self::apacheTimestamp($line);
                $n += self::insertEvent($map[$tid], $tid, $eventType, null, $occurred, 'apache_access', $line);
            }
            fclose($fp);
        }
        return $n;
    }

    private static function ingestAuthLogs(array $map): int
    {
        $n = 0;
        foreach (glob(self::TRAINING_LOG_DIR . '/training_log_*.txt') ?: [] as $file) {
            $fp = fopen($file, 'r');
            if ($fp === false) {
                continue;
            }
            while (($line = fgets($fp)) !== false) {
                if (!preg_match('/Random:\s*(\d{10})/', $line, $mr)) {
                    continue;
                }
                $tid = $mr[1];
                if (!isset($map[$tid])) {
                    continue;
                }
                $type = preg_match('/Type:\s*(\w+)/', $line, $mt) ? $mt[1] : null;
                $occurred = preg_match('/^\[([^\]]+)\]/', $line, $mtime)
                    ? date('Y-m-d H:i:s', strtotime($mtime[1]) ?: time())
                    : date('Y-m-d H:i:s');
                $n += self::insertEvent($map[$tid], $tid, 'auth', $type, $occurred, 'text_log', $line);
            }
            fclose($fp);
        }
        return $n;
    }

    private static function apacheTimestamp(string $line): string
    {
        // Apache combined: [05/Jul/2026:00:24:54 +0900]
        if (preg_match('/\[(\d{2}\/\w{3}\/\d{4}:\d{2}:\d{2}:\d{2})/', $line, $m)) {
            $ts = strtotime(str_replace('/', ' ', preg_replace('/:/', ' ', $m[1], 1)));
            if ($ts !== false) {
                return date('Y-m-d H:i:s', $ts);
            }
        }
        return date('Y-m-d H:i:s');
    }

    private static function insertEvent(array $ref, string $tid, string $type, ?string $variant, string $occurred, string $source, string $raw): int
    {
        // UNIQUE(tracking_id, event_type, occurred_at) で冪等
        return Db::run(
            'INSERT OR IGNORE INTO events (tenant_id, campaign_id, tracking_id, event_type, auth_variant, occurred_at, source, raw)
             VALUES (?,?,?,?,?,?,?,?)',
            [$ref['tenant_id'], $ref['campaign_id'], $tid, $type, $variant, $occurred, $source, substr($raw, 0, 500)]
        );
    }
}
