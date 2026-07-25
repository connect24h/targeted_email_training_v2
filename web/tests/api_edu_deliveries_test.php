<?php
declare(strict_types=1);

/**
 * edu_deliveries API の回帰テスト。
 * ED-1..ED-11, ER-3, ER-4 のシナリオをカバー。
 */

require_once __DIR__ . '/helpers.php';

tet2_test_boot();
load_api('edu_deliveries');

$tenantId = current_user()['tenant_id'];
$actor    = current_user();

// ---- シード: カテゴリ・設問・対象者・グループを用意 ----

// カテゴリ
Db::run(
    "INSERT INTO edu_categories (tenant_id, name, slug, is_active, is_shared) VALUES (?, 'ED テスト用カテゴリ', 'ed-test-cat-" . getmypid() . "', 1, 0)",
    [$tenantId]
);
$catId = (int) Db::one(
    'SELECT id FROM edu_categories WHERE tenant_id = ? ORDER BY id DESC LIMIT 1',
    [$tenantId]
)['id'];

// 設問(difficulty=2)
Db::run(
    "INSERT INTO edu_questions (tenant_id, category_id, title, question_type, options, correct_answer, difficulty, is_active, is_shared)
     VALUES (?, ?, 'ED テスト設問', 'single_choice', '[\"A\",\"B\"]', '[0]', 2, 1, 0)",
    [$tenantId, $catId]
);
$questionId = (int) Db::one(
    'SELECT id FROM edu_questions WHERE category_id = ? ORDER BY id DESC LIMIT 1',
    [$catId]
)['id'];

// 対象者: テナント内に active target が必要(all ターゲット用)
$target = Db::one("SELECT id FROM targets WHERE tenant_id = ? AND status = 'active' LIMIT 1", [$tenantId]);
if ($target === null) {
    Db::run(
        "INSERT INTO targets (tenant_id, email, name, status) VALUES (?, 'ed_test@example.com', 'ED テスト対象', 'active')",
        [$tenantId]
    );
}

// グループ(group target_type テスト用)
$group = Db::one('SELECT id FROM groups WHERE tenant_id = ? LIMIT 1', [$tenantId]);
if ($group === null) {
    Db::run("INSERT INTO groups (tenant_id, name, kind) VALUES (?, 'ED テストグループ', 'custom')", [$tenantId]);
    $group = Db::one('SELECT id FROM groups WHERE tenant_id = ? ORDER BY id DESC LIMIT 1', [$tenantId]);
}
$groupId = (int) $group['id'];

// ---- ED-1: elearning + all + pass_score=70 → 201 ----
$r = call_handler('edu_d_handle_create', [
    'title'         => 'ED-1 elearning テスト',
    'delivery_type' => 'elearning',
    'target_type'   => 'all',
    'pass_score'    => 70,
], 'operator');
check($r['code'] === 201, 'ED-1: elearning + all + pass_score=70 → 201');
check(($r['payload']['delivery']['delivery_type'] ?? '') === 'elearning', 'ED-1: delivery_type が保存される');
check((int) ($r['payload']['delivery']['pass_score'] ?? 0) === 70, 'ED-1: pass_score=70 が保存される');
$ed1Id = (int) $r['payload']['delivery']['id'];

// ---- ED-2: elearning + all + no pass_score → 400 ----
$r = call_handler('edu_d_handle_create', [
    'title'         => 'ED-2 elearning no pass_score',
    'delivery_type' => 'elearning',
    'target_type'   => 'all',
], 'operator');
check($r['code'] === 400, 'ED-2: elearning without pass_score → 400');

// ---- ED-6: awareness_quiz + all → 201 ----
$r = call_handler('edu_d_handle_create', [
    'title'         => 'ED-6 awareness_quiz テスト',
    'delivery_type' => 'awareness_quiz',
    'target_type'   => 'all',
], 'operator');
check($r['code'] === 201, 'ED-6: awareness_quiz + all → 201');
$ed6Id = (int) $r['payload']['delivery']['id'];

// ---- ED-8: invalid delivery_type → 400 ----
$r = call_handler('edu_d_handle_create', [
    'title'         => 'ED-8 不正タイプ',
    'delivery_type' => 'invalid_type',
    'target_type'   => 'all',
], 'operator');
check($r['code'] === 400, 'ED-8: invalid delivery_type → 400');

// ---- ED-9: invalid target_type → 400 ----
$r = call_handler('edu_d_handle_create', [
    'title'         => 'ED-9 不正ターゲット',
    'delivery_type' => 'awareness_quiz',
    'target_type'   => 'invalid_target',
], 'operator');
check($r['code'] === 400, 'ED-9: invalid target_type → 400');

// ---- ED-10: difficulty_range=[1,3] → 201 ----
$r = call_handler('edu_d_handle_create', [
    'title'            => 'ED-10 difficulty_range テスト',
    'delivery_type'    => 'awareness_quiz',
    'target_type'      => 'all',
    'difficulty_range' => [1, 3],
], 'operator');
check($r['code'] === 201, 'ED-10: difficulty_range=[1,3] → 201');

// ---- ED-11: difficulty_range=[3,1] (min>max) → 400 ----
$r = call_handler('edu_d_handle_create', [
    'title'            => 'ED-11 不正 difficulty_range',
    'delivery_type'    => 'awareness_quiz',
    'target_type'      => 'all',
    'difficulty_range' => [3, 1],
], 'operator');
check($r['code'] === 400, 'ED-11: difficulty_range=[3,1] (min>max) → 400');

// ---- ER-3: update draft → 200 ----
$r = call_handler('edu_d_handle_update', [
    'id'        => $ed1Id,
    'pass_score' => 80,
], 'operator');
check($r['code'] === 200, 'ER-3: update draft delivery → 200');
check((int) ($r['payload']['delivery']['pass_score'] ?? 0) === 80, 'ER-3: pass_score が更新される');

// ---- ER-4: update running → 409 ----
// ED-6 の配信を手動で running に変更して編集を試みる
Db::run("UPDATE edu_deliveries SET status = 'running' WHERE id = ?", [$ed6Id]);
$r = call_handler('edu_d_handle_update', [
    'id'    => $ed6Id,
    'title' => '起動済み更新試み',
], 'operator');
check($r['code'] === 409, 'ER-4: update running delivery → 409');

echo "ALL TESTS PASSED\n";
