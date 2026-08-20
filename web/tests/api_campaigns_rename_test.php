<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

tet2_test_boot();
load_api('campaigns');

/**
 * name 変更専用の rename API。
 * 既存の update は draft 限定だが、name は送信データに影響しない表示ラベルなので
 * status に関わらず変更できることを保証する。name 以外は書き換わらないことも検証。
 */
function apiRenameCampaign(string $status): int
{
    return Db::insert(
        'INSERT INTO campaigns
         (tenant_id, name, status, from_address, link_mode, send_mode, content_delivery, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        [1, 'Rename Source', $status, 'rename@example.test', 'link', 'normal', 'distribute', 1]
    );
}

function renameActor(): array
{
    return ['id' => 1, 'tenant_id' => 1, 'role' => 'operator', 'email' => 'operator@example.test'];
}

echo "=== campaigns rename API ===\n";

// 1. scheduled(=既存updateなら409になる状態)でも rename できる
$id = apiRenameCampaign('scheduled');
$response = call_handler('campaigns_handle_rename', ['id' => $id, 'name' => '新しい名前'], 'operator', [renameActor()]);
check($response['code'] === 200, 'scheduled でも rename は200');
$row = Db::one('SELECT name, status, from_address FROM campaigns WHERE id = ?', [$id]);
check($row['name'] === '新しい名前', 'name が更新される');
check($row['status'] === 'scheduled', 'status は変わらない');
check($row['from_address'] === 'rename@example.test', 'name 以外(from_address)は変わらない');

// 2. done でも rename できる(全status許可)
$doneId = apiRenameCampaign('done');
$response = call_handler('campaigns_handle_rename', ['id' => $doneId, 'name' => '完了後の改名'], 'operator', [renameActor()]);
check($response['code'] === 200, 'done でも rename は200');
check(Db::one('SELECT name FROM campaigns WHERE id = ?', [$doneId])['name'] === '完了後の改名', 'done の name も更新される');

// 3. 前後空白は trim される
$trimId = apiRenameCampaign('draft');
$response = call_handler('campaigns_handle_rename', ['id' => $trimId, 'name' => '  余白あり  '], 'operator', [renameActor()]);
check($response['code'] === 200, 'trim対象でも200');
check(Db::one('SELECT name FROM campaigns WHERE id = ?', [$trimId])['name'] === '余白あり', '前後空白をtrimする');

// 4. 空文字は拒否(400)
$emptyId = apiRenameCampaign('draft');
$response = call_handler('campaigns_handle_rename', ['id' => $emptyId, 'name' => '   '], 'operator', [renameActor()]);
check($response['code'] === 400, '空白のみの name は400');
check(Db::one('SELECT name FROM campaigns WHERE id = ?', [$emptyId])['name'] === 'Rename Source', '拒否時は元の name のまま');

// 5. name 欠落は拒否(400)
$noNameId = apiRenameCampaign('draft');
$response = call_handler('campaigns_handle_rename', ['id' => $noNameId], 'operator', [renameActor()]);
check($response['code'] === 400, 'name 欠落は400');

// 6. 他テナント/存在しないIDは404
$response = call_handler('campaigns_handle_rename', ['id' => 999999, 'name' => 'x'], 'operator', [renameActor()]);
check($response['code'] === 404, '存在しないIDは404');

// 7. 削除済みは404
$deletedId = apiRenameCampaign('draft');
Db::run("UPDATE campaigns SET deleted_at = datetime('now') WHERE id = ?", [$deletedId]);
$response = call_handler('campaigns_handle_rename', ['id' => $deletedId, 'name' => 'x'], 'operator', [renameActor()]);
check($response['code'] === 404, '削除済みは404');

echo "ALL TESTS PASSED\n";
