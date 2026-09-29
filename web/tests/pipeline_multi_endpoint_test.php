<?php
declare(strict_types=1);

/**
 * マルチエンドポイントの起動時解決(PipelineRunner)のテスト。
 * - 複数指定(beacon_bases/from_addresses)を対象者へラウンドロビンで均等配分
 * - 決定的(同じ起動で毎回同じ割り当て)
 * - campaign_targets.resolved_beacon_base/resolved_from_address に確定値を保存
 * - list.csv のビーコンURL(#$1$#)と送信元が確定値と一致
 * - 複数未指定のキャンペーンは従来と同じ(単数列/フォールバック)
 */

require_once __DIR__ . '/helpers.php';
tet2_test_boot();
require_once __DIR__ . '/../lib/PipelineRunner.php';

$dataDir = sys_get_temp_dir() . '/tet2-mep-' . getmypid();
@mkdir($dataDir, 0775, true);
register_shutdown_function(static function () use ($dataDir): void {
    foreach (glob($dataDir . '/*') ?: [] as $p) { @unlink($p); }
    @rmdir($dataDir);
});

// キャンペーン2に、複数の beacon(2件)と 複数の from(3件)を設定する。
$beacons = ['https://b1.example.test/', 'https://b2.example.test/'];
$froms = ['a@example.test', 'b@example.test', 'c@example.test'];
Db::run("UPDATE campaigns SET data_dir=?, from_address=?, beacon_base=?, beacon_bases=?, from_addresses=? WHERE id=2",
    [$dataDir, $froms[0], $beacons[0], json_encode($beacons), json_encode($froms)]);
Db::run('DELETE FROM campaign_contents WHERE campaign_id=2');
Db::run('DELETE FROM campaign_targets WHERE campaign_id=2');
Db::run("INSERT INTO campaign_contents (campaign_id,content_no,subject_template_id,body_template_id,link_mode) VALUES (2,1,1,2,'link')");

// 対象者6人(全員 content 1)。koban 1..6。
$targetCtIds = [];
for ($i = 1; $i <= 6; $i++) {
    $tid = 30 + $i;
    Db::run('INSERT OR IGNORE INTO targets (id,tenant_id,email,name,status) VALUES (?,1,?,?,?)',
        [$tid, "m{$i}@example.test", "MEP {$i}", 'active']);
    Db::run('INSERT INTO campaign_targets (campaign_id,target_id,tracking_id,koban,content_no,send_status) VALUES (2,?,?,?,1,?)',
        [$tid, sprintf('%010d', 2000000 + $i), $i, 'pending']);
    $targetCtIds[$i] = (int) Db::one('SELECT id FROM campaign_targets WHERE tracking_id = ?', [sprintf('%010d', 2000000 + $i)])['id'];
}

PipelineRunner::generateCsv(2);

// --- resolved 値が保存されている ---
$resolvedRows = Db::all('SELECT koban, resolved_beacon_base, resolved_from_address FROM campaign_targets WHERE campaign_id=2 ORDER BY koban');
check(count($resolvedRows) === 6, '6人分の確定値がある');
foreach ($resolvedRows as $r) {
    check($r['resolved_beacon_base'] !== null && $r['resolved_from_address'] !== null, 'koban ' . $r['koban'] . ' に確定値が入る');
}

// --- beacon のラウンドロビン(koban 順)。2件を 3/3 に均等配分 ---
$beaconByKoban = array_column($resolvedRows, 'resolved_beacon_base', 'koban');
$expectedBeacon = [];
foreach (range(1, 6) as $k) { $expectedBeacon[$k] = $beacons[($k - 1) % 2]; }
check($beaconByKoban == $expectedBeacon, 'beacon は koban 順ラウンドロビンで割り当てられる');
$bCounts = array_count_values(array_values($beaconByKoban));
check($bCounts[$beacons[0]] === 3 && $bCounts[$beacons[1]] === 3, 'beacon 2件が 3/3 に均等配分される');

