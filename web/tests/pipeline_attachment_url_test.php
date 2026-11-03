<?php
declare(strict_types=1);

/**
 * 本文URL型(link_mode=='attachment_url')の生成回帰テスト(2026-11-03)。
 *
 * attachment_url は「添付(PDF)を付け、本文はトップ、PDF本文にクリック可能な
 * 偽ログインURL(link-{tid}.html)を埋め込む」形式。QR型/添付型と同じく添付を生成し、
 * link/form 型のように本文URLに link-{tid}.html を置かない。
 *
 * 対象: PipelineRunner::generateCsv() の Attachment.csv「拡張子」列と list.csv。
 * - attachment_url → Attachment.csv 拡張子は 'url_pdf' 固定(attachment_ext は無視)
 * - attachment_url → list.csv 添付ファイル番号 = content_no(添付あり)、本文差し込み1 は link- を含まない
 * - 対比: link 型 → 添付ファイル番号は空、本文差し込み1 は link-{tid}.html
 * - CampaignPreflight が attachment_url を不正扱いしない(配信形式ブロッカーを出さない)
 */

require_once __DIR__ . '/helpers.php';
$dbPath = tet2_test_boot();
load_api('campaigns');

$tenantId = current_user()['tenant_id'];

function mk_tpl_au(int $tenantId, string $kind, string $name, string $content, ?int $authFlag = null): int
{
    Db::run('INSERT INTO templates (tenant_id, kind, name, content, auth_flag) VALUES (?,?,?,?,?)',
        [$tenantId, $kind, $name, $content, $authFlag]);
    return (int) Db::one('SELECT id FROM templates WHERE tenant_id = ? AND name = ?', [$tenantId, $name])['id'];
}
$subj = mk_tpl_au($tenantId, 'subject', 'AU_SUBJ', '件名テスト');
$body = mk_tpl_au($tenantId, 'body', 'AU_BODY', "本文 #\$2\$#\n下記より確認をお願いします。");
$phish = mk_tpl_au($tenantId, 'phish_login', 'AU_PHISH', '偽ログイン', 1);

$tids = [];
foreach (['au1@test', 'au2@test'] as $i => $em) {
    Db::run('INSERT INTO targets (tenant_id, email, name, status) VALUES (?,?,?,?)', [$tenantId, $em, 'AU' . $i, 'active']);
    $tids[] = (int) Db::one('SELECT id FROM targets WHERE tenant_id = ? AND email = ?', [$tenantId, $em])['id'];
}

$now = '2026-11-03 09:00:00';

// content_no=1: attachment_url(本文URL型)、content_no=2: link(対比)。distribute で 1人ずつ割当。
$body_req = [
    'name' => 'AU_CAMPAIGN',
    'from_address' => 'au@example.com',
    'send_mode' => 'normal',
    'start_at' => $now,
    'end_at' => '2026-11-10 18:00:00',
    'target_ids' => $tids,
    'content_delivery' => 'distribute',
    'contents' => [
        ['subject_template_id' => $subj, 'body_template_id' => $body, 'phish_template_id' => $phish,
         'link_mode' => 'attachment_url', 'attachment_ext' => 'docx'], // attachment_ext は無視され url_pdf 固定になる
        ['subject_template_id' => $subj, 'body_template_id' => $body, 'phish_template_id' => $phish,
         'link_mode' => 'link'],
    ],
];
$r = call_handler('campaigns_handle_create', $body_req, 'operator');
check($r['code'] === 201 || $r['code'] === 200, 'attachment_url contents[] create → 201/200');
$campaignId = (int) ($r['payload']['campaign']['id'] ?? 0);
check($campaignId > 0, 'キャンペーンが作成された');

// link_mode が DB に保存されている(配信形式が受理された)
$savedModes = [];
foreach (Db::all('SELECT content_no, link_mode FROM campaign_contents WHERE campaign_id = ? ORDER BY content_no', [$campaignId]) as $row) {
    $savedModes[(int) $row['content_no']] = (string) $row['link_mode'];
}
check(($savedModes[1] ?? '') === 'attachment_url', 'content1 の link_mode=attachment_url が保存される');
check(($savedModes[2] ?? '') === 'link', 'content2 の link_mode=link が保存される');

