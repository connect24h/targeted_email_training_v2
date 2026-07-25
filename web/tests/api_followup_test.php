<?php
declare(strict_types=1);

/**
 * followup API の回帰テスト。
 * FU-1: failures with valid campaign_id → 200
 * FU-3: to_group with valid campaign + group → 200
 * FU-4: to_group re-run (idempotent) → 200
 */

require_once __DIR__ . '/helpers.php';

tet2_test_boot();
load_api('followup');

$tenantId = current_user()['tenant_id'];

// --- Seed: campaign, targets, campaign_targets, events, group ---

// キャンペーン作成
Db::run(
    'INSERT INTO campaigns (tenant_id, name, status) VALUES (?, ?, ?)',
    [$tenantId, 'FU_TEST_CAMPAIGN', 'done']
);
$campaignId = (int) Db::one(
    'SELECT id FROM campaigns WHERE tenant_id = ? AND name = ? ORDER BY id DESC LIMIT 1',
    [$tenantId, 'FU_TEST_CAMPAIGN']
)['id'];
check($campaignId > 0, 'seed: キャンペーン作成');

// targets 2人
Db::run('INSERT INTO targets (tenant_id, email, name) VALUES (?, ?, ?)', [$tenantId, 'fu_t1@example.com', 'FU対象1']);
$target1Id = (int) Db::one('SELECT id FROM targets WHERE tenant_id = ? AND email = ?', [$tenantId, 'fu_t1@example.com'])['id'];

Db::run('INSERT INTO targets (tenant_id, email, name) VALUES (?, ?, ?)', [$tenantId, 'fu_t2@example.com', 'FU対象2']);
$target2Id = (int) Db::one('SELECT id FROM targets WHERE tenant_id = ? AND email = ?', [$tenantId, 'fu_t2@example.com'])['id'];

// campaign_targets
$trk1 = 'FUTRK001';
$trk2 = 'FUTRK002';
Db::run(
    'INSERT INTO campaign_targets (campaign_id, target_id, tracking_id, send_status) VALUES (?, ?, ?, ?)',
    [$campaignId, $target1Id, $trk1, 'sent']
);
Db::run(
    'INSERT INTO campaign_targets (campaign_id, target_id, tracking_id, send_status) VALUES (?, ?, ?, ?)',
    [$campaignId, $target2Id, $trk2, 'sent']
);

// events: target1 は click のみ(auth なし = フォローアップ対象)
// target2 は click + auth(auth あり = フォローアップ対象だが auth 済み)
Db::run(
    'INSERT INTO events (tenant_id, campaign_id, tracking_id, event_type, occurred_at) VALUES (?,?,?,?,?)',
    [$tenantId, $campaignId, $trk1, 'click', '2026-07-10 09:00:00']
);
Db::run(
    'INSERT INTO events (tenant_id, campaign_id, tracking_id, event_type, occurred_at) VALUES (?,?,?,?,?)',
    [$tenantId, $campaignId, $trk2, 'click', '2026-07-10 10:00:00']
);
Db::run(
    'INSERT INTO events (tenant_id, campaign_id, tracking_id, event_type, occurred_at) VALUES (?,?,?,?,?)',
    [$tenantId, $campaignId, $trk2, 'auth', '2026-07-10 10:01:00']
);

// group 作成
Db::run(
    'INSERT INTO groups (tenant_id, name, kind) VALUES (?, ?, ?)',
    [$tenantId, 'FU_TEST_GROUP', 'custom']
);
$groupId = (int) Db::one(
    'SELECT id FROM groups WHERE tenant_id = ? AND name = ?',
    [$tenantId, 'FU_TEST_GROUP']
)['id'];
check($groupId > 0, 'seed: グループ作成');

// -----------------------------------------------------------------------
// FU-1: failures with valid campaign_id → 200
// -----------------------------------------------------------------------
$_GET = ['action' => 'failures', 'campaign_id' => (string) $campaignId];
$r = call_handler('followup_handle_failures', [], 'viewer');
check($r['code'] === 200, 'FU-1: failures with valid campaign_id → 200');
check(($r['payload']['success'] ?? false) === true, 'FU-1: success=true');
check(array_key_exists('failures', $r['payload']), 'FU-1: failures キーが存在する');
check(array_key_exists('count', $r['payload']), 'FU-1: count キーが存在する');
check((int) $r['payload']['campaign_id'] === $campaignId, 'FU-1: campaign_id が一致する');

// -----------------------------------------------------------------------
// FU-3: to_group with valid campaign + group → 200
// -----------------------------------------------------------------------
$r = call_handler('followup_handle_to_group', [
    'campaign_id' => $campaignId,
    'group_id'    => $groupId,
], 'operator');
check($r['code'] === 200, 'FU-3: to_group with valid campaign + group → 200');
check(($r['payload']['success'] ?? false) === true, 'FU-3: success=true');
check(array_key_exists('added', $r['payload']), 'FU-3: added キーが存在する');
check((int) $r['payload']['group_id'] === $groupId, 'FU-3: group_id が一致する');

// -----------------------------------------------------------------------
// FU-4: to_group re-run (idempotent) → 200
// -----------------------------------------------------------------------
$r = call_handler('followup_handle_to_group', [
    'campaign_id' => $campaignId,
    'group_id'    => $groupId,
], 'operator');
check($r['code'] === 200, 'FU-4: to_group re-run (idempotent) → 200');
check(($r['payload']['success'] ?? false) === true, 'FU-4: success=true');
// 再実行では added=0(INSERT OR IGNORE による冪等性)
check(isset($r['payload']['added']), 'FU-4: added キーが存在する');

echo "ALL TESTS PASSED\n";
