<?php
declare(strict_types=1);

/**
 * QR型(link_mode=='qr')の添付ファイル拡張子拡張(2026-08-05)の回帰テスト。
 *
 * 対象: PipelineRunner::generateCsv() が Attachment.csv に書く「拡張子」列。
 * - attachment_ext が docx/pdf/html のいずれか → 'qr_docx'/'qr_pdf'/'qr_html'
 *   (create_beacon_files.py 側がこの識別子で QR埋め込み文書を生成する)
 * - attachment_ext が未指定/空/不正値      → 'qr'（従来の生QR画像PNG・後方互換）
 * - contents[] を使わない旧経路(単一コンテンツ)でも同じ規則が適用されること
 */

require_once __DIR__ . '/helpers.php';
$dbPath = tet2_test_boot();
load_api('campaigns');

$tenantId = current_user()['tenant_id'];

function mk_tpl_qr(int $tenantId, string $kind, string $name, string $content, ?int $authFlag = null): int
{
    Db::run('INSERT INTO templates (tenant_id, kind, name, content, auth_flag) VALUES (?,?,?,?,?)',
        [$tenantId, $kind, $name, $content, $authFlag]);
    return (int) Db::one('SELECT id FROM templates WHERE tenant_id = ? AND name = ?', [$tenantId, $name])['id'];
}
$subj = mk_tpl_qr($tenantId, 'subject', 'QRX_SUBJ', '件名テスト');
$body = mk_tpl_qr($tenantId, 'body', 'QRX_BODY', '本文 #$2$# #$1$#');
$phish = mk_tpl_qr($tenantId, 'phish_login', 'QRX_PHISH', '偽ログイン', 0);

$tids = [];
foreach (['qrx1@test', 'qrx2@test', 'qrx3@test', 'qrx4@test'] as $i => $em) {
    Db::run('INSERT INTO targets (tenant_id, email, name, status) VALUES (?,?,?,?)', [$tenantId, $em, 'QRX' . $i, 'active']);
    $tids[] = (int) Db::one('SELECT id FROM targets WHERE tenant_id = ? AND email = ?', [$tenantId, $em])['id'];
}

$now = '2026-08-05 09:00:00';

// --- ケース1: contents[] 経路。QR型 × attachment_ext=docx/pdf/未指定/不正値 ---
$body_req = [
    'name' => 'QRX_CAMPAIGN',
    'from_address' => 'qrx@example.com',
    'send_mode' => 'normal',
    'start_at' => $now,
    'end_at' => '2026-08-10 18:00:00',
    'target_ids' => $tids,
    'contents' => [
        ['subject_template_id' => $subj, 'body_template_id' => $body, 'phish_template_id' => $phish,
         'link_mode' => 'qr', 'attachment_ext' => 'docx'],
        ['subject_template_id' => $subj, 'body_template_id' => $body, 'phish_template_id' => $phish,
         'link_mode' => 'qr', 'attachment_ext' => 'pdf'],
        ['subject_template_id' => $subj, 'body_template_id' => $body, 'phish_template_id' => $phish,
         'link_mode' => 'qr'], // attachment_ext 未指定 → 従来 'qr'(PNG)にフォールバック
        ['subject_template_id' => $subj, 'body_template_id' => $body, 'phish_template_id' => $phish,
         'link_mode' => 'qr', 'attachment_ext' => 'exe'], // 不正値 → 従来 'qr'(PNG)にフォールバック
    ],
];
$r = call_handler('campaigns_handle_create', $body_req, 'operator');
check($r['code'] === 201 || $r['code'] === 200, 'QR型contents[] create → 201/200');
$campaignId = (int) ($r['payload']['campaign']['id'] ?? 0);
check($campaignId > 0, 'キャンペーンが作成された');

$tmpDir = sys_get_temp_dir() . '/qrx-gen-' . getmypid();
Db::run('UPDATE campaigns SET data_dir = ? WHERE id = ?', [$tmpDir, $campaignId]);
require_once __DIR__ . '/../lib/PipelineRunner.php';
$dir = PipelineRunner::generateCsv($campaignId);

$attachCsv = array_map('str_getcsv', array_filter(explode("\n", str_replace("\r", '', file_get_contents($dir . '/Attachment.csv'))), fn($l) => $l !== ''));
$extByNo = [];
foreach (array_slice($attachCsv, 1) as $row) { $extByNo[$row[0]] = $row[2]; }

check($extByNo['1'] === 'qr_docx', 'QR型+docx → Attachment.csv拡張子は qr_docx');
check($extByNo['2'] === 'qr_pdf', 'QR型+pdf → Attachment.csv拡張子は qr_pdf');
check($extByNo['3'] === 'qr', 'QR型+未指定 → Attachment.csv拡張子は従来の qr(後方互換)');
check($extByNo['4'] === 'qr', 'QR型+不正値(exe) → Attachment.csv拡張子は従来の qr(フォールバック)');

