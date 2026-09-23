<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
tet2_test_boot();
load_api('report');

$tenantId = (int) current_user()['tenant_id'];
$campaignId = Db::insert("INSERT INTO campaigns (tenant_id, name, status, credential_capture_approval_ref) VALUES (?, 'close test', 'running', 'synthetic-approval')", [$tenantId]);
$otherId = Db::insert("INSERT INTO campaigns (tenant_id, name, status) VALUES (?, 'other', 'done')", [$tenantId]);
$targetId = Db::insert('INSERT INTO targets (tenant_id, email) VALUES (?, ?)', [$tenantId, 'close@example.test']);
Db::run("INSERT INTO campaign_targets (campaign_id, target_id, tracking_id, send_status) VALUES (?, ?, '0123456789', 'sent')", [$campaignId, $targetId]);
Db::run("INSERT INTO credential_captures (tenant_id, campaign_id, tracking_id, auth_type, nonce, ciphertext) VALUES (?, ?, '0123456789', 'box', 'synthetic', 'synthetic-secret')", [$tenantId, $campaignId]);
Db::run("INSERT INTO credential_captures (tenant_id, campaign_id, tracking_id, auth_type, nonce, ciphertext) VALUES (?, ?, '9876543210', 'box', 'other', 'other-secret')", [$tenantId, $otherId]);

$_GET = ['campaign_id' => (string) $campaignId];
check(call_handler('report_handle_close', [], 'superadmin')['code'] === 409, '未確定はクローズ不可');
check(Db::one('SELECT COUNT(*) AS n FROM credential_captures WHERE campaign_id=?', [$campaignId])['n'] === 1, '拒否時は本文を残す');
check(call_handler('report_handle_commit', [], 'operator')['code'] === 200, 'レポート確定');
check(call_handler('report_handle_close', [], 'tenant_admin')['code'] === 403, '顧客管理者はクローズ不可');
check(call_handler('report_handle_close', [], 'superadmin')['code'] === 409, '配信中はクローズ不可');

Db::run("UPDATE campaigns SET status='done' WHERE id=?", [$campaignId]);
Db::run("INSERT INTO send_schedule (campaign_id, status, scheduled_at) VALUES (?, 'queued', datetime('now'))", [$campaignId]);
check(call_handler('report_handle_close', [], 'superadmin')['code'] === 409, '未処理の送信予定がある場合はクローズ不可');
Db::run("UPDATE send_schedule SET status='done' WHERE campaign_id=?", [$campaignId]);

$snapshotBefore = report_snapshot_of($campaignId, $tenantId);
$result = call_handler('report_handle_close', [], 'superadmin');
check($result['code'] === 200 && ($result['payload']['closed_at'] ?? '') !== '', '確定済みキャンペーンをクローズ');
check(($GLOBALS['__TET2_TEST_AUDIT'][0]['action'] ?? '') === 'campaign.close', 'クローズ操作を監査する');
check(Db::one('SELECT COUNT(*) AS n FROM credential_captures WHERE campaign_id=?', [$campaignId])['n'] === 0, '本文の暗号文を消去');
check(Db::one('SELECT COUNT(*) AS n FROM credential_captures WHERE campaign_id=?', [$otherId])['n'] === 1, '他キャンペーンの本文は保持');
check(report_snapshot_of($campaignId, $tenantId) === $snapshotBefore, '確定済み統計を保持');
check(call_handler('report_handle_close', [], 'superadmin')['code'] === 409, '二重クローズ不可');
check(call_handler('report_handle_uncommit', [], 'tenant_admin')['code'] === 409, 'クローズ後は確定解除不可');
check(call_handler('report_handle_commit', [], 'superadmin')['code'] === 409, 'クローズ後は再確定不可');

