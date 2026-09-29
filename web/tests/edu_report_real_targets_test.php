<?php
declare(strict_types=1);

/**
 * 教育レポートの配信ごとの表も、概要と同じく、テスト用の対象者(is_test=1)と削除済みの対象者(status が active でない)を
 * 数えないことのテスト。受講者を1人指定して開く表示(person)だけは、指定された人をそのまま見せる。
 */
require_once __DIR__ . '/helpers.php';
tet2_test_boot();
load_api('edu_report');

$target = static function (string $name, string $email, int $isTest, string $status): int {
    Db::run('INSERT INTO targets (tenant_id, email, name, department, is_test, status) VALUES (1, ?, ?, ?, ?, ?)',
        [$email, $name, '営業部', $isTest, $status]);
    return (int) Db::one('SELECT MAX(id) AS id FROM targets')['id'];
};
$real = $target('本物 一郎', 'real@example.test', 0, 'active');
$test = $target('試し 二郎', 'test@example.test', 1, 'active');
$gone = $target('退職 三郎', 'gone@example.test', 0, 'archived');

Db::run("INSERT INTO edu_deliveries (tenant_id, title, status, delivery_type, pass_score) VALUES (1, '実対象の確認', 'running', 'elearning', 80)");
$deliveryId = (int) Db::one('SELECT MAX(id) AS id FROM edu_deliveries')['id'];
Db::run("INSERT INTO edu_categories (tenant_id, name, slug) VALUES (1, '実対象', 'real-targets')");
$categoryId = (int) Db::one('SELECT MAX(id) AS id FROM edu_categories')['id'];
Db::run("INSERT INTO edu_questions (tenant_id, category_id, title, question_type, options, correct_answer, difficulty) VALUES (1, ?, '設問', 'single_choice', '[\"a\",\"b\"]', '[0]', 1)", [$categoryId]);
$questionId = (int) Db::one('SELECT MAX(id) AS id FROM edu_questions')['id'];
Db::run('INSERT INTO edu_delivery_questions (delivery_id, question_id, sort_order) VALUES (?, ?, 0)', [$deliveryId, $questionId]);

$n = 0;
foreach ([[$real, 100, 1], [$test, 0, 0], [$gone, 0, 0]] as [$targetId, $pct, $correct]) {
    $n++;
    Db::run("INSERT INTO edu_assignments (tenant_id, delivery_id, target_id, access_token, status, completed_at) VALUES (1, ?, ?, ?, 'completed', '2026-09-20 10:00:00')",
        [$deliveryId, $targetId, str_repeat((string) $n, 32)]);
    $aid = (int) Db::one('SELECT MAX(id) AS id FROM edu_assignments')['id'];
    Db::run("INSERT INTO edu_responses (tenant_id, assignment_id, percentage, completed_at) VALUES (1, ?, ?, '2026-09-20 10:00:00')", [$aid, $pct]);
    $rid = (int) Db::one('SELECT MAX(id) AS id FROM edu_responses')['id'];
    Db::run('INSERT INTO edu_response_answers (response_id, question_id, answer, is_correct, score_earned) VALUES (?, ?, ?, ?, ?)',
        [$rid, $questionId, '[0]', $correct, $correct]);
}

// 配信1件の集計(教育配信の一覧の「レポート」)
$_GET = ['id' => (string) $deliveryId];
$r = call_handler('edu_rep_handle_delivery', [], 'viewer');
check($r['code'] === 200, 'RT-1: 配信1件の集計を読める');
$s = $r['payload']['summary'];
check((int) $s['assigned'] === 1 && (int) $s['completed'] === 1, 'RT-1: 配信の対象と完了は本物の対象者だけを数える');
check((float) $s['average_score'] === 100.0 && (float) $s['pass_rate'] === 100.0, 'RT-1: 平均点と合格率も本物の対象者だけ');
$q = $r['payload']['by_question'][0];
check((int) $q['answered'] === 1 && (int) $q['correct'] === 1, 'RT-1: 設問ごとの正答も本物の対象者だけ');

// 配信ごとの受講者と部署
$_GET = ['id' => (string) $deliveryId];
$r = call_handler('edu_rep_handle_delivery_people', [], 'viewer');
check(array_column($r['payload']['people'], 'name') === ['本物 一郎'], 'RT-2: 受講者ごとの表に、テスト用と削除済みの対象者を出さない');
$_GET = ['id' => (string) $deliveryId];
$r = call_handler('edu_rep_handle_delivery_depts', [], 'viewer');
check(count($r['payload']['departments']) === 1 && (int) $r['payload']['departments'][0]['assigned'] === 1, 'RT-2: 部署ごとの表も本物の対象者だけ');

// 配信の一覧
$_GET = [];
$r = call_handler('edu_rep_handle_deliveries', [], 'viewer');
$row = array_values(array_filter($r['payload']['deliveries'], static fn(array $d): bool => (int) $d['id'] === $deliveryId))[0];
check((int) $row['assigned'] === 1, 'RT-3: 配信の一覧の対象の数も本物の対象者だけ');

// 受講者の検索と、1人を指定した表示
$_GET = [];
$r = call_handler('edu_rep_handle_learners', [], 'viewer');
check(array_column($r['payload']['learners'], 'name') === ['本物 一郎'], 'RT-4: 受講者ごとのタブの一覧に、テスト用と削除済みの対象者を出さない');
$_GET = ['target_id' => (string) $test];
$r = call_handler('edu_rep_handle_person', [], 'viewer');
check($r['code'] === 200 && count($r['payload']['deliveries'] ?? $r['payload']['rows'] ?? []) === 1, 'RT-5: 1人を指定して開けば、テスト用の対象者の記録も見られる');

echo "edu_report_real_targets_test: OK\n";