// --- ケース2: 旧経路(contents[]を使わない単一コンテンツ) QR型 + attachment_ext=html ---
$body_req2 = [
    'name' => 'QRX_LEGACY_CAMPAIGN',
    'subject_template_id' => $subj,
    'body_template_id' => $body,
    'phish_template_id' => $phish,
    'from_address' => 'qrx-legacy@example.com',
    'link_mode' => 'qr',
    'attachment_ext' => 'html',
    'send_mode' => 'normal',
    'start_at' => $now,
    'end_at' => '2026-08-10 18:00:00',
    'target_ids' => [$tids[0]],
];
$r2 = call_handler('campaigns_handle_create', $body_req2, 'operator');
check($r2['code'] === 201 || $r2['code'] === 200, '旧経路QR型 create → 201/200');
$campaignId2 = (int) ($r2['payload']['campaign']['id'] ?? 0);
check($campaignId2 > 0, '旧経路キャンペーンが作成された');

$tmpDir2 = sys_get_temp_dir() . '/qrx-legacy-gen-' . getmypid();
Db::run('UPDATE campaigns SET data_dir = ? WHERE id = ?', [$tmpDir2, $campaignId2]);
$dir2 = PipelineRunner::generateCsv($campaignId2);
$attachCsv2 = array_map('str_getcsv', array_filter(explode("\n", str_replace("\r", '', file_get_contents($dir2 . '/Attachment.csv'))), fn($l) => $l !== ''));
check($attachCsv2[1][2] === 'qr_html', '旧経路QR型+html → Attachment.csv拡張子は qr_html');

// --- ケース3: 旧経路 QR型 + attachment_ext未指定 → 従来PNG(後方互換の要) ---
$body_req3 = $body_req2;
$body_req3['name'] = 'QRX_LEGACY_NOEXT_CAMPAIGN';
unset($body_req3['attachment_ext']);
$r3 = call_handler('campaigns_handle_create', $body_req3, 'operator');
check($r3['code'] === 201 || $r3['code'] === 200, '旧経路QR型(拡張子未指定) create → 201/200');
$campaignId3 = (int) ($r3['payload']['campaign']['id'] ?? 0);
$tmpDir3 = sys_get_temp_dir() . '/qrx-legacy-noext-gen-' . getmypid();
Db::run('UPDATE campaigns SET data_dir = ? WHERE id = ?', [$tmpDir3, $campaignId3]);
$dir3 = PipelineRunner::generateCsv($campaignId3);
$attachCsv3 = array_map('str_getcsv', array_filter(explode("\n", str_replace("\r", '', file_get_contents($dir3 . '/Attachment.csv'))), fn($l) => $l !== ''));
check($attachCsv3[1][2] === 'qr', '旧経路QR型+拡張子未指定 → Attachment.csv拡張子は従来の qr(後方互換)');

// --- ケース4: 非QR型(attachment)は従来通り attachment_ext をそのまま使う(回帰確認) ---
$body_req4 = [
    'name' => 'QRX_ATTACHMENT_REGRESSION',
    'from_address' => 'qrx-att@example.com',
    'send_mode' => 'normal',
    'start_at' => $now,
    'end_at' => '2026-08-10 18:00:00',
    'target_ids' => [$tids[1]],
    'contents' => [
        ['subject_template_id' => $subj, 'body_template_id' => $body, 'phish_template_id' => $phish,
         'link_mode' => 'attachment', 'attachment_ext' => 'doc'],
    ],
];
$r4 = call_handler('campaigns_handle_create', $body_req4, 'operator');
check($r4['code'] === 201 || $r4['code'] === 200, '非QR添付型 create → 201/200');
$campaignId4 = (int) ($r4['payload']['campaign']['id'] ?? 0);
$tmpDir4 = sys_get_temp_dir() . '/qrx-att-gen-' . getmypid();
Db::run('UPDATE campaigns SET data_dir = ? WHERE id = ?', [$tmpDir4, $campaignId4]);
$dir4 = PipelineRunner::generateCsv($campaignId4);
$attachCsv4 = array_map('str_getcsv', array_filter(explode("\n", str_replace("\r", '', file_get_contents($dir4 . '/Attachment.csv'))), fn($l) => $l !== ''));
check($attachCsv4[1][2] === 'doc', '非QR添付型は attachment_ext(doc) がそのまま書かれる(回帰なし)');

echo "ALL TESTS PASSED\n";
