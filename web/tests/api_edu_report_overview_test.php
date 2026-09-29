<?php
declare(strict_types=1);

/**
 * 教育レポートの概要(overview)と推移(trend)の集計の誤りの回帰テスト(段0 の G49、G50、G51)。
 * - 全社の数字(受講完了率、平均点、カテゴリ別)と推移に、テスト用の対象者(is_test=1)と
 *   削除済み(status が active でない)の対象者を入れない。
 * - 推移は受講の記録(edu_responses の完了日時)から月ごとに直接集計する(積んだ記録は読まない)。
 * - eラーニング(合格制)とアウェアネス(小問)を分けて出す(平均点を1つにまとめない)。
 */
require_once __DIR__ . '/helpers.php';
tet2_test_boot();
load_api('edu_report');

$tenantId = 1;
$target = static function (string $email, string $department, int $isTest = 0, string $status = 'active') use ($tenantId): int {
    return Db::insert('INSERT INTO targets (tenant_id, email, name, department, is_test, status) VALUES (?, ?, ?, ?, ?, ?)',
        [$tenantId, $email, '受講者', $department, $isTest, $status]);
};
$delivery = static function (string $title, string $type, ?int $pass) use ($tenantId): int {
    return Db::insert("INSERT INTO edu_deliveries (tenant_id, title, status, delivery_type, pass_score) VALUES (?, ?, 'running', ?, ?)",
        [$tenantId, $title, $type, $pass]);
};
$seq = 0;
$categoryId = Db::insert("INSERT INTO edu_categories (tenant_id, name, slug) VALUES (?, 'フィッシング', 'phishing-ov')", [$tenantId]);
$questionId = Db::insert(
    "INSERT INTO edu_questions (tenant_id, category_id, title, options, correct_answer) VALUES (?, ?, '設問', '[\"A\",\"B\"]', '[0]')",
    [$tenantId, $categoryId]
);
/** 割当と提出の結果を作る。$correct は設問1問の正誤。 */
$respond = static function (int $deliveryId, int $targetId, string $status, int $pct, string $completedAt, bool $correct)
    use ($tenantId, $questionId, &$seq): void {
    $seq++;
    $aid = Db::insert('INSERT INTO edu_assignments (tenant_id, delivery_id, target_id, access_token, status, completed_at) VALUES (?, ?, ?, ?, ?, ?)',
        [$tenantId, $deliveryId, $targetId, str_pad((string) $seq, 32, 'c', STR_PAD_LEFT), $status,
            $status === 'completed' ? $completedAt : null]);
    $rid = Db::insert('INSERT INTO edu_responses (tenant_id, assignment_id, percentage, completed_at) VALUES (?, ?, ?, ?)',
        [$tenantId, $aid, $pct, $completedAt]);
    Db::run('INSERT INTO edu_response_answers (response_id, question_id, answer, is_correct, score_earned) VALUES (?, ?, ?, ?, ?)',
        [$rid, $questionId, $correct ? '[0]' : '[1]', $correct ? 1 : 0, $correct ? 1 : 0]);
};

$real = $target('real-ov@example.test', '営業部');
$real2 = $target('real2-ov@example.test', '総務部');
$tester = $target('tester-ov@example.test', '営業部', 1);
$archived = $target('archived-ov@example.test', '営業部', 0, 'archived');

$el1 = $delivery('eラーニング1', 'elearning', 80);
$el2 = $delivery('eラーニング2', 'elearning', 80);
$aw = $delivery('小問', 'awareness_quiz', null);

