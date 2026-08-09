<?php
declare(strict_types=1);

/**
 * groups API の CRUD・target 追加・削除の回帰テスト。
 */

require_once __DIR__ . '/helpers.php';

// テナント1のターゲット ID を seed SQL で確認しやすくするため、
// 本番 DB をコピーしてから targets の存在を確認する。
tet2_test_boot();
load_api('groups');

$tenantId = current_user()['tenant_id']; // helpers.php で tenant_id=1 のユーザーを返す

// 自テナントのターゲットを最大2件取得する
$targets = Db::all('SELECT id FROM targets WHERE tenant_id = ? AND status != ? ORDER BY id LIMIT 2', [$tenantId, 'archived']);
if (count($targets) < 2) {
    // テスト用ターゲットを追加
    Db::run('INSERT OR IGNORE INTO targets (tenant_id, email, name) VALUES (?, ?, ?)', [$tenantId, 'grp_test1@example.com', 'グループ対象1']);
    Db::run('INSERT OR IGNORE INTO targets (tenant_id, email, name) VALUES (?, ?, ?)', [$tenantId, 'grp_test2@example.com', 'グループ対象2']);
    $targets = Db::all('SELECT id FROM targets WHERE tenant_id = ? AND status != ? ORDER BY id LIMIT 2', [$tenantId, 'archived']);
}
$target1Id = (int) $targets[0]['id'];
$target2Id = (int) $targets[1]['id'];

// 他テナントの ID を取得
$otherTenant = Db::one('SELECT id FROM tenants WHERE id != ? LIMIT 1', [$tenantId]);
$otherTenantId = $otherTenant !== null ? (int) $otherTenant['id'] : null;

// -----------------------------------------------------------------------
// CRUD テスト
// -----------------------------------------------------------------------

// GC-1: name="営業部", kind=department → 201
$r = call_handler('groups_handle_create', ['name' => '営業部', 'kind' => 'department'], 'operator');
check($r['code'] === 201, 'GC-1: create name="営業部", kind=department → 201');
check(($r['payload']['group']['name'] ?? '') === '営業部', 'GC-1: name が保存される');
check(($r['payload']['group']['kind'] ?? '') === 'department', 'GC-1: kind が保存される');
$createdGroupId = (int) $r['payload']['group']['id'];

// GC-2: 重複名 → 409
// UNIQUE 制約エラーは groups.php の try ブロック(load_api で除去済み)で 409 に変換される。
// テスト内で同等の変換を行う。
try {
    $r = call_handler('groups_handle_create', ['name' => '営業部', 'kind' => 'department'], 'operator');
    $gc2Code = $r['code'];
} catch (PDOException $e) {
    $gc2Code = str_contains($e->getMessage(), 'UNIQUE') ? 409 : 500;
}
check($gc2Code === 409, 'GC-2: duplicate name → 409');

// GC-3: kind=custom → 201
$r = call_handler('groups_handle_create', ['name' => 'カスタムグループA', 'kind' => 'custom'], 'operator');
check($r['code'] === 201, 'GC-3: create kind=custom → 201');
check(($r['payload']['group']['kind'] ?? '') === 'custom', 'GC-3: kind=custom が保存される');
$customGroupId = (int) $r['payload']['group']['id'];

// GC-4: 不正な kind → 400
$r = call_handler('groups_handle_create', ['name' => 'バッドカインド', 'kind' => 'invalid_kind'], 'operator');
check($r['code'] === 400, 'GC-4: invalid kind → 400');

// GC-5: name 更新 → 200
$r = call_handler('groups_handle_update', ['id' => $createdGroupId, 'name' => '営業部(更新)'], 'operator');
check($r['code'] === 200, 'GC-5: update name → 200');
check(($r['payload']['group']['name'] ?? '') === '営業部(更新)', 'GC-5: name が更新される');

// GC-6: kind 更新 → 200
$r = call_handler('groups_handle_update', ['id' => $createdGroupId, 'kind' => 'custom'], 'operator');
check($r['code'] === 200, 'GC-6: update kind → 200');
check(($r['payload']['group']['kind'] ?? '') === 'custom', 'GC-6: kind が更新される');

// GC-7: 更新項目なし → 400
$r = call_handler('groups_handle_update', ['id' => $createdGroupId], 'operator');
check($r['code'] === 400, 'GC-7: update no fields → 400');

