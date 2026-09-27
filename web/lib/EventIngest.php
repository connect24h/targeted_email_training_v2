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
require_once __DIR__ . '/ReportMailIngest.php';

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
        // report のMaildir取込を実装。回収IDと対象者のFrom一致で確定し、
        // pending は人が確定する。報告URLへのアクセスでは加点しない。
        $counts = ['open' => 0, 'click' => 0, 'auth' => 0];
        $counts['open']  += self::ingestApache($map, 'open',  '/kunren-beacon-(\d{10})\.png/');
        $counts['click'] += self::ingestApache($map, 'click', '/link-(\d{10})\.html/');
        $counts['auth']  += self::ingestAuthLogs($map);
        $report = ReportMailIngest::run();
        $counts['report'] = $report['report'];
        $counts['report_pending'] = $report['report_pending'];
        return $counts;
    }

    /** ログ探索先。テストからは TET2_APACHE_LOG_DIR で差し替える。 */
    private static function apacheLogDir(): string
    {
        $env = getenv('TET2_APACHE_LOG_DIR');
        return ($env !== false && $env !== '') ? rtrim($env, '/') : self::APACHE_LOG_DIR;
    }

    private static function apacheLogFiles(): array
    {
        $files = [];
        $dir = self::apacheLogDir();
        foreach (['access.log', 'access.log.1'] as $f) {
            $p = $dir . '/' . $f;
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
                // リクエスト行(Apache combined の最初の "METHOD /path HTTP/x")のパスだけを
                // 判定対象にする。行全体に $pattern を当てると、favicon.ico や認証 POST
                // (/training_log.php)のように「リファラーに link-{tid}.html を含むだけ」の
                // 行まで click に誤計上され、1回の開封が複数 click に水増しされる(2026-08-23 修正)。
                $requestPath = self::apacheRequestPath($line);
                if ($requestPath === null || !preg_match($pattern, $requestPath, $m)) {
                    continue;
                }
                $tid = $m[1];
                if (!isset($map[$tid])) {
                    continue;
                }
                // Slack 等のリンク自動展開・メールセキュリティのURL事前スキャンが訓練リンクを
                // 踏むと、人間のクリックでないのに防衛失敗として記録される。UAでボットを除外する。
                // 2026-08 に Slackbot 156件がキャンペーン89の全クリックを誤計上した事故による。
                //
                // report は Maildir 経由(ReportMailIngest)で取り込むので Apache ログには現れないが、
                // 将来 URL 方式を足しても報告は加点要素なので、スキャナに踏まれて「報告した」ことに
                // なる害が大きい。念のため同じ条件で弾く。
                //
                // open(ビーコン)は意図的に除外しない。メールクライアントのプリフェッチで
                // 踏まれる性質のものであり、運用上の動作確認(PowerShell 等)も開封として
                // 数える現行の判断を維持する。
                if (in_array($eventType, ['click', 'report'], true) && self::isBotUserAgent($line)) {
                    continue;
                }
                $occurred = self::apacheTimestamp($line);
                $n += self::insertEvent($map[$tid], $tid, $eventType, null, $occurred, 'apache_access', $line);
            }
            fclose($fp);
        }
        return $n;
    }

    /**
     * Apache combined ログ1行の User-Agent がボット(SNSリンク展開・メールセキュリティ
     * スキャナ・HTTPクライアント)かを判定する。行末の "..." で囲まれた最後のフィールドが
     * User-Agent。UA が取れない行は false(=ボット扱いしない。既存挙動を維持する)。
     */
    private static function isBotUserAgent(string $logLine): bool
    {
        // Apache combined の末尾: ... "referer" "user-agent"
        if (!preg_match('/"([^"]*)"\s*$/', rtrim($logLine), $m)) {
            return false;
        }
        $ua = $m[1];
        if ($ua === '' || $ua === '-') {
            return false;
        }
        // SNSリンク展開ボット / HTTPクライアント / メールセキュリティのURLスキャナ。
        $patterns = [
            'Slackbot', 'Googlebot', 'bingbot', 'YandexBot', 'DuckDuckBot',
            'Twitterbot', 'facebookexternalhit', 'LinkedInBot', 'Discordbot',
            'TelegramBot', 'WhatsApp', 'Applebot',
            'curl', 'Wget', 'python-requests', 'Go-http-client', 'Java/', 'okhttp',
            'Barracuda', 'Proofpoint', 'Mimecast', 'Microsoft-', 'SafeLinks',
            'URLDefense', 'ATP', 'Symantec', 'Forcepoint', 'Cisco', 'Ironport',
        ];
        foreach ($patterns as $needle) {
            if (stripos($ua, $needle) !== false) {
                return true;
            }
        }
        return false;
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

    /**
     * Apache combined ログ1行から、リクエスト行のパスを取り出す。
     * 例: '... "GET /link-1234567890.html HTTP/1.1" 200 ...' → '/link-1234567890.html'
     * リクエスト行が取れない行は null(=判定対象外)。リファラーや UA に含まれる
     * パス文字列を誤ってマッチさせないため、クエリ以降とプロトコルは落とす。
     */
    private static function apacheRequestPath(string $line): ?string
    {
        // 最初の "METHOD /path HTTP/x.y" を取る。メソッドは GET/POST 等。
        if (!preg_match('/"[A-Z]+\s+(\S+)\s+HTTP\/[0-9.]+"/', $line, $m)) {
            return null;
        }
        $path = $m[1];
        // クエリ文字列を除く(link-{tid}.html?foo 等でも本体パスで判定)。
        $q = strpos($path, '?');
        return $q === false ? $path : substr($path, 0, $q);
    }

    private static function insertEvent(array $ref, string $tid, string $type, ?string $variant, string $occurred, string $source, string $raw): int
    {
        // UNIQUE(tracking_id, event_type, occurred_at) で冪等。
        // 取込は毎回ログを先頭から読み直すので、取り込み済みの行は読むだけで確かめて書き込まない。
        // INSERT OR IGNORE は無視される行でも書き込みの鍵を取り、数千行の取込の間ずっと鍵が取られ続けて
        // 受講画面の書き込みが busy_timeout を超えて失敗した(2026-09-27)。
        if (Db::one('SELECT 1 FROM events WHERE tracking_id = ? AND event_type = ? AND occurred_at = ?', [$tid, $type, $occurred]) !== null) {
            return 0;
        }
        return Db::run(
            'INSERT OR IGNORE INTO events (tenant_id, campaign_id, tracking_id, event_type, auth_variant, occurred_at, source, raw)
             VALUES (?,?,?,?,?,?,?,?)',
            [$ref['tenant_id'], $ref['campaign_id'], $tid, $type, $variant, $occurred, $source, substr($raw, 0, 500)]
        );
    }
}
