<?php
declare(strict_types=1);

/**
 * QR型(link_mode=='qr')の添付ファイル拡張子拡張(2026-08-05)の回帰テスト。
 *
 * 対象: PipelineRunner::generateCsv() が Attachment.csv に書く「拡張子」列。
 * - attachment_ext が docx/pdf/html/xlsx/pptx → 'qr_docx'/'qr_pdf'/'qr_html'/'qr_xlsx'/'qr_pptx'
 *   (create_beacon_files.py 側がこの識別子で QR埋め込み文書を生成する)
 * - attachment_ext が doc/xls/ppt          → 正規化して docx/xlsx/pptx 扱い(2026-08-12 追加)
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
foreach (['qrx1@test', 'qrx2@test', 'qrx3@test', 'qrx4@test', 'qrx5@test', 'qrx6@test', 'qrx7@test', 'qrx8@test', 'qrx9@test'] as $i => $em) {
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
        ['subject_template_id' => $subj, 'body_template_id' => $body, 'phish_template_id' => $phish,
         'link_mode' => 'qr', 'attachment_ext' => 'xlsx'], // No.5
        ['subject_template_id' => $subj, 'body_template_id' => $body, 'phish_template_id' => $phish,
         'link_mode' => 'qr', 'attachment_ext' => 'pptx'], // No.6
        ['subject_template_id' => $subj, 'body_template_id' => $body, 'phish_template_id' => $phish,
         'link_mode' => 'qr', 'attachment_ext' => 'doc'], // No.7 正規化 doc→docx
        ['subject_template_id' => $subj, 'body_template_id' => $body, 'phish_template_id' => $phish,
         'link_mode' => 'qr', 'attachment_ext' => 'xls'], // No.8 正規化 xls→xlsx
        ['subject_template_id' => $subj, 'body_template_id' => $body, 'phish_template_id' => $phish,
         'link_mode' => 'qr', 'attachment_ext' => 'ppt'], // No.9 正規化 ppt→pptx
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

// Web(PHP)がumask=0022で作成したディレクトリでも、送信worker(training:www-data)が
// ログ・状態ファイルを書けるよう、生成のたびにgroup write + setgidへ正規化する。
chmod($dir, 0755);
chmod($dir . '/logs', 0755);
chmod($dir . '/Attachment', 0755);
PipelineRunner::generateCsv($campaignId);
clearstatcache(true, $dir);
clearstatcache(true, $dir . '/logs');
clearstatcache(true, $dir . '/Attachment');
check((fileperms($dir) & 07777) === 02775, 'data_dirを2775へ正規化する');
check((fileperms($dir . '/logs') & 07777) === 02775, 'logsを2775へ正規化する');
check((fileperms($dir . '/Attachment') & 07777) === 02775, 'Attachmentを2775へ正規化する');

$attachCsv = array_map('str_getcsv', array_filter(explode("\n", str_replace("\r", '', file_get_contents($dir . '/Attachment.csv'))), fn($l) => $l !== ''));
$extByNo = [];
foreach (array_slice($attachCsv, 1) as $row) { $extByNo[$row[0]] = $row[2]; }

check($extByNo['1'] === 'qr_docx', 'QR型+docx → Attachment.csv拡張子は qr_docx');
check($extByNo['2'] === 'qr_pdf', 'QR型+pdf → Attachment.csv拡張子は qr_pdf');
check($extByNo['3'] === 'qr', 'QR型+未指定 → Attachment.csv拡張子は従来の qr(後方互換)');
check($extByNo['4'] === 'qr', 'QR型+不正値(exe) → Attachment.csv拡張子は従来の qr(フォールバック)');
check($extByNo['5'] === 'qr_xlsx', 'QR型+xlsx → Attachment.csv拡張子は qr_xlsx');
check($extByNo['6'] === 'qr_pptx', 'QR型+pptx → Attachment.csv拡張子は qr_pptx');
check($extByNo['7'] === 'qr_docx', 'QR型+doc → 正規化されて qr_docx');
check($extByNo['8'] === 'qr_xlsx', 'QR型+xls → 正規化されて qr_xlsx');
check($extByNo['9'] === 'qr_pptx', 'QR型+ppt → 正規化されて qr_pptx');

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

// --- ケース5: suppress_prefill_email が list.csv『メール空欄』列に反映される ---
// content_no=1 は suppress_prefill_email=1(空欄化)、content_no=2 は未指定(=0)。
$body_req5 = [
    'name' => 'QRX_SUPPRESS_EMAIL',
    'from_address' => 'qrx-mail@example.com',
    'send_mode' => 'normal',
    'start_at' => $now,
    'end_at' => '2026-08-10 18:00:00',
    'target_ids' => [$tids[2], $tids[3]],
    'content_delivery' => 'distribute',
    'contents' => [
        ['subject_template_id' => $subj, 'body_template_id' => $body, 'phish_template_id' => $phish,
         'link_mode' => 'link', 'suppress_prefill_email' => 1],
        ['subject_template_id' => $subj, 'body_template_id' => $body, 'phish_template_id' => $phish,
         'link_mode' => 'link'], // 未指定 → 0
    ],
];
$r5 = call_handler('campaigns_handle_create', $body_req5, 'operator');
check($r5['code'] === 201 || $r5['code'] === 200, 'suppress_email create → 201/200');
$campaignId5 = (int) ($r5['payload']['campaign']['id'] ?? 0);

// DBに正しく保存されたか(get経路の復元用SELECTが列を返すか)
$saved = Db::all('SELECT content_no, suppress_prefill_email FROM campaign_contents WHERE campaign_id = ? ORDER BY content_no', [$campaignId5]);
$savedByNo = [];
foreach ($saved as $s) { $savedByNo[(int) $s['content_no']] = (int) $s['suppress_prefill_email']; }
check($savedByNo[1] === 1, 'content1 の suppress_prefill_email=1 がDBに保存される');
check($savedByNo[2] === 0, 'content2 の suppress_prefill_email 未指定→0 がDBに保存される');

// list.csv 生成: 『メール空欄』列(末尾)が content 毎に正しく書かれる
$tmpDir5 = sys_get_temp_dir() . '/qrx-mail-gen-' . getmypid();
Db::run('UPDATE campaigns SET data_dir = ? WHERE id = ?', [$tmpDir5, $campaignId5]);
$dir5 = PipelineRunner::generateCsv($campaignId5);
$listCsv = array_map('str_getcsv', array_filter(explode("\n", str_replace("\r", '', file_get_contents($dir5 . '/list.csv'))), fn($l) => $l !== ''));
$header5 = $listCsv[0];
$mailBlankIdx = array_search('メール空欄', $header5, true);
check($mailBlankIdx !== false, 'list.csv ヘッダに『メール空欄』列がある');
check($mailBlankIdx === count($header5) - 1, '『メール空欄』は末尾列(既存20列の位置を崩さない)');
// content_no は 件名定型文No 列(index 2)に入る。行ごとに content と突合。
$subjectIdx = array_search('件名定型文No', $header5, true);
$foundC1 = false; $foundC2 = false;
foreach (array_slice($listCsv, 1) as $row) {
    if ((int) $row[$subjectIdx] === 1) { $foundC1 = true; check((int) $row[$mailBlankIdx] === 1, 'content1 行の『メール空欄』=1'); }
    if ((int) $row[$subjectIdx] === 2) { $foundC2 = true; check((int) $row[$mailBlankIdx] === 0, 'content2 行の『メール空欄』=0'); }
}
check($foundC1 && $foundC2, '両コンテンツの行が list.csv に生成された');
foreach (glob($tmpDir5 . '/*') ?: [] as $p) { if (is_file($p)) { @unlink($p); } }
@rmdir($tmpDir5 . '/logs'); @rmdir($tmpDir5 . '/Attachment'); @rmdir($tmpDir5);

echo "ALL TESTS PASSED\n";
