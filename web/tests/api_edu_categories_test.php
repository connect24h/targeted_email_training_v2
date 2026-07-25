<?php
declare(strict_types=1);

/**
 * edu_categories API の回帰テスト。
 * EC-1..EC-12 のシナリオをカバー。
 */

require_once __DIR__ . '/helpers.php';

tet2_test_boot();
load_api('edu_categories');

$tenantId = current_user()['tenant_id'];

// ---- シード: テスト用カテゴリ・設問を用意 ----
// 削除テスト(EC-10)用: 設問なしカテゴリ
Db::run(
    "INSERT INTO edu_categories (tenant_id, name, slug, is_active, is_shared) VALUES (?, 'テスト削除用', 'test-delete-unused', 1, 0)",
    [$tenantId]
);
$unusedCatId = (int) Db::one(
    'SELECT id FROM edu_categories WHERE tenant_id = ? AND slug = ?',
    [$tenantId, 'test-delete-unused']
)['id'];

// 削除テスト(EC-11)用: 設問ありカテゴリ
Db::run(
    "INSERT INTO edu_categories (tenant_id, name, slug, is_active, is_shared) VALUES (?, 'テスト設問あり', 'test-has-questions', 1, 0)",
    [$tenantId]
);
$usedCatId = (int) Db::one(
    'SELECT id FROM edu_categories WHERE tenant_id = ? AND slug = ?',
    [$tenantId, 'test-has-questions']
)['id'];
Db::run(
    "INSERT INTO edu_questions (tenant_id, category_id, title, question_type, options, correct_answer, difficulty, is_active, is_shared)
     VALUES (?, ?, 'テスト設問', 'single_choice', '[\"A\",\"B\"]', '[0]', 1, 1, 0)",
    [$tenantId, $usedCatId]
);

// fork テスト(EC-12)用: 共有カテゴリ(tenant_id = NULL)
Db::run(
    "INSERT INTO edu_categories (tenant_id, name, slug, is_active, is_shared) VALUES (NULL, '共有フォーク元', 'shared-fork-src', 1, 1)"
);
$sharedCatId = (int) Db::one(
    "SELECT id FROM edu_categories WHERE tenant_id IS NULL AND slug = 'shared-fork-src'"
)['id'];
// 共有カテゴリに設問を1件追加(fork でコピーされるか確認)
Db::run(
    "INSERT INTO edu_questions (tenant_id, category_id, title, question_type, options, correct_answer, difficulty, is_active, is_shared)
     VALUES (NULL, ?, '共有設問', 'single_choice', '[\"X\",\"Y\"]', '[1]', 2, 1, 1)",
    [$sharedCatId]
);

// ---- EC-1: create name+slug valid → 201 ----
$r = call_handler('edu_cat_handle_create', ['name' => 'テストカテゴリ', 'slug' => 'test-cat-001'], 'operator');
check($r['code'] === 201, 'EC-1: create valid name+slug → 201');
check(($r['payload']['category']['slug'] ?? '') === 'test-cat-001', 'EC-1: slug が保存される');
$createdCatId = (int) $r['payload']['category']['id'];

// ---- EC-2: create duplicate slug → 409 ----
// load_api は try ブロックを除去するため、UNIQUE 制約違反は PDOException として飛ぶ。
// groups_test と同じパターンでローカル catch して 409 に変換する。
try {
    $r = call_handler('edu_cat_handle_create', ['name' => '重複カテゴリ', 'slug' => 'test-cat-001'], 'operator');
    $ec2Code = $r['code'];
} catch (PDOException $e) {
    $ec2Code = str_contains($e->getMessage(), 'UNIQUE') ? 409 : 500;
}
check($ec2Code === 409, 'EC-2: duplicate slug → 409');

// ---- EC-3: create slug with uppercase → 400 ----
$r = call_handler('edu_cat_handle_create', ['name' => '大文字スラッグ', 'slug' => 'InvalidSlug'], 'operator');
check($r['code'] === 400, 'EC-3: slug with uppercase → 400');

// ---- EC-5: create shared=true with superadmin → 201 ----
$r = call_handler('edu_cat_handle_create', [
    'name'   => '共有カテゴリ新規',
    'slug'   => 'shared-cat-new-' . getmypid(),
    'shared' => true,
], 'superadmin');
check($r['code'] === 201, 'EC-5: create shared=true with superadmin → 201');
check((int) ($r['payload']['category']['is_shared'] ?? 0) === 1, 'EC-5: is_shared=1 が保存される');

// ---- EC-6: list → 200, 共有+自テナントのカテゴリが含まれる ----
$_GET = [];
$r = call_handler('edu_cat_handle_list', [], 'viewer');
check($r['code'] === 200, 'EC-6: list → 200');
check(isset($r['payload']['categories']), 'EC-6: categories キーが存在する');
$cats = $r['payload']['categories'];
$foundOwn    = false;
$foundShared = false;
foreach ($cats as $c) {
    if ((int) $c['id'] === $createdCatId) {
        $foundOwn = true;
    }
    if ((int) $c['id'] === $sharedCatId) {
        $foundShared = true;
    }
}
check($foundOwn, 'EC-6: 自テナントのカテゴリが一覧に含まれる');
check($foundShared, 'EC-6: 共有カテゴリが一覧に含まれる');

// ---- EC-7: update name → 200 ----
$r = call_handler('edu_cat_handle_update', ['id' => $createdCatId, 'name' => '更新済みカテゴリ'], 'operator');
check($r['code'] === 200, 'EC-7: update name → 200');
check(($r['payload']['category']['name'] ?? '') === '更新済みカテゴリ', 'EC-7: name が更新される');

// ---- EC-10: delete unused category → 200 ----
$r = call_handler('edu_cat_handle_delete', ['id' => $unusedCatId], 'operator');
check($r['code'] === 200, 'EC-10: delete unused category → 200');
check(($r['payload']['success'] ?? false) === true, 'EC-10: success=true');
$deleted = Db::one('SELECT id FROM edu_categories WHERE id = ?', [$unusedCatId]);
check($deleted === null, 'EC-10: カテゴリが実際に削除される');

// ---- EC-11: delete category with questions → 409 ----
$r = call_handler('edu_cat_handle_delete', ['id' => $usedCatId], 'operator');
check($r['code'] === 409, 'EC-11: delete category with questions → 409');

// ---- EC-12: fork shared category → 201 ----
$r = call_handler('edu_cat_handle_fork', ['id' => $sharedCatId], 'operator');
check($r['code'] === 201, 'EC-12: fork shared category → 201');
$forkedId = (int) $r['payload']['category']['id'];
check($forkedId > 0, 'EC-12: fork で新しい category_id が返る');
// フォーク先は自テナント所有
$forkedRow = Db::one('SELECT tenant_id FROM edu_categories WHERE id = ?', [$forkedId]);
check((int) $forkedRow['tenant_id'] === $tenantId, 'EC-12: フォーク先は自テナント所有');
// 共有設問もコピーされているか
$copiedQ = Db::one('SELECT id FROM edu_questions WHERE category_id = ?', [$forkedId]);
check($copiedQ !== null, 'EC-12: 共有カテゴリの設問もコピーされる');

echo "ALL TESTS PASSED\n";
