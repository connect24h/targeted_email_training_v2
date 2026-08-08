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
    foreach ([1, 2] as $contentNo) {
        Db::run(
            'INSERT INTO campaign_contents
             (campaign_id, content_no, subject_template_id, body_template_id, phish_template_id, link_mode)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$id, $contentNo, $subjectId, $bodyId, $phishId, 'link']
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
$response = call_handler('campaigns_handle_duplicate', ['id' => $sourceId], 'operator', [duplicateActor()]);
check($response['code'] === 200, '既存duplicate APIのstatus codeを維持する');
$draftId = (int) ($response['payload']['campaign']['id'] ?? 0);
check($draftId > 0 && $draftId !== $sourceId, '複製先IDを返す');
$draft = Db::one('SELECT status, start_at, end_at, data_dir FROM campaigns WHERE id = ?', [$draftId]);
check($draft !== null && $draft['status'] === 'draft', '複製先はdraft');
check($draft['start_at'] === null && $draft['end_at'] === null, '配信日時は未設定');
check(str_ends_with((string) $draft['data_dir'], '/campaign_' . $draftId), '複製先専用data_dirを返す');
$rows = Db::all('SELECT tracking_id, send_status, from_address FROM campaign_targets WHERE campaign_id = ?', [$draftId]);
check(count($rows) === 2, '元キャンペーンの対象者数を維持する');
check(count(array_unique(array_column($rows, 'tracking_id'))) === 2, 'tracking IDを再採番する');
check(array_unique(array_column($rows, 'send_status')) === ['pending'], '送信状態をpendingへ戻す');
check(array_unique(array_column($rows, 'from_address')) === ['duplicate@example.test'], '送信元はcampaign設定へfallbackする');

$deletedId = apiDuplicateSource($subjectId, $bodyId, $phishId);
Db::run("UPDATE campaigns SET deleted_at = datetime('now') WHERE id = ?", [$deletedId]);
$beforeDeleted = (int) Db::one('SELECT COUNT(*) AS n FROM campaigns')['n'];
$response = call_handler('campaigns_handle_duplicate', ['id' => $deletedId], 'operator', [duplicateActor()]);
check($response['code'] === 404, '削除済みキャンペーンは404');
check((int) Db::one('SELECT COUNT(*) AS n FROM campaigns')['n'] === $beforeDeleted, '削除済み複製の途中行なし');

$emptyId = apiDuplicateSource($subjectId, $bodyId, $phishId);
Db::run('DELETE FROM campaign_targets WHERE campaign_id = ?', [$emptyId]);
$beforeEmpty = (int) Db::one('SELECT COUNT(*) AS n FROM campaigns')['n'];
$response = call_handler('campaigns_handle_duplicate', ['id' => $emptyId], 'operator', [duplicateActor()]);
check($response['code'] === 409, '対象者ゼロは409');
check((int) Db::one('SELECT COUNT(*) AS n FROM campaigns')['n'] === $beforeEmpty, '対象者ゼロ複製の途中行なし');

$response = call_handler('campaigns_handle_duplicate', ['id' => 3], 'operator', [duplicateActor()]);
check($response['code'] === 404, '他テナントのキャンペーンは404');

echo "ALL TESTS PASSED\n";
