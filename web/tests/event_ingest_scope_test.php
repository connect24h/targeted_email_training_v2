<?php
declare(strict_types=1);

/**
 * EventIngest の取込対象と除外規則を固定する。
 *
 * 報告はMaildirから取り込む。ボット除外の列挙にもreportを含める。
 *
 * 実装本体の EventIngest::ingestAll() をそのまま呼ぶ。ログ探索先だけを
 * TET2_APACHE_LOG_DIR で差し替える(ロジックをテスト側で書き直さない)。
 */

require_once __DIR__ . '/helpers.php';

tet2_test_boot();
require_once __DIR__ . '/../lib/EventIngest.php';

$logDir = sys_get_temp_dir() . '/tet2-ingest-' . bin2hex(random_bytes(4));
mkdir($logDir);
putenv('TET2_APACHE_LOG_DIR=' . $logDir);
putenv('TET2_REPORT_MAILDIR=' . $logDir . '/missing');
register_shutdown_function(static function () use ($logDir): void {
    foreach (glob($logDir . '/*') ?: [] as $f) {
        unlink($f);
    }
    @rmdir($logDir);
});

$campaignId = Db::insert(
    'INSERT INTO campaigns (tenant_id, name, status, created_by) VALUES (?, ?, ?, ?)',
    [1, '取込テスト', 'done', 1]
);
$targetId = Db::insert(
    'INSERT INTO targets (tenant_id, email, name, status, is_test) VALUES (?, ?, ?, ?, ?)',
    [1, 'ingest@example.test', '取込 太郎', 'active', 0]
);
Db::run(
    'INSERT INTO campaign_targets (campaign_id, target_id, tracking_id, koban) VALUES (?, ?, ?, ?)',
    [$campaignId, $targetId, '9000000001', 1]
);

$HUMAN_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
    . '(KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36';
$POWERSHELL_UA = 'Mozilla/5.0 (Windows NT; Windows NT 10.0; ja-JP) WindowsPowerShell/5.1.26100.8972';

/** combined ログ1行。時刻を変えて UNIQUE(tracking_id,event_type,occurred_at) を検証する。 */
function ingestLogLine(string $path, string $ua, string $time = '10:00:00'): string
{
    return '1.2.3.4 - - [16/Aug/2026:' . $time . ' +0900] "GET ' . $path . ' HTTP/1.1" 200 100 "-" "' . $ua . '"';
}

/** access.log を差し替えて本体の ingestAll() を回す。 */
function ingestWith(array $lines): array
{
    $dir = getenv('TET2_APACHE_LOG_DIR');
    file_put_contents($dir . '/access.log', implode("\n", $lines) . "\n");
    return EventIngest::ingestAll();
}

function ingestReset(): void
{
    Db::run('DELETE FROM events');
}

// --- 取込対象は open / click / auth の3種 ---
$counts = ingestWith([ingestLogLine('/link-9000000001.html', $HUMAN_UA)]);
check(array_key_exists('open', $counts) && array_key_exists('click', $counts)
    && array_key_exists('auth', $counts), '取込結果は open/click/auth を返す');
check(($counts['click'] ?? 0) === 1, 'リンクページへのアクセスを click として取り込む');

// --- Maildirがなければ報告は0件 ---
ingestReset();
$counts = ingestWith([ingestLogLine('/kunren-report-9000000001.html', $HUMAN_UA)]);
check($counts['report'] === 0 && $counts['report_pending'] === 0, 'Maildirがなければreport=0');
check(Db::one("SELECT 1 FROM events WHERE event_type='report'") === null, 'Maildir不在ならreportは増えない');

// --- ボット除外は click と report に効く。report は加点要素なので除外規則を維持 ---
ingestReset();
$counts = ingestWith([
    ingestLogLine('/link-9000000001.html', 'Proofpoint-URLDefense'),
    ingestLogLine('/link-9000000001.html', 'Slackbot-LinkExpanding 1.0', '10:00:01'),
]);
check(($counts['click'] ?? 0) === 0, 'メールセキュリティのスキャナはクリックとして数えない');

$isBot = new ReflectionMethod(EventIngest::class, 'isBotUserAgent');
$isBot->setAccessible(true);
$source = (string) file_get_contents(__DIR__ . '/../lib/EventIngest.php');
check(
    str_contains($source, "['click', 'report']"),
    'ボット除外の対象に report を含める(報告は加点要素なので水増しの害が大きい)'
);

// --- open にはボット除外を適用しない(運用の動作確認を潰さない) ---
ingestReset();
$counts = ingestWith([ingestLogLine('/kunren-beacon-9000000001.png', $POWERSHELL_UA)]);
check(($counts['open'] ?? 0) === 1, 'PowerShell からのビーコン取得は開封として残す(運用の動作確認手段のため)');

// --- 冪等性: 同一時刻の重複アクセスは UNIQUE 制約で1件になる ---
ingestReset();
$counts = ingestWith([
    ingestLogLine('/link-9000000001.html', $HUMAN_UA),
    ingestLogLine('/link-9000000001.html', $HUMAN_UA),
]);
check(($counts['click'] ?? 0) === 1, '同一時刻の重複アクセスは1件だけ記録する');

// --- 再実行しても増えない(5分毎 timer が同じログを読み直すため必須) ---
$again = ingestWith([ingestLogLine('/link-9000000001.html', $HUMAN_UA)]);
check(($again['click'] ?? 0) === 0, '同じログを再取込しても二重にならない');

// --- 未知の tracking_id は無視する ---
ingestReset();
$counts = ingestWith([ingestLogLine('/link-9999999999.html', $HUMAN_UA)]);
check(($counts['click'] ?? 0) === 0, '対象外の tracking_id は取り込まない');

echo "ALL TESTS PASSED\n";