// 実在の受講者: 7月にeラーニング80点で完了、8月にeラーニング60点(不合格で受講中)と小問40点
$respond($el1, $real, 'completed', 80, '2026-07-10 10:00:00', true);
$respond($el2, $real, 'started', 60, '2026-08-05 10:00:00', true);
$respond($aw, $real, 'completed', 40, '2026-08-20 10:00:00', true);
$respond($el1, $real2, 'completed', 100, '2026-07-31 23:00:00', true);
// テスト用と削除済みの受講者は全社の数字に入れない
$respond($el1, $tester, 'completed', 0, '2026-07-11 10:00:00', false);
$respond($aw, $tester, 'completed', 0, '2026-08-11 10:00:00', false);
$respond($el1, $archived, 'completed', 10, '2026-07-12 10:00:00', false);
// 積んだ記録(全期間の平均)は推移に使わない
Db::run("INSERT INTO edu_score_snapshots (tenant_id, snapshot_type, average_score, respondent_count, snapshot_date)
         VALUES (?, 'company', 99, 99, '2026-08-31')", [$tenantId]);

// ---- 概要 ----
$r = call_handler('edu_rep_handle_overview', [], 'viewer');
check($r['code'] === 200, '概要を取得できる');
$s = $r['payload']['summary'];
check($s['assigned'] === 4 && $s['completed'] === 3, '全社の割当と完了にテスト用と削除済みの対象者を入れない(G49)');
check($s['completion_rate'] === 75.0, '全社の受講完了率は実在の受講者だけで計算する');
$byType = $r['payload']['by_type'] ?? [];
check(isset($byType['elearning'], $byType['awareness_quiz']), '概要の KPI をeラーニングとアウェアネスに分けて出す(G51)');
check($byType['elearning']['assigned'] === 3 && $byType['elearning']['completed'] === 2
    && $byType['elearning']['average_score'] === 80.0,
    'eラーニングの割当、完了、平均点(80、60、100 の平均)');
check($byType['elearning']['pass_rate'] === 66.7, 'eラーニングの合格率(合格2人÷回答3件)');
check($byType['awareness_quiz']['assigned'] === 1 && $byType['awareness_quiz']['average_score'] === 40.0
    && $byType['awareness_quiz']['pass_rate'] === null, 'アウェアネスの平均点は別に出し、合格率は出さない');
check(!array_key_exists('literacy_score', $s), '種類をまとめた1つの平均点(literacy_score)は出さない');
$cat = array_values(array_filter($r['payload']['by_category'], static fn(array $c): bool => $c['slug'] === 'phishing-ov'))[0];
check($cat['answered'] === 4 && $cat['correct_rate'] === 100.0, 'カテゴリ別の正答率にテスト用と削除済みの対象者の解答を入れない');
$depts = array_column($r['payload']['by_department'], null, 'department');
check(($depts['営業部']['respondent_count'] ?? 0) === 3, '部署別にも削除済みの対象者を入れない');

// ---- 推移 ----
$t = call_handler('edu_rep_handle_trend', [], 'viewer');
check($t['code'] === 200, '推移を取得できる');
check(($t['payload']['months'] ?? null) === ['2026-07', '2026-08'], '推移は受講の完了日時の月ごと(G50)');
$el = $t['payload']['series']['elearning'] ?? [];
$awS = $t['payload']['series']['awareness_quiz'] ?? [];
check(count($el) === 2 && $el[0]['month'] === '2026-07' && $el[0]['average_score'] === 90.0
    && $el[0]['completions'] === 2 && $el[0]['respondents'] === 2,
    '7月のeラーニングは7月の受講だけで平均する(80 と 100。テスト用と削除済みを除く)');
check($el[1]['average_score'] === 60.0 && $el[1]['completions'] === 0 && $el[1]['respondents'] === 1,
    '8月のeラーニングは不合格の60点だけ(完了の数は0)');
check($awS[0]['average_score'] === null && $awS[0]['respondents'] === 0 && $awS[1]['average_score'] === 40.0,
    'アウェアネスは別の系列(受講のない月は平均なし)');
foreach ([$el, $awS] as $series) {
    foreach ($series as $p) {
        check($p['average_score'] !== 99.0, '積んだ記録(edu_score_snapshots)の値を使わない: ' . $p['month']);
    }
}

// 他テナントを指定したら拒否
$_GET['tenant_id'] = '2';
check(call_handler('edu_rep_handle_trend', [], 'viewer')['code'] === 403, '他テナントの推移を拒否');
unset($_GET['tenant_id']);

echo "ALL TESTS PASSED\n";
