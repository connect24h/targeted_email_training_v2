<?php
declare(strict_types=1);

/**
 * コンテンツ別の送信元アドレス/ビーコンURL(2026-07-19)の回帰テスト。
 * campaign_contents への保存と、PipelineRunner が content 別に URL.csv/list.csv を出力すること、
 * 未指定時はキャンペーン既定にフォールバックすることを固定する。
 */

require_once __DIR__ . '/helpers.php';
$dbPath = tet2_test_boot();
load_api('campaigns');

$tenantId = current_user()['tenant_id'];

// テンプレートを用意(subject/body/phish 各1つ、テナント内)
function mk_tpl(int $tenantId, string $kind, string $name, string $content, ?int $authFlag = null): int
{
    Db::run('INSERT INTO templates (tenant_id, kind, name, content, auth_flag) VALUES (?,?,?,?,?)',
        [$tenantId, $kind, $name, $content, $authFlag]);
    return (int) Db::one('SELECT id FROM templates WHERE tenant_id = ? AND name = ?', [$tenantId, $name])['id'];
}
$subj = mk_tpl($tenantId, 'subject', 'CF_SUBJ', '件名テスト');
$body = mk_tpl($tenantId, 'body', 'CF_BODY', '本文 #$1$#');
$phishBox = mk_tpl($tenantId, 'phish_login', 'CF_PHISH_BOX', '偽ログインBox', 1);

// 対象者2名
$tids = [];
foreach (['cf1@test', 'cf2@test'] as $i => $em) {
    Db::run('INSERT INTO targets (tenant_id, email, name, status) VALUES (?,?,?,?)', [$tenantId, $em, 'CF' . $i, 'active']);
    $tids[] = (int) Db::one('SELECT id FROM targets WHERE tenant_id = ? AND email = ?', [$tenantId, $em])['id'];
}

// create: 2コンテンツ。content1 は送信元/URL 指定あり、content2 は未指定(キャンペーン既定にフォールバック)
$now = '2026-07-20 09:00:00';
$body_req = [
    'name' => 'CF_CAMPAIGN',
    'from_address' => 'campaign-default@example.com', // キャンペーン既定
    'beacon_base' => 'https://campaign-default.example', // キャンペーン既定
    'send_mode' => 'normal',
    'start_at' => $now,
    'end_at' => '2026-07-25 18:00:00',
    'target_ids' => $tids,
    'contents' => [
        ['subject_template_id' => $subj, 'body_template_id' => $body, 'phish_template_id' => $phishBox,
         'link_mode' => 'link', 'from_address' => 'content1@example.com', 'beacon_base' => 'https://content1.example'],
        ['subject_template_id' => $subj, 'body_template_id' => $body, 'phish_template_id' => $phishBox,
         'link_mode' => 'link'], // 送信元/URL 未指定 → キャンペーン既定にフォールバック
    ],
];
$r = call_handler('campaigns_handle_create', $body_req, 'operator');
check($r['code'] === 201 || $r['code'] === 200, 'create → 201/200');
$campaignId = (int) ($r['payload']['campaign']['id'] ?? 0);
check($campaignId > 0, 'キャンペーンが作成された');

// campaign_contents に content別 from_address/beacon_base が保存されている
$c1 = Db::one('SELECT from_address, beacon_base FROM campaign_contents WHERE campaign_id = ? AND content_no = 1', [$campaignId]);
$c2 = Db::one('SELECT from_address, beacon_base FROM campaign_contents WHERE campaign_id = ? AND content_no = 2', [$campaignId]);
check($c1['from_address'] === 'content1@example.com', 'content1 の from_address が保存される');
check($c1['beacon_base'] === 'https://content1.example', 'content1 の beacon_base が保存される');
check($c2['from_address'] === null, 'content2 の from_address は NULL(未指定)');
check($c2['beacon_base'] === null, 'content2 の beacon_base は NULL(未指定)');

// PipelineRunner で CSV 生成。data_dir をスクラッチパッドに逃がす。
$tmpDir = sys_get_temp_dir() . '/cf-gen-' . getmypid();
Db::run('UPDATE campaigns SET data_dir = ? WHERE id = ?', [$tmpDir, $campaignId]);
require_once __DIR__ . '/../lib/PipelineRunner.php';
$dir = PipelineRunner::generateCsv($campaignId);