// --- from のラウンドロビン。3件を 2/2/2 に均等配分 ---
$fromByKoban = array_column($resolvedRows, 'resolved_from_address', 'koban');
$expectedFrom = [];
foreach (range(1, 6) as $k) { $expectedFrom[$k] = $froms[($k - 1) % 3]; }
check($fromByKoban == $expectedFrom, 'from は koban 順ラウンドロビンで割り当てられる');
$fCounts = array_count_values(array_values($fromByKoban));
check($fCounts === [$froms[0] => 2, $froms[1] => 2, $froms[2] => 2], 'from 3件が 2/2/2 に均等配分される');

// --- 各 from はメール形式 ---
foreach ($fromByKoban as $f) { check(filter_var($f, FILTER_VALIDATE_EMAIL) !== false, "確定 from はメール形式: {$f}"); }

// --- 決定的: もう一度生成しても同じ ---
$before = $resolvedRows;
PipelineRunner::generateCsv(2);
$after = Db::all('SELECT koban, resolved_beacon_base, resolved_from_address FROM campaign_targets WHERE campaign_id=2 ORDER BY koban');
check($before == $after, '同じ起動で確定値は決定的(2回目も同じ)');

// --- list.csv が確定値と一致 ---
$rows = array_map('str_getcsv', file($dataDir . '/list.csv', FILE_IGNORE_NEW_LINES));
$header = array_shift($rows);
$urlIdx = array_search('本文差し込み1 #$1$#', $header, true);
$fromIdx = array_search('送信元メールアドレス', $header, true);
$kobanIdx = array_search('項番', $header, true);
$tidIdx = array_search('乱数列', $header, true);
foreach ($rows as $row) {
    $k = (int) $row[$kobanIdx];
    $tid = $row[$tidIdx];
    $expectUrl = rtrim($beaconByKoban[$k], '/') . '/link-' . $tid . '.html';
    check($row[$urlIdx] === $expectUrl, "koban {$k} の list.csv 本文URLが確定 beacon と一致");
    check($row[$fromIdx] === $fromByKoban[$k], "koban {$k} の list.csv 送信元が確定 from と一致");
}

// --- logs の campaign_files が確定値(resolved)を読む ---
$GLOBALS['__TET2_TEST_ROLE'] = 'superadmin';
$_GET['campaign_id'] = '2';
require_once __DIR__ . '/../lib/PipelineRunner.php';
require_once __DIR__ . '/../lib/TrainingLogRows.php'; // logs_campaign_filter() の定義
load_api('logs');
// logs_campaign_filter は assert_campaign_owned を使う。helpers のスタブがあるので直接 campaign_files_rows を呼ぶ。
$data = campaign_files_rows(1);
$byTid = [];
foreach ($data['rows'] as $r) { $byTid[$r['tracking_id']] = $r; }
foreach ($resolvedRows as $rr) {
    $tid = Db::one('SELECT tracking_id FROM campaign_targets WHERE campaign_id=2 AND koban=?', [$rr['koban']])['tracking_id'];
    $base = rtrim($rr['resolved_beacon_base'], '/');
    check(isset($byTid[$tid]) && $byTid[$tid]['link_url'] === $base . '/link-' . $tid . '.html',
        "campaign_files が koban {$rr['koban']} で確定 beacon を出す");
}

// --- 複数未指定なら従来と同じ(単数 beacon_base/from_address を使う) ---
Db::run("UPDATE campaigns SET beacon_bases=NULL, from_addresses=NULL, beacon_base='https://single.example.test/', from_address='single@example.test' WHERE id=2");
Db::run('UPDATE campaign_targets SET resolved_beacon_base=NULL, resolved_from_address=NULL WHERE campaign_id=2');
PipelineRunner::generateCsv(2);
$single = Db::all('SELECT resolved_beacon_base, resolved_from_address FROM campaign_targets WHERE campaign_id=2');
foreach ($single as $s) {
    check($s['resolved_beacon_base'] === 'https://single.example.test/' && $s['resolved_from_address'] === 'single@example.test',
        '複数未指定なら全員が単数列の値になる(従来フォールバック)');
}

echo "ALL TESTS PASSED\n";
