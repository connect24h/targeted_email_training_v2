<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
tet2_test_boot();
require_once __DIR__ . '/../lib/PipelineRunner.php';

$dataDir = sys_get_temp_dir() . '/tet2-test-delivery-' . getmypid();
register_shutdown_function(static function () use ($dataDir): void {
    foreach (glob($dataDir . '/*') ?: [] as $path) {
        if (is_file($path)) {
            @unlink($path);
        }
    }
    @rmdir($dataDir . '/logs');
    @rmdir($dataDir . '/Attachment');
    @rmdir($dataDir);
});

Db::run(
    "UPDATE campaigns SET is_test=1, test_redirect_emails=?, data_dir=?,
        from_address='sender@example.test', beacon_base='https://example.test'
     WHERE id=2",
    ['tester1@example.test,tester2@example.test,tester3@example.test', $dataDir]
);
Db::run('DELETE FROM campaign_targets WHERE campaign_id=2');
for ($contentNo = 1; $contentNo <= 4; $contentNo++) {
    Db::run(
        'INSERT INTO campaign_contents
         (campaign_id,content_no,subject_template_id,body_template_id,link_mode)
         VALUES (2,?,?,?,?)',
        [$contentNo, 1, 2, 'link']
    );
}
for ($targetNo = 1; $targetNo <= 5; $targetNo++) {
    if ($targetNo > 2) {
        Db::run(
            'INSERT INTO targets (id,tenant_id,email,name,status) VALUES (?,1,?,?,?)',
            [$targetNo + 10, "target{$targetNo}@example.test", "Target {$targetNo}", 'active']
        );
    }
    $targetId = $targetNo <= 2 ? $targetNo : $targetNo + 10;
    for ($contentNo = 1; $contentNo <= 4; $contentNo++) {
        Db::run(
            'INSERT INTO campaign_targets
             (campaign_id,target_id,tracking_id,koban,content_no,send_status)
             VALUES (2,?,?,?,?,?)',
            [$targetId, sprintf('%02d%08d', $targetNo, $contentNo), $targetNo, $contentNo, 'pending']
        );
    }
}

PipelineRunner::generateCsv(2);
$rows = array_map('str_getcsv', file($dataDir . '/list.csv', FILE_IGNORE_NEW_LINES));
$header = array_shift($rows);
$toIndex = array_search('送信先情報', $header, true);
$trackingIndex = array_search('乱数列', $header, true);
$recipients = array_column($rows, $toIndex);
$trackingIds = array_column($rows, $trackingIndex);

// TESTでも本番対象の全行(5人×4content=20行)を生成する。差し込みデータと
// tracking_id は本番同等のまま、実際の送信先(To)だけをテスト宛先へ均等分配する。
// これが元の仕様であり、件数を絞ると「全職員分のデータを送るテスト」ができない。
check(count($rows) === 20, 'TESTでも本番対象の全行を生成する(5人×4content)');

// 20行を3宛先へ i % 3 で分配するので 7 / 7 / 6 になる
$expected = ['tester1@example.test' => 7, 'tester2@example.test' => 7, 'tester3@example.test' => 6];
foreach ($expected as $recipient => $count) {
    check(
        count(array_keys($recipients, $recipient, true)) === $count,
        "{$recipient}へ均等分配される({$count}通)"
    );
}
check(count(array_unique($trackingIds)) === 20, 'TEST行もtracking IDを重複させない');

echo "ALL TESTS PASSED\n";
