<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
tet2_test_boot();
require_once __DIR__ . '/../lib/Scheduler.php';

Db::run(
    "UPDATE campaigns
     SET send_mode='split', split_count=3, split_interval_min=15,
         start_at='2026-08-11 09:00:00', end_at='2026-08-11 18:00:00'
     WHERE id=2"
);
Db::run('UPDATE campaign_targets SET koban=1, content_no=1 WHERE campaign_id=2');
foreach ([2, 3, 4] as $contentNo) {
    Db::run(
        'INSERT INTO campaign_targets
         (campaign_id,target_id,tracking_id,koban,content_no,send_status)
         VALUES (2,1,?,1,?,?)',
        [sprintf('100000000%d', $contentNo), $contentNo, 'pending']
    );
}
foreach ([1, 2, 3, 4] as $contentNo) {
    Db::run(
        'INSERT INTO campaign_targets
         (campaign_id,target_id,tracking_id,koban,content_no,send_status)
         VALUES (2,2,?,2,?,?)',
        [sprintf('200000000%d', $contentNo), $contentNo, 'pending']
    );
}

$count = Scheduler::expand(2);
$batches = Db::all(
    'SELECT koban_from,koban_to FROM send_schedule WHERE campaign_id=2 ORDER BY batch_no'
);

check($count === 2, '対象者2人はcontent行数でなく2つのbatchに分割する');
check($batches === [
    ['koban_from' => 1, 'koban_to' => 1],
    ['koban_from' => 2, 'koban_to' => 2],
], '全content配信でもkoban範囲が重複しない');

echo "ALL TESTS PASSED\n";
