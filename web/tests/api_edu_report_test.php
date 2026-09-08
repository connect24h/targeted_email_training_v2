<?php
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';
tet2_test_boot();
load_api('edu_report');
$tenantId = (int) current_user()['tenant_id'];
Db::run("INSERT INTO edu_deliveries (tenant_id, title) VALUES (?, '受講状態の集計')", [$tenantId]);
$id = (int) Db::one('SELECT MAX(id) AS id FROM edu_deliveries')['id'];
$r = call_handler('edu_rep_handle_deliveries');
check($r['code'] === 200, '教育配信レポートを取得できる');
$row = array_values(array_filter($r['payload']['deliveries'], fn($d) => $d['id'] === $id))[0];
check(($row['started_count'] ?? null) === 0, '割当なしの開始数は整数0');
foreach (['assigned', 'started', 'completed'] as $i => $status) {
    Db::run("INSERT INTO targets (tenant_id, email, name) VALUES (?, ?, ?)", [$tenantId, "edu-report-$i@example.test", "教育集計$i"]);
    $targetId = (int) Db::one('SELECT MAX(id) AS id FROM targets')['id'];
    Db::run('INSERT INTO edu_assignments (tenant_id, delivery_id, target_id, access_token, status) VALUES (?, ?, ?, ?, ?)', [$tenantId, $id, $targetId, str_pad((string) ($i + 1), 32, '0', STR_PAD_LEFT), $status]);
}
$r = call_handler('edu_rep_handle_deliveries');
$row = array_values(array_filter($r['payload']['deliveries'], fn($d) => $d['id'] === $id))[0];
check($row['assigned'] === 3, '全割当3件を維持');
check($row['started_count'] === 1, '受講中のみ開始数に数える');
check($row['completed'] === 1, '完了数は別に維持');
$_GET['tenant_id'] = $tenantId + 1;
$r = call_handler('edu_rep_handle_deliveries');
check($r['code'] === 403, '他テナントの集計を拒否');
echo "ALL TESTS PASSED\n";
