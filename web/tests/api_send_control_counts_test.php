<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

tet2_test_boot();
load_api('send_control');

/**
 * send_control_counts は送信件数を DB の campaign_targets から集計する。
 * send_status.json のプロセス単位カウンタではなくキャンペーン全体の累計を
 * 返すことを保証する(2026-08-20 の 945 vs 1546 表示食い違いの回帰防止)。
 */
function seedCampaignWithTargets(int $sentCount, int $pendingCount): int
{
    $id = Db::insert(
        'INSERT INTO campaigns (tenant_id, name, status, from_address, link_mode, send_mode, content_delivery, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        [1, 'Counts Source', 'paused', 'counts@example.test', 'link', 'normal', 'distribute', 1]
    );
    // target_id は boot が用意する実在行(1..3)を循環使用し FK を満たす。
    // (campaign_id, target_id, content_no) の UNIQUE 制約は content_no を
    // 行ごとにユニークにして回避する(koban を流用)。集計対象は send_status のみ。
    $koban = 1;
    for ($i = 0; $i < $sentCount; $i++, $koban++) {
        Db::run(
            'INSERT INTO campaign_targets (campaign_id, target_id, tracking_id, koban, send_status, content_no)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$id, ($koban % 3) + 1, 'S' . $id . '_' . $koban, $koban, 'sent', $koban]
        );
    }
    for ($i = 0; $i < $pendingCount; $i++, $koban++) {
        Db::run(
            'INSERT INTO campaign_targets (campaign_id, target_id, tracking_id, koban, send_status, content_no)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$id, ($koban % 3) + 1, 'P' . $id . '_' . $koban, $koban, 'pending', $koban]
        );
    }
    return $id;
}

echo "=== send_control_counts (DB集計) ===\n";

// 分割送信の状況(sent 1546 相当を圧縮: sent 6 / pending 4)を模す。
$id = seedCampaignWithTargets(6, 4);
$counts = send_control_counts($id);
check($counts['total'] === 10, 'total は全 campaign_targets 件数(sent+pending)');
check($counts['sent'] === 6, 'sent は send_status=sent の件数(累計)');

// 全件送信済み。
$doneId = seedCampaignWithTargets(5, 0);
$counts = send_control_counts($doneId);
check($counts['total'] === 5 && $counts['sent'] === 5, '全送信済みは total=sent');

// 未送信のみ。
$freshId = seedCampaignWithTargets(0, 3);
$counts = send_control_counts($freshId);
check($counts['total'] === 3 && $counts['sent'] === 0, '未送信のみは sent=0');

// 対象者ゼロ(SUMがNULLになるケースの安全性)。
$emptyId = seedCampaignWithTargets(0, 0);
$counts = send_control_counts($emptyId);
check($counts['total'] === 0 && $counts['sent'] === 0, '対象ゼロでも 0/0 を返す(NULL安全)');

echo "ALL TESTS PASSED\n";