$tmpDir = sys_get_temp_dir() . '/au-gen-' . getmypid();
Db::run('UPDATE campaigns SET data_dir = ? WHERE id = ?', [$tmpDir, $campaignId]);
require_once __DIR__ . '/../lib/PipelineRunner.php';
$dir = PipelineRunner::generateCsv($campaignId);

// Attachment.csv: content_no=1 は 'url_pdf'(固定)、content_no=2(link) は 'html'(既定・添付なし扱い)
$attachCsv = array_map('str_getcsv', array_filter(explode("\n", str_replace("\r", '', file_get_contents($dir . '/Attachment.csv'))), fn($l) => $l !== ''));
$extByNo = [];
foreach (array_slice($attachCsv, 1) as $row) { $extByNo[$row[0]] = $row[2]; }
check(($extByNo['1'] ?? null) === 'url_pdf', 'attachment_url → Attachment.csv 拡張子は url_pdf 固定(attachment_ext=docx を無視)');

// list.csv: ヘッダから列位置を引く
$listCsv = array_map('str_getcsv', array_filter(explode("\n", str_replace("\r", '', file_get_contents($dir . '/list.csv'))), fn($l) => $l !== ''));
$header = $listCsv[0];
$subjectIdx = array_search('件名定型文No', $header, true);     // = content_no
$bodyUrlIdx = array_search('本文差し込み1 #$1$#', $header, true);
$attachNoIdx = array_search('添付ファイル番号', $header, true);
$tidIdx = array_search('乱数列', $header, true);
check($subjectIdx !== false && $bodyUrlIdx !== false && $attachNoIdx !== false, 'list.csv に必要な列がある');

$foundAu = false; $foundLink = false;
foreach (array_slice($listCsv, 1) as $row) {
    $contentNo = (int) $row[$subjectIdx];
    if ($contentNo === 1) { // attachment_url
        $foundAu = true;
        check(trim((string) $row[$attachNoIdx]) !== '', 'attachment_url 行は添付ファイル番号あり(添付を生成する)');
        check((int) $row[$attachNoIdx] === 1, 'attachment_url 行の添付ファイル番号 = content_no(1)');
        check(strpos((string) $row[$bodyUrlIdx], 'link-') === false, 'attachment_url 行のメール本文 #$1$# は URL ではない');
        // メール本文 #$1$# は固定の添付参照文言に差し替える(実URLはPDF添付内 #$1$# に入る)。
        check((string) $row[$bodyUrlIdx] === '添付ファイルをご参照ください', 'attachment_url 行のメール本文 #$1$# は「添付ファイルをご参照ください」');
    }
    if ($contentNo === 2) { // link 対比
        $foundLink = true;
        check(trim((string) $row[$attachNoIdx]) === '', 'link 行は添付ファイル番号なし(添付を付けない)');
        $tid = (string) $row[$tidIdx];
        check(strpos((string) $row[$bodyUrlIdx], 'link-' . $tid . '.html') !== false, 'link 行の本文URLは link-{tid}.html');
    }
}
check($foundAu, 'attachment_url の行が list.csv に生成された');
check($foundLink, 'link の行が list.csv に生成された');

// CampaignPreflight: attachment_url を配信形式不正としない
require_once __DIR__ . '/../lib/CampaignPreflight.php';
$inspect = CampaignPreflight::inspect($campaignId, $tenantId);
$badModeBlockers = array_filter($inspect['blockers'] ?? [], fn($b) => strpos((string) $b, '配信形式が不正') !== false);
check(count($badModeBlockers) === 0, 'Preflight が attachment_url を配信形式不正としない');
$hasAttachWarn = false;
foreach ($inspect['warnings'] ?? [] as $w) {
    if (strpos((string) $w, '添付/QR形式') !== false) { $hasAttachWarn = true; }
}
check($hasAttachWarn, 'Preflight の添付生成注意に attachment_url も計上される');

// 後片付け(このテストが作った一時ディレクトリのみ)
foreach (glob($tmpDir . '/*') ?: [] as $p) { if (is_file($p)) { @unlink($p); } }
@rmdir($tmpDir . '/logs'); @rmdir($tmpDir . '/Attachment'); @rmdir($tmpDir);

echo "ALL TESTS PASSED\n";
