<?php
declare(strict_types=1);

/**
 * edu_questions API の回帰テスト。
 * EQ-1..EQ-14, EQU-1, EQD-1 のシナリオをカバー。
 */

require_once __DIR__ . '/helpers.php';

tet2_test_boot();
load_api('edu_questions');

$tenantId = current_user()['tenant_id'];

// ---- シード: テスト用カテゴリを用意 ----
Db::run(
    "INSERT INTO edu_categories (tenant_id, name, slug, is_active, is_shared) VALUES (?, 'テスト用カテゴリ', 'test-qcat-" . getmypid() . "', 1, 0)",
    [$tenantId]
);
$catId = (int) Db::one(
    'SELECT id FROM edu_categories WHERE tenant_id = ? ORDER BY id DESC LIMIT 1',
    [$tenantId]
)['id'];

// ---- EQ-1: single_choice, 3 options, correct=[1] → 201 ----
$r = call_handler('edu_q_handle_create', [
    'category_id'    => $catId,
    'title'          => 'EQ-1 問題',
    'question_type'  => 'single_choice',
    'options'        => ['選択肢A', '選択肢B', '選択肢C'],
    'correct_answer' => [1],
    'difficulty'     => 1,
], 'operator');
check($r['code'] === 201, 'EQ-1: single_choice 3 options correct=[1] → 201');
check(($r['payload']['question']['question_type'] ?? '') === 'single_choice', 'EQ-1: question_type が保存される');
$eq1Id = (int) $r['payload']['question']['id'];

// ---- EQ-2: single_choice, correct=[0,1] (複数) → 400 ----
$r = call_handler('edu_q_handle_create', [
    'category_id'    => $catId,
    'title'          => 'EQ-2 問題',
    'question_type'  => 'single_choice',
    'options'        => ['A', 'B', 'C'],
    'correct_answer' => [0, 1],
    'difficulty'     => 1,
], 'operator');
check($r['code'] === 400, 'EQ-2: single_choice correct=[0,1] (multiple) → 400');

// ---- EQ-3: true_false, 2 options, correct=[0] → 201 ----
$r = call_handler('edu_q_handle_create', [
    'category_id'    => $catId,
    'title'          => 'EQ-3 問題',
    'question_type'  => 'true_false',
    'options'        => ['正しい', '誤り'],
    'correct_answer' => [0],
    'difficulty'     => 1,
], 'operator');
check($r['code'] === 201, 'EQ-3: true_false 2 options correct=[0] → 201');

// ---- EQ-5: multiple_choice, correct=[0,2] → 201 ----
$r = call_handler('edu_q_handle_create', [
    'category_id'    => $catId,
    'title'          => 'EQ-5 問題',
    'question_type'  => 'multiple_choice',
    'options'        => ['A', 'B', 'C'],
    'correct_answer' => [0, 2],
    'difficulty'     => 2,
], 'operator');
check($r['code'] === 201, 'EQ-5: multiple_choice correct=[0,2] → 201');

// ---- EQ-6: multiple_choice, correct=[] → 400 ----
$r = call_handler('edu_q_handle_create', [
    'category_id'    => $catId,
    'title'          => 'EQ-6 問題',
    'question_type'  => 'multiple_choice',
    'options'        => ['A', 'B', 'C'],
    'correct_answer' => [],
    'difficulty'     => 1,
], 'operator');
check($r['code'] === 400, 'EQ-6: multiple_choice correct=[] → 400');

// ---- EQ-7: 1 option only → 400 (options は2件以上必須) ----
$r = call_handler('edu_q_handle_create', [
    'category_id'    => $catId,
    'title'          => 'EQ-7 問題',
    'question_type'  => 'single_choice',
    'options'        => ['たった一択'],
    'correct_answer' => [0],
    'difficulty'     => 1,
], 'operator');
check($r['code'] === 400, 'EQ-7: 1 option only → 400');

// ---- EQ-8: correct=[5] out of range (options 3件) → 400 ----
$r = call_handler('edu_q_handle_create', [
    'category_id'    => $catId,
    'title'          => 'EQ-8 問題',
    'question_type'  => 'single_choice',
    'options'        => ['A', 'B', 'C'],
    'correct_answer' => [5],
    'difficulty'     => 1,
], 'operator');
check($r['code'] === 400, 'EQ-8: correct=[5] out of range → 400');

// ---- EQ-10: invalid question_type → 400 ----
$r = call_handler('edu_q_handle_create', [
    'category_id'    => $catId,
    'title'          => 'EQ-10 問題',
    'question_type'  => 'invalid_type',
    'options'        => ['A', 'B'],
    'correct_answer' => [0],
    'difficulty'     => 1,
], 'operator');
check($r['code'] === 400, 'EQ-10: invalid question_type → 400');

// ---- EQ-11: difficulty=1 → 201 ----
$r = call_handler('edu_q_handle_create', [
    'category_id'    => $catId,
    'title'          => 'EQ-11 問題',
    'question_type'  => 'single_choice',
    'options'        => ['A', 'B'],
    'correct_answer' => [0],
    'difficulty'     => 1,
], 'operator');
check($r['code'] === 201, 'EQ-11: difficulty=1 → 201');

// ---- EQ-12: difficulty=3 → 201 ----
$r = call_handler('edu_q_handle_create', [
    'category_id'    => $catId,
    'title'          => 'EQ-12 問題',
    'question_type'  => 'single_choice',
    'options'        => ['A', 'B'],
    'correct_answer' => [1],
    'difficulty'     => 3,
], 'operator');
check($r['code'] === 201, 'EQ-12: difficulty=3 → 201');

// ---- EQ-13: difficulty=0 → 400 ----
$r = call_handler('edu_q_handle_create', [
    'category_id'    => $catId,
    'title'          => 'EQ-13 問題',
    'question_type'  => 'single_choice',
    'options'        => ['A', 'B'],
    'correct_answer' => [0],
    'difficulty'     => 0,
], 'operator');
check($r['code'] === 400, 'EQ-13: difficulty=0 → 400');

// ---- EQ-14: difficulty=4 → 400 ----
$r = call_handler('edu_q_handle_create', [
    'category_id'    => $catId,
    'title'          => 'EQ-14 問題',
    'question_type'  => 'single_choice',
    'options'        => ['A', 'B'],
    'correct_answer' => [0],
    'difficulty'     => 4,
], 'operator');
check($r['code'] === 400, 'EQ-14: difficulty=4 → 400');

// ---- EQU-1: update title only → 200 ----
$r = call_handler('edu_q_handle_update', [
    'id'    => $eq1Id,
    'title' => '更新済みタイトル',
], 'operator');
check($r['code'] === 200, 'EQU-1: update title only → 200');
check(($r['payload']['question']['title'] ?? '') === '更新済みタイトル', 'EQU-1: title が更新される');

// ---- EQD-1: delete unused question → 200 ----
// 配信に組み込まれていない EQ-1 の設問を削除する
$r = call_handler('edu_q_handle_delete', ['id' => $eq1Id], 'operator');
check($r['code'] === 200, 'EQD-1: delete unused question → 200');
check(($r['payload']['success'] ?? false) === true, 'EQD-1: success=true');
$deleted = Db::one('SELECT id FROM edu_questions WHERE id = ?', [$eq1Id]);
check($deleted === null, 'EQD-1: 設問が実際に削除される');

echo "ALL TESTS PASSED\n";
