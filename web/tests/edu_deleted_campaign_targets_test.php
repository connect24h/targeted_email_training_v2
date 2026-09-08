<?php
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';
tet2_test_boot();
load_api('edu_deliveries');
require_once __DIR__ . '/../lib/EduAutoEnroll.php';
$tenantId = (int) current_user()['tenant_id'];
$deliveryId = Db::insert("INSERT INTO edu_deliveries (tenant_id,title,created_at) VALUES (?,'抽出検証','2026-01-01 00:00:00')", [$tenantId]);
$campaigns = $targets = [];
foreach ([null, '2026-07-02 00:00:00'] as $i => $deletedAt) {
    $campaigns[$i] = Db::insert("INSERT INTO campaigns (tenant_id,name,status,deleted_at) VALUES (?,'抽出用','done',?)", [$tenantId,$deletedAt]);
    $targets[$i] = Db::insert('INSERT INTO targets (tenant_id,email) VALUES (?,?)', [$tenantId,"deleted-campaign-$i@example.test"]);
    $trackingId = '900000000' . $i;
    Db::run('INSERT INTO campaign_targets (campaign_id,target_id,tracking_id,koban) VALUES (?,?,?,?)', [$campaigns[$i],$targets[$i],$trackingId,$targets[$i]]);
    Db::run("INSERT INTO events (tenant_id,campaign_id,tracking_id,event_type,occurred_at,source) VALUES (?,?,?,'click','2026-07-01 00:00:00','apache_access')", [$tenantId,$campaigns[$i],$trackingId]);
}
$historyId = Db::insert("INSERT INTO edu_assignments (tenant_id,delivery_id,target_id,access_token,status) VALUES (?,?,?,'33345678901234567890123456789012','completed')", [$tenantId,$deliveryId,$targets[1]]);
$extract = new ReflectionMethod(EduAutoEnroll::class, 'failerTargetIds');
foreach ([null, $campaigns[0], $campaigns[1]] as $campaignId) {
    $expected = $campaignId === $campaigns[1] ? [] : [$targets[0]];
    $ids = $extract->invoke(null, $tenantId, $campaignId, $deliveryId);
    check($ids === $expected, '自動追加: 削除済キャンペーン除外 campaign=' . ($campaignId ?? 'all'));
    if ($campaignId === $campaigns[1]) {
        $r = call_handler('edu_d_resolve_targets', [], 'operator', [['target_type' => 'risk', 'phish_campaign_id' => $campaignId], $tenantId]);
        check($r['code'] === 404, '手動risk抽出: 削除済キャンペーンの明示指定を拒否');
    } else {
        $ids = edu_d_resolve_targets(['target_type' => 'risk', 'phish_campaign_id' => $campaignId], $tenantId);
        check($ids === $expected, '手動risk抽出: 削除済キャンペーン除外 campaign=' . ($campaignId ?? 'all'));
    }
}
check(Db::one('SELECT status FROM edu_assignments WHERE id=?', [$historyId])['status'] === 'completed', '既存の教育完了履歴は保持');
check((int) Db::one('SELECT COUNT(*) AS n FROM events WHERE campaign_id IN (?,?)', $campaigns)['n'] === 2, '既存訓練イベントは保持');
echo "ALL TESTS PASSED\n";
