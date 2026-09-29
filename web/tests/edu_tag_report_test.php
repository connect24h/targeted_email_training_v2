<?php
declare(strict_types=1);

/**
 * 分野(タグ)の教育レポートとマイページの数字(C1、G09・G59・G60)を、手で数えられる合成データで確かめる。
 *
 * タグ: 組織1の親「フィッシング」(P1)とその子「添付」(C1)、共有の親「パスワード」(P2)と、その下の組織1の子「多要素」(C2)。
 * 設問: Q1=P1、Q2=C1、Q3=P1+C1(親では1回だけ数える)、Q4=P2+P1(両方の親で数える)、Q5=C2、Q6=タグなし。
 * 解答(○正解 ×不正解):
 *   営業A(営業部、2026-09-10) Q1○ Q2× Q3○ Q4○ Q5○ Q6○
 *   営業B(営業部、2026-08-05) Q1× Q3× Q4×
 *   総務C(総務部、2026-09-20) Q2○ Q5×
 *   部署なしD(部署なし、2025-01-15) Q1○            ← 12か月の推移の外
 *   テスト用E(is_test=1)、削除済みF(archived) Q1× Q4× ← 数えない
 *   組織2の G(Q7=P2) ×                                  ← 組織1には入らない
 */

require_once __DIR__ . '/helpers.php';

tet2_test_boot();
load_api('edu_report');

$run = static fn(string $sql, array $p = []): int => Db::insert($sql, $p);
$tag = static fn(?int $tenant, ?int $parent, string $name, int $sort): int
    => $run('INSERT INTO edu_tags (tenant_id, parent_id, name, sort_order) VALUES (?, ?, ?, ?)', [$tenant, $parent, $name, $sort]);
$p1 = $tag(1, null, 'フィッシング', 0);
$c1 = $tag(1, $p1, '添付', 0);
$p2 = $tag(null, null, 'パスワード', 1);
$c2 = $tag(1, $p2, '多要素', 0);
$tag(2, null, '組織2だけの分野', 0);

$run("INSERT INTO edu_categories (id, tenant_id, name, slug) VALUES (601, 1, '総合', 'general'), (602, 2, '組織2', 'other')");
$question = static function (int $tenant, int $category, array $tags) use ($run): int {
    $id = $run("INSERT INTO edu_questions (tenant_id, category_id, title, options, correct_answer) VALUES (?, ?, '設問', '[\"a\",\"b\"]', '[0]')",
        [$tenant, $category]);
    foreach ($tags as $t) {
        $run('INSERT INTO edu_question_tags (question_id, tag_id) VALUES (?, ?)', [$id, $t]);
    }
    return $id;
};
[$q1, $q2, $q3, $q4, $q5, $q6] = [$question(1, 601, [$p1]), $question(1, 601, [$c1]), $question(1, 601, [$p1, $c1]),
    $question(1, 601, [$p2, $p1]), $question(1, 601, [$c2]), $question(1, 601, [])];
$q7 = $question(2, 602, [$p2]);

$d1 = $run("INSERT INTO edu_deliveries (tenant_id, title, status) VALUES (1, '配信', 'running')");
$d2 = $run("INSERT INTO edu_deliveries (tenant_id, title, status) VALUES (2, '組織2の配信', 'running')");
$person = static function (int $tenant, string $name, ?string $dept, int $isTest = 0, string $status = 'active') use ($run): int {
    static $no = 100;
    $no++;
    return $run('INSERT INTO targets (tenant_id, tenant_no, email, name, department, is_test, status) VALUES (?, ?, ?, ?, ?, ?, ?)',
        [$tenant, $no, "p{$no}@example.test", $name, $dept, $isTest, $status]);
};
$answer = static function (int $tenant, int $delivery, int $target, string $completedAt, array $answers) use ($run): void {
    $aid = $run("INSERT INTO edu_assignments (tenant_id, delivery_id, target_id, access_token, status, completed_at) VALUES (?, ?, ?, ?, 'completed', ?)",
        [$tenant, $delivery, $target, bin2hex(random_bytes(16)), $completedAt]);
    $rid = $run('INSERT INTO edu_responses (tenant_id, assignment_id, percentage, completed_at) VALUES (?, ?, 50, ?)', [$tenant, $aid, $completedAt]);
    foreach ($answers as $qid => $correct) {
        $run('INSERT INTO edu_response_answers (response_id, question_id, is_correct) VALUES (?, ?, ?)', [$rid, $qid, $correct]);
    }
};
$a = $person(1, '営業A', '営業部');
$b = $person(1, '営業B', ' 営業部 ');
$c = $person(1, '総務C', '総務部');
$d = $person(1, '部署なしD', '');
$answer(1, $d1, $a, '2026-09-10 10:00:00', [$q1 => 1, $q2 => 0, $q3 => 1, $q4 => 1, $q5 => 1, $q6 => 1]);
$answer(1, $d1, $b, '2026-08-05 10:00:00', [$q1 => 0, $q3 => 0, $q4 => 0]);
$answer(1, $d1, $c, '2026-09-20 10:00:00', [$q2 => 1, $q5 => 0]);
$answer(1, $d1, $d, '2025-01-15 10:00:00', [$q1 => 1]);
$answer(1, $d1, $person(1, 'テスト用E', '営業部', 1), '2026-09-11 10:00:00', [$q1 => 0, $q4 => 0]);
$answer(1, $d1, $person(1, '削除済みF', '営業部', 0, 'archived'), '2026-09-12 10:00:00', [$q1 => 0, $q4 => 0]);
$answer(2, $d2, $person(2, '組織2G', '営業部'), '2026-09-13 10:00:00', [$q7 => 0]);

