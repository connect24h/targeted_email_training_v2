<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

tet2_test_boot();
require_once __DIR__ . '/../lib/CampaignDraftFactory.php';
load_api('campaigns');

Db::run(
    'INSERT INTO templates (tenant_id, kind, name, content) VALUES (?, ?, ?, ?)',
    [1, 'subject', 'Duplicate Subject', 'subject']
);
$subjectId = (int) Db::one("SELECT id FROM templates WHERE name = 'Duplicate Subject'")['id'];
Db::run(
    'INSERT INTO templates (tenant_id, kind, name, content) VALUES (?, ?, ?, ?)',
    [1, 'body', 'Duplicate Body', 'body']
);
$bodyId = (int) Db::one("SELECT id FROM templates WHERE name = 'Duplicate Body'")['id'];
Db::run(
    'INSERT INTO templates (tenant_id, kind, name, content, auth_flag) VALUES (?, ?, ?, ?, ?)',
    [1, 'phish_login', 'Duplicate Phish', 'phish', 2]
);
$phishId = (int) Db::one("SELECT id FROM templates WHERE name = 'Duplicate Phish'")['id'];

function apiDuplicateSource(
    int $subjectId,
    int $bodyId,
    int $phishId
): int {
    $id = Db::insert(
        'INSERT INTO campaigns
         (tenant_id, name, status, subject_template_id, body_template_id, phish_template_id,
          from_address, link_mode, send_mode, content_delivery, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [1, 'API Duplicate Source', 'done', $subjectId, $bodyId, $phishId,
         'duplicate@example.test', 'link', 'normal', 'distribute', 1]
    );
    // コピーで「引き継がない」ことを確認するため、元にテスト宛先を入れておく。
    Db::run('UPDATE campaigns SET test_redirect_emails = ? WHERE id = ?',
        ['src-test@example.test', $id]);
    foreach ([1, 2] as $contentNo) {
        Db::run(
            'INSERT INTO campaign_contents
             (campaign_id, content_no, subject_template_id, body_template_id, phish_template_id, link_mode, attachment_filename)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$id, $contentNo, $subjectId, $bodyId, $phishId, 'link', '添付名サンプル']
        );
    }
    foreach ([1, 2] as $index => $targetId) {
        Db::run(
            'INSERT INTO campaign_targets
             (campaign_id, target_id, tracking_id, koban, send_status, content_no)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$id, $targetId, '7000000' . str_pad((string) $id, 2, '0', STR_PAD_LEFT) . $index,
             $index + 1, 'sent', $index + 1]
        );
    }
    return $id;
}

function duplicateActor(): array
{
    return ['id' => 1, 'tenant_id' => 1, 'role' => 'operator', 'email' => 'operator@example.test'];
}

echo "=== campaigns duplicate API ===\n";

$sourceId = apiDuplicateSource($subjectId, $bodyId, $phishId);
Db::run('UPDATE campaigns SET credential_capture_approval_ref=? WHERE id=?', ['synthetic-source-approval', $sourceId]);
$response = call_handler('campaigns_handle_duplicate', ['id' => $sourceId], 'operator', [duplicateActor()]);
check($response['code'] === 200, '既存duplicate APIのstatus codeを維持する');
$draftId = (int) ($response['payload']['campaign']['id'] ?? 0);
check($draftId > 0 && $draftId !== $sourceId, '複製先IDを返す');
$draft = Db::one('SELECT status, start_at, end_at, data_dir, credential_capture_approval_ref, test_redirect_emails FROM campaigns WHERE id = ?', [$draftId]);
check($draft !== null && $draft['status'] === 'draft', '複製先はdraft');
check($draft['start_at'] === null && $draft['end_at'] === null, '配信日時は未設定');
check($draft['credential_capture_approval_ref'] === null, '複製先へ顧客承認を引き継がない');
check(str_ends_with((string) $draft['data_dir'], '/campaign_' . $draftId), '複製先専用data_dirを返す');
check($draft['test_redirect_emails'] === null, 'コピーはテスト宛先を引き継がない');
$rows = Db::all('SELECT tracking_id FROM campaign_targets WHERE campaign_id = ?', [$draftId]);
check(count($rows) === 0, 'コピーは対象者を引き継がない(空の下書き)');
// コンテンツと設定(添付ファイル名)は引き継ぐ。
$contents = Db::all('SELECT content_no, attachment_filename FROM campaign_contents WHERE campaign_id = ? ORDER BY content_no', [$draftId]);
check(count($contents) === 2, 'コンテンツは引き継ぐ');
check(array_unique(array_column($contents, 'attachment_filename')) === ['添付名サンプル'], '添付ファイル名を引き継ぐ(複製漏れの修正)');

$deletedId = apiDuplicateSource($subjectId, $bodyId, $phishId);
Db::run("UPDATE campaigns SET deleted_at = datetime('now') WHERE id = ?", [$deletedId]);
$beforeDeleted = (int) Db::one('SELECT COUNT(*) AS n FROM campaigns')['n'];
$response = call_handler('campaigns_handle_duplicate', ['id' => $deletedId], 'operator', [duplicateActor()]);
check($response['code'] === 404, '削除済みキャンペーンは404');
check((int) Db::one('SELECT COUNT(*) AS n FROM campaigns')['n'] === $beforeDeleted, '削除済み複製の途中行なし');

// コピーは対象者を引き継がないので、元が空でも成功して空の下書きができる。
$emptyId = apiDuplicateSource($subjectId, $bodyId, $phishId);
Db::run('DELETE FROM campaign_targets WHERE campaign_id = ?', [$emptyId]);
$response = call_handler('campaigns_handle_duplicate', ['id' => $emptyId], 'operator', [duplicateActor()]);
check($response['code'] === 200, '元が対象者ゼロでもコピーは成功する');
$emptyDraftId = (int) ($response['payload']['campaign']['id'] ?? 0);
check((int) Db::one('SELECT COUNT(*) AS n FROM campaign_targets WHERE campaign_id = ?', [$emptyDraftId])['n'] === 0,
    'コピー先の対象者も空');

$response = call_handler('campaigns_handle_duplicate', ['id' => 3], 'operator', [duplicateActor()]);
check($response['code'] === 404, '他テナントのキャンペーンは404');

echo "ALL TESTS PASSED\n";
