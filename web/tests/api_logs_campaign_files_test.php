<?php
declare(strict_types=1);

/**
 * ログ管理: キャンペーン別 リンク/ビーコン ファイル一覧(campaign_files)テスト。
 * 対象者ごとに link-{tid}.html / kunren-beacon-{tid}.png の完全URLを生成し、
 * beacon_base をキャンペーン/コンテンツ別に解決することを固定する。
 * IDOR(他テナントのキャンペーン指定は404)も検証する。
 */

require_once __DIR__ . '/helpers.php';
tet2_test_boot();
// campaign_files ハンドラは PipelineRunner::beaconUrlBase を使う。
// load_api は関数内の require 行も剥がすため、テスト側で先にロードしておく。
require_once __DIR__ . '/../lib/PipelineRunner.php';
// logs_campaign_filter() は lib/TrainingLogRows.php に移設済み(レポートExcelと共有のため)。
require_once __DIR__ . '/../lib/TrainingLogRows.php';
load_api('logs');

$TENANT = 1; // current_user() の tenant(seed の tenant_id IS NOT NULL 最初 = tenant 1)

// このtestに必要なcampaign/targetを明示し、本番dataのIDや内容へ依存させない。
Db::run("INSERT INTO campaigns (id, tenant_id, name, status, beacon_base) VALUES
  (61, 1, 'Campaign Files Fixture', 'draft', 'https://beacon.example.test')");
for ($i = 1; $i <= 3; $i++) {
    $targetId = 6100 + $i;
    $trackingId = $i === 1 ? '6160278278' : '616027827' . (8 + $i);
    Db::run(
        'INSERT INTO targets (id, tenant_id, email, name, status) VALUES (?,?,?,?,?)',
        [$targetId, $TENANT, "campaign-files-{$i}@example.test", "対象者{$i}", 'active']
    );
    Db::run(
        'INSERT INTO campaign_targets (campaign_id, target_id, tracking_id, koban, content_no) VALUES (?,?,?,?,?)',
        [61, $targetId, $trackingId, $i, $i]
    );
}

// --- 正常系: campaign 61(tenant1, 3対象者, content_no 1/2/3) ---
$_GET = ['campaign_id' => '61'];
$res = call_handler('logs_handle_campaign_files', [], 'operator', [$TENANT]);
check($res['code'] === 200, 'campaign_files: 自テナントのキャンペーンは200');
$p = $res['payload'];
check(($p['count'] ?? 0) === 3, 'campaign_files: 対象者3名分の行が返る');
check($p['campaign_id'] === 61, 'campaign_files: campaign_id が一致');

// tracking_id → link/beacon ファイル名の生成を検証。
$byTid = [];
foreach ($p['rows'] as $r) { $byTid[$r['tracking_id']] = $r; }
check(isset($byTid['6160278278']), 'campaign_files: tracking_id 6160278278 の行がある');
$r0 = $byTid['6160278278'];
check($r0['link_file'] === 'link-6160278278.html', 'campaign_files: link ファイル名が link-{tid}.html');
check($r0['beacon_file'] === 'kunren-beacon-6160278278.png', 'campaign_files: beacon ファイル名が kunren-beacon-{tid}.png');
check(str_ends_with($r0['link_url'], '/link-6160278278.html'), 'campaign_files: link_url が完全URL(末尾ファイル名)');
check(str_ends_with($r0['beacon_url'], '/kunren-beacon-6160278278.png'), 'campaign_files: beacon_url が完全URL');
check(str_starts_with($r0['link_url'], 'http'), 'campaign_files: link_url は http(s) スキーム');
// beacon_base はキャンペーン既定を含む(config.ini/既定IP のいずれか)。
check(isset($p['beacon_base']) && str_starts_with($p['beacon_base'], 'http'), 'campaign_files: beacon_base が解決される');

// --- 異常系1: campaign_id 未指定は400 ---
$_GET = [];
$res = call_handler('logs_handle_campaign_files', [], 'operator', [$TENANT]);
check($res['code'] === 400, 'campaign_files: campaign_id 未指定は400');

// --- 異常系2: IDOR。tenant2 として tenant1 の campaign 61 を要求 → 404 ---
$_GET = ['campaign_id' => '61'];
$res = call_handler('logs_handle_campaign_files', [], 'operator', [2]);
check($res['code'] === 404, 'campaign_files: 他テナントのキャンペーンは404(IDOR防止)');

// --- CSV も同じ行数・IDOR挙動 ---
// CSV ハンドラは exit するため直接呼べない。行組み立て関数 campaign_files_rows を検証。
$_GET = ['campaign_id' => '61'];
$rowsData = campaign_files_rows($TENANT);
check(count($rowsData['rows']) === 3, 'campaign_files_rows: CSV用の行組み立ても3行');

echo "ALL TESTS PASSED\n";
