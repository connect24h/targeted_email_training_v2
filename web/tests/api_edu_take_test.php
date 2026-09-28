<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

tet2_test_boot();
load_api('edu_take');

check(function_exists('take_delivery_material'), 'ET-1: 配信教材を取得する処理がある');
check(function_exists('take_save_attempt'), 'ET-2: 合否に応じて受講状態を保存する処理がある');

$tenantId = (int) current_user()['tenant_id'];
$targetId = (int) Db::one('SELECT id FROM targets WHERE tenant_id = ? ORDER BY id LIMIT 1', [$tenantId])['id'];
Db::run(
    "INSERT INTO edu_categories (tenant_id, name, slug) VALUES (?, '受講テスト', 'take-test')",
    [$tenantId]
);
$categoryId = (int) Db::one('SELECT id FROM edu_categories WHERE tenant_id = ? ORDER BY id LIMIT 1', [$tenantId])['id'];
Db::run(
    "INSERT INTO edu_questions
     (tenant_id, category_id, title, question_type, options, correct_answer, difficulty)
     VALUES (?, ?, '再受講テスト', 'single_choice', '[\"正解\",\"不正解\"]', '[0]', 1)",
    [$tenantId, $categoryId]
);
$questionId = (int) Db::one('SELECT id FROM edu_questions ORDER BY id DESC LIMIT 1')['id'];
Db::run(
    "INSERT INTO edu_materials (tenant_id, title, description, slides, is_active)
     VALUES (?, '受講教材', '説明', '[{\"title\":\"教材\",\"body\":\"本文\"}]', 1)",
    [$tenantId]
);
$materialId = (int) Db::one('SELECT id FROM edu_materials ORDER BY id DESC LIMIT 1')['id'];
Db::run(
    "INSERT INTO edu_deliveries
     (tenant_id, title, status, delivery_type, pass_score, target_type, material_id)
     VALUES (?, '再受講配信', 'running', 'elearning', 80, 'individual', ?)",
    [$tenantId, $materialId]
);
$deliveryId = (int) Db::one('SELECT id FROM edu_deliveries ORDER BY id DESC LIMIT 1')['id'];
Db::run('INSERT INTO edu_delivery_questions (delivery_id, question_id, sort_order) VALUES (?, ?, 0)', [$deliveryId, $questionId]);
Db::run(
    "INSERT INTO edu_assignments (tenant_id, delivery_id, target_id, access_token, status)
     VALUES (?, ?, ?, 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'started')",
    [$tenantId, $deliveryId, $targetId]
);
$assignment = Db::one('SELECT * FROM edu_assignments WHERE delivery_id = ?', [$deliveryId]);

$material = take_delivery_material($deliveryId);
check(($material['title'] ?? '') === '受講教材', 'ET-1: 配信に紐づく教材を返す');
check(count($material['slides'] ?? []) === 1, 'ET-1: 教材スライドを返す');

take_save_attempt($assignment, [
    'total_score' => 0,
    'max_score' => 1,
    'percentage' => 0,
    'answers' => [['question_id' => $questionId, 'answer' => [1], 'is_correct' => false, 'score_earned' => 0]],
], [$questionId => ['id' => $questionId]], false);
$afterFail = Db::one('SELECT status, completed_at FROM edu_assignments WHERE id = ?', [(int) $assignment['id']]);
check($afterFail['status'] === 'started' && $afterFail['completed_at'] === null,
    'ET-2: eラーニング不合格時は未完了のまま再受講できる');

take_save_attempt($assignment, [
    'total_score' => 1,
    'max_score' => 1,
    'percentage' => 100,
    'answers' => [['question_id' => $questionId, 'answer' => [0], 'is_correct' => true, 'score_earned' => 1]],
], [$questionId => ['id' => $questionId]], true);
$afterPass = Db::one('SELECT status, completed_at, score FROM edu_assignments WHERE id = ?', [(int) $assignment['id']]);
check($afterPass['status'] === 'completed' && (int) $afterPass['score'] === 100,
    'ET-3: eラーニング合格時に完了する');
check((int) Db::one('SELECT COUNT(*) AS n FROM edu_responses WHERE assignment_id = ?', [(int) $assignment['id']])['n'] === 1,
    'ET-3: 再受講結果は1件に更新する');

// 二度押し: 最初の提出で完了した後、古い割当(まだ受講中)のままもう一度保存しようとしても、回は増えない
$attemptsBefore = (int) Db::one('SELECT COUNT(*) AS n FROM edu_attempts WHERE assignment_id = ?', [(int) $assignment['id']])['n'];
$rejected = false;
try {
    take_save_attempt($assignment, [
        'total_score' => 1,
        'max_score' => 1,
        'percentage' => 100,
        'answers' => [['question_id' => $questionId, 'answer' => [0], 'is_correct' => true, 'score_earned' => 1]],
    ], [$questionId => ['id' => $questionId]], true);
} catch (TakeAlreadyCompleted $e) {
    $rejected = true;
}
check($rejected && (int) Db::one('SELECT COUNT(*) AS n FROM edu_attempts WHERE assignment_id = ?', [(int) $assignment['id']])['n'] === $attemptsBefore,
    'ET-3b: 完了した後の二重の提出は書き込みの前に止め、受講の回を増やさない');

// --- 期限切れの受講リンクを弾く ---
// token_expiry の検証コードは元からあったが、セットする側が無く実測では全件 NULL
// だった(受講リンクが事実上無期限)。launch/自動投入の両方で deadline を入れるようにした。
$now = strtotime('2026-08-16 12:00:00');
check(take_is_expired('2026-08-15 23:59:59', $now) === true, 'ET-4: 期限を過ぎたリンクは無効');
check(take_is_expired('2026-08-16 23:59:59', $now) === false, 'ET-4: 期限内のリンクは有効');
check(take_is_expired(null, $now) === false, 'ET-4: 期限なし(NULL)は従来どおり受講できる');
check(take_is_expired('', $now) === false, 'ET-4: 空文字も期限なしとして扱う');
check(take_is_expired('不正な日付', $now) === false, 'ET-4: 解釈できない値で受講者を締め出さない');

echo "ALL TESTS PASSED\n";
