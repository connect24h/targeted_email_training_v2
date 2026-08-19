<?php
declare(strict_types=1);

/**
 * 添付ファイル名の接頭辞を管理者指定にする改修(2026-08-19)の回帰テスト。
 *
 * 対象:
 *  - PipelineRunner::generateCsv() が Attachment.csv「添付ファイル名」列に接頭辞を書くこと
 *  - attachment_filename 未指定/空 → 従来の 'kunren'(後方互換)
 *  - campaigns API がパストラバーサル・制御文字・cp932非対応文字を 400 で拒否すること
 *  - create → get で接頭辞が復元されること(ラウンドトリップ)
 *
 * 背景: 従来 QR添付名が `kunren-qr-{tid}` 固定で受信者に訓練だとバレていた。
 * 接頭辞だけを可変にし、`{prefix}{tid}.{ext}` の形にする。
 */

require_once __DIR__ . '/helpers.php';
$dbPath = tet2_test_boot();
load_api('campaigns');

$tenantId = current_user()['tenant_id'];

function mk_tpl_prefix(int $tenantId, string $kind, string $name, string $content, ?int $authFlag = null): int
{
    Db::run('INSERT INTO templates (tenant_id, kind, name, content, auth_flag) VALUES (?,?,?,?,?)',
        [$tenantId, $kind, $name, $content, $authFlag]);
    return (int) Db::one('SELECT id FROM templates WHERE tenant_id = ? AND name = ?', [$tenantId, $name])['id'];
}
$subj = mk_tpl_prefix($tenantId, 'subject', 'PFX_SUBJ', '件名テスト');
$body = mk_tpl_prefix($tenantId, 'body', 'PFX_BODY', '本文 #$2$# #$1$#');
$phish = mk_tpl_prefix($tenantId, 'phish_login', 'PFX_PHISH', '偽ログイン', 0);

$tids = [];
foreach (['pfx1@test', 'pfx2@test', 'pfx3@test'] as $i => $em) {
    Db::run('INSERT INTO targets (tenant_id, email, name, status) VALUES (?,?,?,?)', [$tenantId, $em, 'PFX' . $i, 'active']);
    $tids[] = (int) Db::one('SELECT id FROM targets WHERE tenant_id = ? AND email = ?', [$tenantId, $em])['id'];
}
$now = '2026-08-19 09:00:00';

// --- ケース1: 接頭辞がCSVに反映される + 空でフォールバック ---
$req = [
    'name' => 'PFX_CAMPAIGN',
    'from_address' => 'pfx@example.com',
    'send_mode' => 'normal',
    'start_at' => $now,
    'end_at' => '2026-08-20 18:00:00',
    'target_ids' => $tids,
    'contents' => [
        ['subject_template_id' => $subj, 'body_template_id' => $body, 'phish_template_id' => $phish,
         'link_mode' => 'qr', 'attachment_ext' => 'pdf', 'attachment_filename' => '添付資料-'], // No.1 接頭辞指定
        ['subject_template_id' => $subj, 'body_template_id' => $body, 'phish_template_id' => $phish,
         'link_mode' => 'qr', 'attachment_ext' => 'pdf'], // No.2 接頭辞なし → kunren
    ],
];
$r = call_handler('campaigns_handle_create', $req, 'operator');
check($r['code'] === 201 || $r['code'] === 200, '接頭辞付き contents[] create → 201/200');
$campaignId = (int) ($r['payload']['campaign']['id'] ?? 0);
check($campaignId > 0, 'キャンペーンが作成された');

$tmpDir = sys_get_temp_dir() . '/pfx-gen-' . getmypid();
Db::run('UPDATE campaigns SET data_dir = ? WHERE id = ?', [$tmpDir, $campaignId]);
require_once __DIR__ . '/../lib/PipelineRunner.php';
$dir = PipelineRunner::generateCsv($campaignId);

$attachCsv = array_map('str_getcsv', array_filter(explode("\n", str_replace("\r", '', file_get_contents($dir . '/Attachment.csv'))), fn($l) => $l !== ''));
$prefixByNo = [];
foreach (array_slice($attachCsv, 1) as $row) { $prefixByNo[$row[0]] = $row[1]; }

check($prefixByNo['1'] === '添付資料-', '接頭辞指定 → Attachment.csv「添付ファイル名」列に反映');
check($prefixByNo['2'] === 'kunren', '接頭辞なし → 従来の kunren にフォールバック(後方互換)');

// --- ケース2: get でラウンドトリップ復元 ---
$_GET = ['id' => (string) $campaignId];
$g = call_handler('campaigns_handle_get', [], 'operator');
$_GET = [];
check($g['code'] === 200, 'get → 200');
$contents = $g['payload']['contents'] ?? [];
$byNo = [];
foreach ($contents as $c) { $byNo[(int) $c['content_no']] = $c; }
check(($byNo[1]['attachment_filename'] ?? null) === '添付資料-', 'get で接頭辞が復元される');
check(($byNo[2]['attachment_filename'] ?? null) === null, '接頭辞なしは NULL のまま');

// --- ケース3: サニタイズ(危険な入力を 400 で拒否) ---
function prefix_reject_case(int $subj, int $body, int $phish, array $tids, string $now, string $badPrefix): array
{
    return [
        'name' => 'PFX_BAD_' . substr(md5($badPrefix), 0, 6),
        'from_address' => 'pfx-bad@example.com',
        'send_mode' => 'normal',
        'start_at' => $now,
        'end_at' => '2026-08-20 18:00:00',
        'target_ids' => $tids,
        'contents' => [
            ['subject_template_id' => $subj, 'body_template_id' => $body, 'phish_template_id' => $phish,
             'link_mode' => 'qr', 'attachment_ext' => 'pdf', 'attachment_filename' => $badPrefix],
        ],
    ];
}
$r = call_handler('campaigns_handle_create', prefix_reject_case($subj, $body, $phish, $tids, $now, '../etc/'), 'operator');
check($r['code'] === 400, 'パストラバーサル(../) → 400 で拒否');
$r = call_handler('campaigns_handle_create', prefix_reject_case($subj, $body, $phish, $tids, $now, "a/b"), 'operator');
check($r['code'] === 400, 'パス区切り(/) → 400 で拒否');
$r = call_handler('campaigns_handle_create', prefix_reject_case($subj, $body, $phish, $tids, $now, "改行\n入り"), 'operator');
check($r['code'] === 400, '改行(ヘッダインジェクション) → 400 で拒否');
$r = call_handler('campaigns_handle_create', prefix_reject_case($subj, $body, $phish, $tids, $now, '絵文字😀'), 'operator');
check($r['code'] === 400, 'cp932非対応文字(絵文字) → 400 で拒否');
$r = call_handler('campaigns_handle_create', prefix_reject_case($subj, $body, $phish, $tids, $now, str_repeat('あ', 41)), 'operator');
check($r['code'] === 400, '41文字(長さ超過) → 400 で拒否');

// 正常系: 日本語+ハイフンは通る
$r = call_handler('campaigns_handle_create', prefix_reject_case($subj, $body, $phish, $tids, $now, '請求書-'), 'operator');
check($r['code'] === 201 || $r['code'] === 200, '日本語+ハイフンの接頭辞は通る');

echo "pipeline_attachment_prefix_test: 完了\n";
