<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
tet2_test_boot();
load_api('campaigns');

function deleteSourceFixture(string $status = 'done'): int
{
    return Db::insert('INSERT INTO campaigns (tenant_id, name, status) VALUES (1, ?, ?)', ['Delete Source', $status]);
}

function deleteRuleFixture(int $sourceId, int $tenantId = 1): int
{
    return Db::insert(
        "INSERT INTO campaign_automations (tenant_id, name, source_campaign_id, frequency, day_of_month,
         time_mode, send_window_start, next_due_at) VALUES (?, 'Delete Rule', ?, 'monthly', 15, 'fixed', '09:00', '2030-01-01 09:00:00')",
        [$tenantId, $sourceId]
    );
}

$sourceId = deleteSourceFixture();
$otherCaptureCampaignId = deleteSourceFixture();
Db::run("INSERT INTO credential_captures (tenant_id, campaign_id, tracking_id, auth_type, nonce, ciphertext)
    VALUES (1, ?, '1111111111', 'box', 'synthetic-nonce', 'synthetic-ciphertext')", [$sourceId]);
Db::run("INSERT INTO credential_captures (tenant_id, campaign_id, tracking_id, auth_type, nonce, ciphertext)
    VALUES (1, ?, '2222222222', 'box', 'synthetic-nonce', 'synthetic-ciphertext')", [$otherCaptureCampaignId]);
$ruleId = deleteRuleFixture($sourceId);
$otherSourceId = deleteSourceFixture();
$otherRuleId = deleteRuleFixture($otherSourceId);
$otherTenantRuleId = deleteRuleFixture($sourceId, 2);
$draftId = deleteSourceFixture('draft');
Db::run("INSERT INTO campaign_automation_runs (automation_id, occurrence_key, selected_send_at, generated_campaign_id, status) VALUES (?, '2030-01', '2030-01-15 09:00:00', ?, 'generated')", [$ruleId, $draftId]);
$runs = Db::all('SELECT * FROM campaign_automation_runs ORDER BY id');
$draft = Db::one('SELECT * FROM campaigns WHERE id=?', [$draftId]);
$response = call_handler('campaigns_handle_delete', ['id' => $sourceId]);
check($response['code'] === 200, '元campaign削除が成功する');
check(Db::one('SELECT id FROM credential_captures WHERE campaign_id=?', [$sourceId]) === null,
    'campaign論理削除と同じtransactionで入力本文を消去する');
check(Db::one('SELECT id FROM credential_captures WHERE campaign_id=?', [$otherCaptureCampaignId]) !== null,
    '他campaignの入力本文は保持する');
check(Db::one('SELECT status FROM campaign_automations WHERE id=?', [$ruleId])['status'] === 'paused', '元campaignの定期ruleを停止する');
check(Db::one('SELECT status FROM campaign_automations WHERE id=?', [$otherRuleId])['status'] === 'active', '他sourceのruleは変更しない');
check(Db::one('SELECT status FROM campaign_automations WHERE id=?', [$otherTenantRuleId])['status'] === 'active', '他tenantのruleは変更しない');
check(Db::all('SELECT * FROM campaign_automation_runs ORDER BY id') === $runs, '生成履歴を保持する');
check(Db::one('SELECT * FROM campaigns WHERE id=?', [$draftId]) === $draft, '生成済campaignを保持する');
check($GLOBALS['__TET2_TEST_CSRF_CALLS'] === 1, 'CSRF検証を維持する');
check(Db::all('PRAGMA foreign_key_check') === [], '参照整合性を維持する');
$runningId = deleteSourceFixture('running');
$runningRuleId = deleteRuleFixture($runningId);
$dir = sys_get_temp_dir() . '/tet2-delete-automation-' . bin2hex(random_bytes(8));
mkdir($dir, 0700);
try {
    Db::run('UPDATE campaigns SET data_dir=? WHERE id=?', [$dir, $runningId]);
    foreach (['queued', 'running', 'done'] as $status) {
        Db::run("INSERT INTO send_schedule (campaign_id, scheduled_at, status) VALUES (?, '2030-01-01 09:00:00', ?)", [$runningId, $status]);
    }
    $response = call_handler('campaigns_handle_delete', ['id' => $runningId]);
    check($response['code'] === 200, '実行中campaignも削除できる');
    check(array_column(Db::all('SELECT status FROM send_schedule WHERE campaign_id=? ORDER BY id', [$runningId]), 'status') === ['cancelled', 'running', 'done'], '既存のqueued停止処理を維持する');
    check(file_get_contents($dir . '/stop_sending.flag') === "deleted\n", '既存の送信停止flagを維持する');
    check(Db::one('SELECT status FROM campaign_automations WHERE id=?', [$runningRuleId])['status'] === 'paused', '実行中sourceのruleも停止する');
} finally {
    if (is_file($dir . '/stop_sending.flag')) unlink($dir . '/stop_sending.flag');
    rmdir($dir);
}
$rollbackId = deleteSourceFixture();
deleteRuleFixture($rollbackId);
Db::run("INSERT INTO credential_captures (tenant_id, campaign_id, tracking_id, auth_type, nonce, ciphertext)
    VALUES (1, ?, '3333333333', 'box', 'synthetic-nonce', 'synthetic-ciphertext')", [$rollbackId]);
Db::run("CREATE TEMP TRIGGER reject_rule_pause BEFORE UPDATE ON campaign_automations BEGIN SELECT RAISE(ABORT, 'test rollback'); END");
$failed = false;
try {
    call_handler('campaigns_handle_delete', ['id' => $rollbackId]);
} catch (PDOException) {
    $failed = true;
} finally {
    Db::run('DROP TRIGGER reject_rule_pause');
}
check($failed && Db::one('SELECT deleted_at FROM campaigns WHERE id=?', [$rollbackId])['deleted_at'] === null, 'rule停止失敗時はcampaign論理削除もrollbackする');
check(Db::one('SELECT id FROM credential_captures WHERE campaign_id=?', [$rollbackId]) !== null,
    'campaign削除失敗時は暗号文の消去もrollbackする');
echo "ALL TESTS PASSED\n";