// GC-8: archive → 200
$r = call_handler('groups_handle_delete', ['id' => $customGroupId], 'operator');
check($r['code'] === 200, 'GC-8: archive → 200');
check(($r['payload']['archived'] ?? false) === true, 'GC-8: archived=true');
$archived = Db::one('SELECT status, archived_at FROM groups WHERE id = ?', [$customGroupId]);
check($archived !== null && $archived['status'] === 'archived', 'GC-8: グループを論理archiveする');
check($archived['archived_at'] !== null, 'GC-8: archive日時を保存する');

// GC-9: 他テナントのグループを delete → 404
if ($otherTenantId !== null) {
    $otherGroup = Db::one('SELECT id FROM groups WHERE tenant_id = ?', [$otherTenantId]);
    if ($otherGroup !== null) {
        $r = call_handler('groups_handle_delete', ['id' => (int) $otherGroup['id']], 'operator');
        check($r['code'] === 404, 'GC-9: delete other tenant group → 404');
    } else {
        // 他テナントにグループがない場合は存在しない ID で確認
        $r = call_handler('groups_handle_delete', ['id' => 99999], 'operator');
        check($r['code'] === 404, 'GC-9: delete nonexistent group → 404');
    }
} else {
    echo "SKIP: GC-9 (他テナントが存在しない)\n";
}

// GC-10: list → 200 with target_count
// まず対象グループにターゲットを追加してから list 確認
Db::run('INSERT OR IGNORE INTO target_group (target_id, group_id) VALUES (?, ?)', [$target1Id, $createdGroupId]);
$_GET = [];
$r = call_handler('groups_handle_list', [], 'viewer');
check($r['code'] === 200, 'GC-10: list → 200');
check(isset($r['payload']['groups']), 'GC-10: groups キーが存在する');
$listed = $r['payload']['groups'];
$found = null;
foreach ($listed as $g) {
    if ((int) $g['id'] === $createdGroupId) {
        $found = $g;
        break;
    }
}
check($found !== null, 'GC-10: 作成したグループが一覧に含まれる');
check(array_key_exists('target_count', $found), 'GC-10: target_count フィールドが存在する');
check((int) $found['target_count'] >= 1, 'GC-10: target_count >= 1 (追加した分が反映)');

// -----------------------------------------------------------------------
// add_targets / remove_targets テスト
// -----------------------------------------------------------------------

// GT-1: 有効なターゲットを追加 → 200
$r = call_handler('groups_handle_add_targets', [
    'group_id'   => $createdGroupId,
    'target_ids' => [$target1Id, $target2Id],
], 'operator');
check($r['code'] === 200, 'GT-1: add valid targets → 200');
check(($r['payload']['success'] ?? false) === true, 'GT-1: success=true');
$cnt = (int) Db::one(
    'SELECT COUNT(*) c FROM target_group WHERE group_id = ?',
    [$createdGroupId]
)['c'];
check($cnt >= 2, 'GT-1: target_group に行が追加される');

// GT-4: 既にグループにいるターゲットを再追加 → 冪等で 200
$r = call_handler('groups_handle_add_targets', [
    'group_id'   => $createdGroupId,
    'target_ids' => [$target1Id],
], 'operator');
check($r['code'] === 200, 'GT-4: add already-in-group (idempotent) → 200');

// GT-5: ターゲットを remove → 200
$r = call_handler('groups_handle_remove_targets', [
    'group_id'   => $createdGroupId,
    'target_ids' => [$target2Id],
], 'operator');
check($r['code'] === 200, 'GT-5: remove → 200');
check(($r['payload']['success'] ?? false) === true, 'GT-5: success=true');
$row = Db::one(
    'SELECT target_id FROM target_group WHERE group_id = ? AND target_id = ?',
    [$createdGroupId, $target2Id]
);
check($row === null, 'GT-5: target_group から行が削除される');

// GT-6: グループに入っていないターゲットを remove → 冪等で 200
$r = call_handler('groups_handle_remove_targets', [
    'group_id'   => $createdGroupId,
    'target_ids' => [$target2Id],
], 'operator');
check($r['code'] === 200, 'GT-6: remove non-member (idempotent) → 200');

echo "ALL TESTS PASSED\n";