// URL.csv: content1 は content1.example、content2 はキャンペーン既定
$urlCsv = array_map('str_getcsv', array_filter(explode("\n", str_replace("\r", '', file_get_contents($dir . '/URL.csv'))), fn($l) => $l !== ''));
$urlByNo = [];
foreach (array_slice($urlCsv, 1) as $row) { $urlByNo[$row[0]] = $row[1]; }
check($urlByNo['1'] === 'https://content1.example/', 'URL.csv content1 = content1 の beacon_base');
check($urlByNo['2'] === 'https://campaign-default.example/', 'URL.csv content2 = キャンペーン既定にフォールバック');

// list.csv: 送信元列(index 9)が content 別
$listCsv = array_map('str_getcsv', array_filter(explode("\n", str_replace("\r", '', file_get_contents($dir . '/list.csv'))), fn($l) => $l !== ''));
$header = $listCsv[0];
$fromIdx = array_search('送信元メールアドレス', $header, true);
$noIdx = array_search('件名定型文No', $header, true);
check($fromIdx !== false, 'list.csv に送信元列がある');
$fromByContentNo = [];
foreach (array_slice($listCsv, 1) as $row) { $fromByContentNo[$row[$noIdx]] = $row[$fromIdx]; }
check($fromByContentNo['1'] === 'content1@example.com', 'list.csv content1 の送信元 = content1@example.com');
check($fromByContentNo['2'] === 'campaign-default@example.com', 'list.csv content2 の送信元 = キャンペーン既定');

// ---- suppress_body_url: 添付型で本文URLを差し込まない(2026-07-19) ----
$sup = [
    'name' => 'CF_SUPPRESS',
    'from_address' => 'sup@example.com',
    'send_mode' => 'normal',
    'start_at' => $now,
    'end_at' => '2026-07-25 18:00:00',
    'target_ids' => $tids,
    'contents' => [
        // content1: 添付型 + 本文URL抑制
        ['subject_template_id' => $subj, 'body_template_id' => $body, 'phish_template_id' => $phishBox,
         'link_mode' => 'attachment', 'attachment_ext' => 'html', 'suppress_body_url' => 1],
        // content2: 添付型 + 抑制なし(トップURLが入る)
        ['subject_template_id' => $subj, 'body_template_id' => $body, 'phish_template_id' => $phishBox,
         'link_mode' => 'attachment', 'attachment_ext' => 'html'],
    ],
];
$r = call_handler('campaigns_handle_create', $sup, 'operator');
$supCid = (int) ($r['payload']['campaign']['id'] ?? 0);
check($supCid > 0, 'suppress検証キャンペーン作成');
check((int) Db::one('SELECT suppress_body_url FROM campaign_contents WHERE campaign_id = ? AND content_no = 1', [$supCid])['suppress_body_url'] === 1, 'content1 に suppress_body_url=1 が保存される');

$supDir = sys_get_temp_dir() . '/cf-sup-' . getmypid();
Db::run('UPDATE campaigns SET data_dir = ? WHERE id = ?', [$supDir, $supCid]);
$dir2 = PipelineRunner::generateCsv($supCid);
$listCsv2 = array_map('str_getcsv', array_filter(explode("\n", str_replace("\r", '', file_get_contents($dir2 . '/list.csv'))), fn($l) => $l !== ''));
$h2 = $listCsv2[0];
$url1Idx = array_search('本文差し込み1 #$1$#', $h2, true);
$no2Idx = array_search('件名定型文No', $h2, true);
$body1ByNo = [];
foreach (array_slice($listCsv2, 1) as $row) { $body1ByNo[$row[$no2Idx]] = $row[$url1Idx]; }
check($body1ByNo['1'] === '', 'suppress_body_url=1 の content は本文URL(#$1$#)が空');
check($body1ByNo['2'] !== '', 'suppress無しの添付型 content は本文URL(トップ)が入る');
foreach (glob($supDir . '/*') ?: [] as $p) { if (is_file($p)) { @unlink($p); } }

// 後片付け(ファイルのみ削除。サブディレクトリは残っても一時領域なので放置)。
foreach (glob($tmpDir . '/*') ?: [] as $p) { if (is_file($p)) { @unlink($p); } }

echo "ALL TESTS PASSED\n";
