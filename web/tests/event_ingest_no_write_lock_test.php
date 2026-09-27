<?php
declare(strict_types=1);

/**
 * EventIngest が取り込み済みの行に書き込みの鍵を取らないことを固定する。
 *
 * 取込は5分ごとにアクセスログを先頭から読み直す。取り込み済みの約3,000行にも書き込み(INSERT OR IGNORE)を
 * 1件ずつ走らせていたため、取込の間は書き込みの鍵が取られ続け、受講画面の答え合わせが
 * busy_timeout(5秒)を超えて「database is locked」で失敗した(2026-09-27、本番の配信 10)。
 * 別の接続が書き込みの鍵を握っていても、取り込み済みの行だけなら取込はすぐに終わる。
 */

require_once __DIR__ . '/helpers.php';

$dbPath = tet2_test_boot();
require_once __DIR__ . '/../lib/EventIngest.php';

$logDir = sys_get_temp_dir() . '/tet2-ingest-lock-' . bin2hex(random_bytes(4));
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
    [1, '取込の鍵テスト', 'done', 1]
);
$targetId = Db::insert(
    'INSERT INTO targets (tenant_id, email, name, status, is_test) VALUES (?, ?, ?, ?, ?)',
    [1, 'lock@example.test', '鍵 太郎', 'active', 0]
);
Db::run(
    'INSERT INTO campaign_targets (campaign_id, target_id, tracking_id, koban) VALUES (?, ?, ?, ?)',
    [$campaignId, $targetId, '9000000077', 1]
);

$ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36';
$lines = [];
for ($i = 0; $i < 20; $i++) {
    $time = sprintf('10:%02d:00', $i);
    $lines[] = '1.2.3.4 - - [16/Aug/2026:' . $time . ' +0900] "GET /kunren-beacon-9000000077.png HTTP/1.1" 200 100 "-" "' . $ua . '"';
    $lines[] = '1.2.3.4 - - [16/Aug/2026:' . $time . ' +0900] "GET /link-9000000077.html HTTP/1.1" 200 100 "-" "' . $ua . '"';
}
file_put_contents($logDir . '/access.log', implode("\n", $lines) . "\n");

// 1回目: 新しい行なので取り込む
$first = EventIngest::ingestAll();
check($first['open'] === 20 && $first['click'] === 20, '1回目は開封20件とクリック20件を取り込む');

// 別の接続が書き込みの鍵を握ったまま、同じログをもう一度取り込む
$other = new PDO('sqlite:' . $dbPath);
$other->exec('PRAGMA busy_timeout = 0');
$other->exec('BEGIN IMMEDIATE');
$started = microtime(true);
$error = null;
try {
    $second = EventIngest::ingestAll();
} catch (Throwable $e) {
    $error = $e->getMessage();
}
$elapsed = microtime(true) - $started;
$other->exec('ROLLBACK');

check($error === null, '取り込み済みの行だけなら、別の接続が書き込み中でも取込は失敗しない' . ($error !== null ? "(実際: {$error})" : ''));
check($elapsed < 1.0, sprintf('取込は書き込みの空きを待たない(%.2f 秒)', $elapsed));
check(($second['open'] ?? -1) === 0 && ($second['click'] ?? -1) === 0, '2回目の取込件数は0件');

// 鍵が空いた後に新しい行が来れば、これまでどおり取り込む
file_put_contents($logDir . '/access.log',
    '1.2.3.4 - - [16/Aug/2026:11:00:00 +0900] "GET /link-9000000077.html HTTP/1.1" 200 100 "-" "' . $ua . "\"\n", FILE_APPEND);
$third = EventIngest::ingestAll();
check($third['click'] === 1 && $third['open'] === 0, '新しい行は取り込む');
$count = (int) Db::one('SELECT COUNT(*) AS c FROM events WHERE tracking_id = ?', ['9000000077'])['c'];
check($count === 41, 'events は重複なく41件');

echo "event_ingest_no_write_lock_test: OK\n";