// ---- 分野ごと(親と子) ----
$byTag = array_column(EduTagReport::byTag(1, EDU_REP_REAL_TARGET_SQL), null, 'id');
$stat = static fn(int $id): array => [$byTag[$id]['answered'], $byTag[$id]['correct'], $byTag[$id]['correct_rate']];
check($stat($p1) === [9, 5, 55.6], '親 P1: 親か子のタグの設問の解答 9 件、正解 5(Q3 は1回だけ数える)');
check($stat($c1) === [4, 2, 50.0], '子 C1: Q2 と Q3 の解答 4 件、正解 2');
check($stat($p2) === [4, 2, 50.0], '共有の親 P2: Q4 と、自組織の子 C2 の Q5 の解答 4 件(組織2の解答は入らない)');
check($stat($c2) === [2, 1, 50.0], '子 C2: Q5 の解答 2 件');
check(array_column(EduTagReport::byTag(1, EDU_REP_REAL_TARGET_SQL), 'path') === ['フィッシング', 'フィッシング > 添付', 'パスワード', 'パスワード > 多要素'],
    '親の直後に子を、タグの並び順で出す(ほかの組織のタグは出ない)');
$other = array_column(EduTagReport::byTag(2, EDU_REP_REAL_TARGET_SQL), null, 'name');
check(!isset($other['フィッシング'], $other['多要素']) && $other['パスワード']['answered'] === 1 && $other['パスワード']['correct_rate'] === 0.0,
    '組織2では、組織1のタグは出ず、共有のタグは組織2の解答だけで数える');
check($other['組織2だけの分野']['answered'] === 0 && $other['組織2だけの分野']['correct_rate'] === null, '解答のないタグは正答率 null');

// ---- 部署 × 親のタグ ----
$matrix = EduTagReport::departmentMatrix(1, EDU_REP_REAL_TARGET_SQL);
check(array_column($matrix['tags'], 'name') === ['フィッシング', 'パスワード'], '列は解答のある親のタグ(並び順)');
$grid = [];
foreach ($matrix['departments'] as $row) {
    $grid[$row['department']] = array_map(static fn(array $c): array => [$c['answered'], $c['correct'], $c['correct_rate']], $row['cells']);
}
check(array_keys($grid) === ['(未設定)', '営業部', '総務部'], '行は部署(前後の空白は落とし、空は「(未設定)」)。テスト用と削除済みは入らない');
check($grid['営業部'] === [[7, 3, 42.9], [3, 2, 66.7]], '営業部: P1 は 7 件中 3、P2 は 3 件中 2');
check($grid['総務部'] === [[1, 1, 100.0], [1, 0, 0.0]], '総務部: P1 は 1 件中 1、P2 は 1 件中 0(0% と空欄を区別)');
check($grid['(未設定)'] === [[1, 1, 100.0], [0, 0, null]], '解答のないマスは正答率 null(空欄)');
// CSV の無害化は bootstrap.php の本物を使う(load_api は bootstrap を読まない)
preg_match('/function tet2_csv_sanitize\(.*?\n\}/s', (string) file_get_contents(__DIR__ . '/../lib/bootstrap.php'), $match);
eval($match[0]);
$csv = edu_rep_tag_matrix_csv($matrix);
check(str_starts_with($csv, "\xEF\xBB\xBF部署,フィッシングの正答率(%),フィッシングの回答数,パスワードの正答率(%),パスワードの回答数\r\n"),
    'CSV は BOM つきで、タグごとに正答率と回答数の列');