Db::run("INSERT INTO events (tenant_id, campaign_id, tracking_id, event_type, occurred_at) VALUES (?, ?, '0123456789', 'click', datetime('now'))", [$tenantId, $campaignId]);
$_GET = ['action' => 'summary', 'campaign_id' => (string) $campaignId];
$summary = call_handler('report_handle_summary', [], 'viewer');
check($summary['payload']['summary']['click_count'] === 0, 'クローズ後の遅延イベントでサマリーは変わらない');
$_GET = ['action' => 'campaigns'];
$campaigns = call_handler('report_handle_campaigns', [], 'viewer');
$closedRow = array_values(array_filter($campaigns['payload']['campaigns'], static fn(array $row): bool => $row['id'] === $campaignId))[0] ?? null;
check($closedRow !== null && $closedRow['click_count'] === 0 && $closedRow['closed_at'] !== null, '一覧も確定値とクローズ状態を返す');

$_GET = ['action' => 'detail', 'campaign_id' => (string) $campaignId];
$detail = call_handler('report_handle_detail', [], 'viewer');
check($detail['code'] === 200 && ($detail['payload']['is_closed'] ?? false) === true, '閲覧画面にクローズ状態を返す');
check($detail['payload']['is_committed'] === true, 'クローズ後も確定統計を返す');
$_GET['start_date'] = '2026-01-01';
check(call_handler('report_handle_detail', [], 'viewer')['code'] === 409, 'クローズ後の期間再集計を拒否');

$otherTenantId = Db::insert("INSERT INTO tenants (name, slug, data_dir) VALUES ('Other tenant', 'other-close', '/tmp/other-close')");
$foreignCampaignId = Db::insert("INSERT INTO campaigns (tenant_id, name, status) VALUES (?, 'foreign', 'done')", [$otherTenantId]);
$_GET = ['campaign_id' => (string) $foreignCampaignId];
check(call_handler('report_handle_close', [], 'superadmin')['code'] === 404, '別tenantのIDをtenant指定なしでは操作できない');

$_GET = ['campaign_id' => (string) $otherId];
check(call_handler('report_handle_commit', [], 'operator')['code'] === 200, 'rollback確認用レポート確定');
Db::run("CREATE TRIGGER close_purge_abort BEFORE DELETE ON credential_captures WHEN OLD.campaign_id={$otherId} BEGIN SELECT RAISE(ABORT, 'synthetic purge failure'); END");
$failed = false;
try { call_handler('report_handle_close', [], 'superadmin'); }
catch (PDOException $error) { $failed = true; }
check($failed, '暗号文削除に失敗した場合はクローズを失敗させる');
check(Db::one('SELECT closed_at FROM campaigns WHERE id=?', [$otherId])['closed_at'] === null, '本文削除失敗時はクローズをrollback');
check(Db::one('SELECT COUNT(*) AS n FROM credential_captures WHERE campaign_id=?', [$otherId])['n'] === 1, 'rollback時は本文を保持');
Db::run('DROP TRIGGER close_purge_abort');
$legacyPayload = json_decode((string) report_snapshot_of($otherId, $tenantId)['payload'], true);
unset($legacyPayload['campaign_summary']);
Db::run('UPDATE campaign_report_snapshots SET payload=? WHERE campaign_id=? AND tenant_id=?',
    [json_encode($legacyPayload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $otherId, $tenantId]);
check(call_handler('report_handle_close', [], 'superadmin')['code'] === 200, '旧版snapshotでもクローズできる');
check(isset(json_decode((string) report_snapshot_of($otherId, $tenantId)['payload'], true)['campaign_summary']),
    '旧版snapshotに固定サマリーを補完する');

load_api('campaigns');
$_GET = [];
check(call_handler('campaigns_handle_cancel', ['id' => $campaignId], 'operator')['code'] === 409, 'クローズ後のstatus変更を拒否');
check(call_handler('campaigns_handle_set_test', ['id' => $campaignId, 'is_test' => 1], 'operator')['code'] === 409, 'クローズ後の本番・テスト分類変更を拒否');
load_api('credential_captures');
check(call_handler('credential_captures_handle_approve', [
    'tenant_id' => $tenantId, 'campaign_id' => $campaignId, 'approval_ref' => 'synthetic-new-approval',
], 'superadmin')['code'] === 409, 'クローズ後の収集再承認を拒否');

echo "ALL TESTS PASSED\n";
