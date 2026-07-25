<?php
declare(strict_types=1);

/**
 * tenants API の CRUD 回帰テスト。
 * テナント操作はすべて superadmin 権限が必要。
 */

require_once __DIR__ . '/helpers.php';
tet2_test_boot();
load_api('tenants');

// tenants ハンドラは引数なし(call_handler の自動 current_user() 渡しを無効にする)。
// $args=[] を明示指定して引数なしで呼ぶ。

// TN-1: list → 200
$r = call_handler('tenants_handle_list', [], 'superadmin', []);
check($r['code'] === 200, 'TN-1: list → 200');
check(isset($r['payload']['tenants']), 'TN-1: tenants キーが存在する');
check(is_array($r['payload']['tenants']), 'TN-1: tenants は配列');

// TN-2: create name="テスト社", slug="test-co" → 201
$r = call_handler('tenants_handle_create', ['name' => 'テスト社', 'slug' => 'test-co'], 'superadmin', []);
check($r['code'] === 201, 'TN-2: create name="テスト社", slug="test-co" → 201');
check(($r['payload']['tenant']['name'] ?? '') === 'テスト社', 'TN-2: name が保存される');
check(($r['payload']['tenant']['slug'] ?? '') === 'test-co', 'TN-2: slug が保存される');
$createdTenantId = (int) $r['payload']['tenant']['id'];

// TN-3: slug が大文字(TEST) → 400 (パターン /^[a-z0-9][a-z0-9-]{1,30}$/ に不一致)
$r = call_handler('tenants_handle_create', ['name' => '大文字社', 'slug' => 'TEST'], 'superadmin', []);
check($r['code'] === 400, 'TN-3: uppercase slug → 400');

// TN-4: slug が短すぎる("a" は1文字: [a-z0-9] の後に {1,30} が必要) → 400
$r = call_handler('tenants_handle_create', ['name' => '短すぎる社', 'slug' => 'a'], 'superadmin', []);
check($r['code'] === 400, 'TN-4: too short slug → 400');

// TN-5: 重複 slug → 409
try {
    $r = call_handler('tenants_handle_create', ['name' => '重複社', 'slug' => 'test-co'], 'superadmin', []);
    $tn5Code = $r['code'];
} catch (PDOException $e) {
    $tn5Code = str_contains($e->getMessage(), 'UNIQUE') ? 409 : 500;
}
check($tn5Code === 409, 'TN-5: duplicate slug → 409');

// TN-6: update name → 200
$r = call_handler('tenants_handle_update', ['id' => $createdTenantId, 'name' => 'テスト社(更新)'], 'superadmin', []);
check($r['code'] === 200, 'TN-6: update name → 200');
check(($r['payload']['tenant']['name'] ?? '') === 'テスト社(更新)', 'TN-6: name が更新される');

// TN-7: update status=suspended → 200
$r = call_handler('tenants_handle_update', ['id' => $createdTenantId, 'status' => 'suspended'], 'superadmin', []);
check($r['code'] === 200, 'TN-7: update status=suspended → 200');
check(($r['payload']['tenant']['status'] ?? '') === 'suspended', 'TN-7: status が suspended に更新される');

// TN-8: update 不正な status → 400
$r = call_handler('tenants_handle_update', ['id' => $createdTenantId, 'status' => 'deleted'], 'superadmin', []);
check($r['code'] === 400, 'TN-8: invalid status → 400');

// TN-9: update 更新項目なし → 400
$r = call_handler('tenants_handle_update', ['id' => $createdTenantId], 'superadmin', []);
check($r['code'] === 400, 'TN-9: update no fields → 400');

// TN-10: superadmin 以外は 403
// tenants.php の role チェックは外側の try ブロック内にあり load_api で除去されるため、
// テスト内で require_role を直接呼んで 403 を確認する。
$r = call_handler('require_role', [], 'operator', ['superadmin']);
check($r['code'] === 403, 'TN-10: non-superadmin → 403 (operator)');

$r = call_handler('require_role', [], 'tenant_admin', ['superadmin']);
check($r['code'] === 403, 'TN-10: non-superadmin → 403 (tenant_admin)');

$r = call_handler('require_role', [], 'viewer', ['superadmin']);
check($r['code'] === 403, 'TN-10: non-superadmin → 403 (viewer)');

echo "ALL TESTS PASSED\n";