check(str_contains($csv, "(未設定),100,1,,\r\n") && str_contains($csv, "営業部,42.9,7,66.7,3\r\n"), 'CSV の空のマスは空欄');
$empty = EduTagReport::departmentMatrix(99, EDU_REP_REAL_TARGET_SQL);
check($empty === ['tags' => [], 'departments' => []], '解答のない組織は空の表');

// ---- 親のタグごとの月の推移(直近12か月) ----
$trend = EduTagReport::monthlyTrend(1, EDU_REP_REAL_TARGET_SQL, '2026-09');
check(count($trend['months']) === 12 && $trend['months'][0] === '2025-10' && $trend['months'][11] === '2026-09', '月は直近12か月');
$series = array_column($trend['series'], 'points', 'name');
$point = static fn(string $name, string $month): array => (static function (array $p): array {
    return [$p['answered'], $p['correct'], $p['correct_rate']];
})(array_column($series[$name], null, 'month')[$month]);
check($point('フィッシング', '2026-08') === [3, 0, 0.0] && $point('フィッシング', '2026-09') === [5, 4, 80.0],
    'P1: 8月は 3 件中 0、9月は 5 件中 4(期間の外の 2025-01 は入らない)');
check($point('パスワード', '2026-08') === [1, 0, 0.0] && $point('パスワード', '2026-09') === [3, 2, 66.7], 'P2 の月ごと');
check($point('フィッシング', '2026-01') === [0, 0, null], '解答のない月は null(折れ線のすき間)');
check(array_keys($series) === ['フィッシング', 'パスワード'], '系列は期間に解答のある親のタグだけ');
check(EduTagReport::monthlyTrend(1, EDU_REP_REAL_TARGET_SQL, '2025-01')['series'][0]['points'][11]['answered'] === 1,
    '期間を変えれば、その期間の解答を数える');

// ---- API(viewer、テナントで絞る) ----
$r = call_handler('edu_rep_handle_tags', [], 'viewer');
check($r['code'] === 200 && isset($r['payload']['by_tag'], $r['payload']['matrix'], $r['payload']['trend'])
    && count($r['payload']['trend']['months']) === 12, 'viewer は分野の数字を読める');
$_GET = ['tenant_id' => '2'];
check(call_handler('edu_rep_handle_tags', [], 'operator')['code'] === 403, 'ほかの組織の分野の数字は読めない');
$r = call_handler('edu_rep_handle_tags', [], 'superadmin');
check(!in_array('フィッシング', array_column($r['payload']['by_tag'], 'name'), true), 'superadmin が組織2を選ぶと組織2の数字');
$_GET = [];
$r = call_handler('edu_rep_handle_tag_matrix', [], 'viewer');
check($r['code'] === 200 && count($r['payload']['matrix']['departments']) === 3, '部署×分野の表を JSON でも返す');

// ---- マイページ: 本人の親のタグごとの正答率 ----
$mine = static fn(int $target): array => array_map(static fn(array $t): array => [$t['name'], $t['answered'], $t['correct'], $t['correct_rate']],
    LearnerPortal::grades(1, $target)['by_tag']);
check($mine($a) === [['フィッシング', 4, 3, 75.0], ['パスワード', 2, 2, 100.0]], '営業A: 自分の解答だけで親のタグごとの正答率');
check($mine($c) === [['フィッシング', 1, 1, 100.0], ['パスワード', 1, 0, 0.0]], '総務C: ほかの人の解答は入らない');
$nobody = $person(1, '未受講H', '営業部');
check($mine($nobody) === [], '解答のない人は空');
check(LearnerPortal::grades(2, $a)['by_tag'] === [], 'ほかの組織として引いても、その人の解答は出ない');
// 下書きに戻した配信(マイページに出さない配信)の解答は数えない
Db::run("UPDATE edu_deliveries SET status = 'draft' WHERE id = ?", [$d1]);
check($mine($a) === [], 'マイページに出さない配信(下書き)の解答は数えない');

echo "ALL TESTS PASSED\n";
