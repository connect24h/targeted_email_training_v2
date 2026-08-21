<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

tet2_test_boot();
load_api('campaigns');

/**
 * is_test 切替専用の set_test API。
 * 既存の update は draft 限定だが、is_test は送信データに影響しない分類ラベルなので
 * status に関わらず(running/done でも)切り替えられることを保証する。
 * is_test 以外は書き換わらないことも検証。
 */
function apiSetTestCampaign(string $status, int $isTest = 0): int
{
    return Db::insert(
        'INSERT INTO campaigns
         (tenant_id, name, status, from_address, link_mode, send_mode, content_delivery, is_test, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [1, 'SetTest Source', $status, 'settest@example.test', 'link', 'normal', 'distribute', $isTest, 1]
    );
}

function setTestActor(): array
{
    return ['id' => 1, 'tenant_id' => 1, 'role' => 'operator', 'email' => 'operator@example.test'];
}

echo "=== campaigns set_test API ===\n";

// 1. draft を test(1)→prod(0) に切替できる
$id = apiSetTestCampaign('draft', 1);
$res = call_handler('campaigns_handle_set_test', ['id' => $id, 'is_test' => 0], 'operator', [setTestActor()]);
check($res['code'] === 200, 'draft の is_test 切替は200');
check((int) Db::one('SELECT is_test FROM campaigns WHERE id=?', [$id])['is_test'] === 0, 'is_test が 1→0 に更新される');

// 2. 【要望の核心】running でも切替できる(draft限定でないこと)
$runId = apiSetTestCampaign('running', 0);
$res = call_handler('campaigns_handle_set_test', ['id' => $runId, 'is_test' => 1], 'operator', [setTestActor()]);
check($res['code'] === 200, 'running でも is_test 切替は200(送信後も切替可)');
check((int) Db::one('SELECT is_test FROM campaigns WHERE id=?', [$runId])['is_test'] === 1, 'running の is_test が 0→1 に更新される');

// 3. done でも切替できる
$doneId = apiSetTestCampaign('done', 1);
$res = call_handler('campaigns_handle_set_test', ['id' => $doneId, 'is_test' => 0], 'operator', [setTestActor()]);
check($res['code'] === 200, 'done でも is_test 切替は200');
check((int) Db::one('SELECT is_test FROM campaigns WHERE id=?', [$doneId])['is_test'] === 0, 'done の is_test も更新される');

// 4. is_test 以外(status/from_address)は変わらない
$keepId = apiSetTestCampaign('scheduled', 0);
call_handler('campaigns_handle_set_test', ['id' => $keepId, 'is_test' => 1], 'operator', [setTestActor()]);
$row = Db::one('SELECT status, from_address FROM campaigns WHERE id=?', [$keepId]);
check($row['status'] === 'scheduled', 'status は変わらない');
check($row['from_address'] === 'settest@example.test', 'from_address は変わらない');

// 5. 異常値(0/1 以外の 5 等)は 400 で拒否(防御的: 厳密に 0/1 のみ受け入れる)
$normId = apiSetTestCampaign('draft', 0);
$res = call_handler('campaigns_handle_set_test', ['id' => $normId, 'is_test' => 5], 'operator', [setTestActor()]);
check($res['code'] === 400, '0/1 以外(5)は400で拒否');
check((int) Db::one('SELECT is_test FROM campaigns WHERE id=?', [$normId])['is_test'] === 0, '拒否時は元の is_test のまま');

// 6. is_test 欠落は400
$noFlagId = apiSetTestCampaign('draft', 0);
$res = call_handler('campaigns_handle_set_test', ['id' => $noFlagId], 'operator', [setTestActor()]);
check($res['code'] === 400, 'is_test 欠落は400');

// 7. 存在しないID/他テナントは404
$res = call_handler('campaigns_handle_set_test', ['id' => 999999, 'is_test' => 1], 'operator', [setTestActor()]);
check($res['code'] === 404, '存在しないIDは404');

// 8. 削除済みは404
$delId = apiSetTestCampaign('draft', 0);
Db::run("UPDATE campaigns SET deleted_at = datetime('now') WHERE id=?", [$delId]);
$res = call_handler('campaigns_handle_set_test', ['id' => $delId, 'is_test' => 1], 'operator', [setTestActor()]);
check($res['code'] === 404, '削除済みは404');

echo "ALL TESTS PASSED\n";
