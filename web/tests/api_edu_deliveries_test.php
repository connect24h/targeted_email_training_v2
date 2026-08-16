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

// 個別配信にはテスト宛先も明示選択できる。全対象者には含めない。
Db::run(
    "INSERT INTO targets (tenant_id, tenant_no, email, name, status, is_test)
     VALUES (?, 9999, 'ed-test-recipient@example.test', 'ED テスト宛先', 'active', 1)",
    [$tenantId]
);
$testTargetId = (int) Db::one(
    "SELECT id FROM targets WHERE tenant_id = ? AND is_test = 1 ORDER BY id DESC LIMIT 1",
    [$tenantId]
)['id'];
$realTargetId = (int) Db::one(
    "SELECT id FROM targets WHERE tenant_id = ? AND status = 'active' AND is_test = 0 ORDER BY id LIMIT 1",
    [$tenantId]
)['id'];
Db::run(
    "INSERT INTO edu_materials (tenant_id, title, slides, is_active)
     VALUES (?, 'ED テスト教材', '[{\"title\":\"教材\",\"body\":\"本文\"}]', 1)",
    [$tenantId]
);
$materialId = (int) Db::one('SELECT id FROM edu_materials WHERE tenant_id = ? ORDER BY id DESC LIMIT 1', [$tenantId])['id'];

// ---- ED-1: elearning + all + pass_score=70 → 201 ----
$r = call_handler('edu_d_handle_create', [
    'title'         => 'ED-1 elearning テスト',
    'delivery_type' => 'elearning',
    'target_type'   => 'all',
    'pass_score'    => 70,
    'material_id'   => $materialId,
], 'operator');
check($r['code'] === 201, 'ED-1: elearning + all + pass_score=70 → 201');
check(($r['payload']['delivery']['delivery_type'] ?? '') === 'elearning', 'ED-1: delivery_type が保存される');
check((int) ($r['payload']['delivery']['pass_score'] ?? 0) === 70, 'ED-1: pass_score=70 が保存される');
check((int) ($r['payload']['delivery']['material_id'] ?? 0) === $materialId, 'ED-1: スライド教材を保存する');
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

// ---- ED-7: individual は通常対象者とテスト宛先を明示保存する ----
$r = call_handler('edu_d_handle_create', [
    'title'         => 'ED-7 個別配信テスト',
    'delivery_type' => 'elearning',
    'target_type'   => 'individual',
    'target_ids'    => [$realTargetId, $testTargetId],
    'pass_score'    => 80,
], 'operator');
check($r['code'] === 201, 'ED-7: individual + target_ids → 201');
$ed7Id = (int) $r['payload']['delivery']['id'];
$ed7 = edu_d_assert_owned($ed7Id, $tenantId);
check(edu_d_resolve_targets($ed7, $tenantId) === [$realTargetId, $testTargetId],
    'ED-7: 通常対象者とテスト宛先を個別対象として解決する');

$r = call_handler('edu_d_handle_create', [
    'title'         => 'ED-7b 個別対象なし',
    'delivery_type' => 'elearning',
    'target_type'   => 'individual',
    'target_ids'    => [],
    'pass_score'    => 80,
], 'operator');
check($r['code'] === 400, 'ED-7b: individual はtarget_ids必須');

$r = call_handler('edu_d_handle_create', [
    'title'         => 'ED-7c 他テナント対象',
    'delivery_type' => 'elearning',
    'target_type'   => 'individual',
    'target_ids'    => [3],
    'pass_score'    => 80,
], 'operator');
check($r['code'] === 404, 'ED-7c: 他テナント対象者を拒否する');

$allTargets = edu_d_resolve_targets(edu_d_assert_owned($ed1Id, $tenantId), $tenantId);
check(!in_array($testTargetId, $allTargets, true), 'ED-7d: 全対象者からテスト宛先を除外する');

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

// --- 受講トークンの有効期限は配信の deadline から決める ---
// 検証コードは edu_take.php にあったのに、セットする側が無く実測では全件 NULL
// (受講リンクが事実上無期限)だった。締切が機能していなかったのを閉じる。
check(edu_d_token_expiry(['deadline' => '2026-08-31']) === '2026-08-31 23:59:59',
    'ER-5: 日付のみの締切はその日いっぱいを有効期限にする');
check(edu_d_token_expiry(['deadline' => '2026-08-31 18:00:00']) === '2026-08-31 18:00:00',
    'ER-5: 時刻付きの締切はそのまま使う');
check(edu_d_token_expiry(['deadline' => null]) === null, 'ER-5: 締切がなければ無期限');
check(edu_d_token_expiry(['deadline' => '  ']) === null, 'ER-5: 空白だけの締切は無期限として扱う');
check(edu_d_token_expiry([]) === null, 'ER-5: deadline 列が無い配信でも落ちない');

// --- triggered_by と target_type の整合性 ---
// phishing_failure は EduAutoEnroll が対象を自動決定するので risk 限定。
// 他と組み合わせると手動確定した対象と自動投入が二重に走る。
$r = call_handler('edu_d_handle_create', [
    'title' => '自動連携と全員配信の併用', 'delivery_type' => 'awareness_quiz',
    'target_type' => 'all', 'triggered_by' => 'phishing_failure',
], 'operator');
check($r['code'] === 400, 'ER-5: phishing_failure と target_type=all の併用を拒否する');

$r = call_handler('edu_d_handle_create', [
    'title' => '自動連携', 'delivery_type' => 'awareness_quiz',
    'target_type' => 'risk', 'triggered_by' => 'phishing_failure',
], 'operator');
check($r['code'] === 201, 'ER-5: phishing_failure と target_type=risk は作成できる');
check(($r['payload']['delivery']['triggered_by'] ?? '') === 'phishing_failure',
    'ER-5: triggered_by が保存される(ハードコードされていない)');

$r = call_handler('edu_d_handle_create', [
    'title' => '不正なトリガー', 'delivery_type' => 'awareness_quiz',
    'target_type' => 'risk', 'triggered_by' => 'unknown_trigger',
], 'operator');
check($r['code'] === 400, 'ER-5: 未知の triggered_by を拒否する');

echo "ALL TESTS PASSED\n";
