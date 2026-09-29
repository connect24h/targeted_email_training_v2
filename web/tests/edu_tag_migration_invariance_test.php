<?php
declare(strict_types=1);

/**
 * 分野のタグの migration(20261101-edu-tags)の前後で、既存の集計(概要のカテゴリ別の正答率ほか)が1バイトも変わらないこと。
 * タグの表のない DB(migration の前)で概要と配信ごとの数字を取り、migration を当ててから同じ数字を取り直して比べる。
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../db/MigrationRunner.php';

$dbPath = tet2_test_boot();
load_api('edu_report');

$run = static fn(string $sql, array $p = []): int => Db::insert($sql, $p);
$run("INSERT INTO edu_categories (id, tenant_id, name, slug, sort_order, is_shared) VALUES
    (701, NULL, '共有の分野', 'shared-field', 2, 1), (702, 1, 'フィッシング', 'phish', 1, 0), (703, 1, 'パスワード', 'pw', 3, 0),
    (704, 1, 'フィッシング', 'phish-2', 4, 0), (705, 2, 'フィッシング', 'phish', 1, 0)");
$questions = [];
foreach ([701, 701, 702, 703, 703, 704, 705] as $i => $category) {
    $tenant = $category === 701 ? null : ($category === 705 ? 2 : 1);
    $questions[] = $run("INSERT INTO edu_questions (tenant_id, category_id, title, options, correct_answer, is_shared) VALUES (?, ?, ?, '[\"a\",\"b\"]', '[0]', ?)",
        [$tenant, $category, "設問{$i}", $tenant === null ? 1 : 0]);
}
$delivery = $run("INSERT INTO edu_deliveries (tenant_id, title, status, pass_score) VALUES (1, '配信', 'running', 60)");
foreach ($questions as $i => $qid) {
    if ($i < 6) {
        $run('INSERT INTO edu_delivery_questions (delivery_id, question_id, sort_order) VALUES (?, ?, ?)', [$delivery, $qid, $i]);
    }
}
foreach ([['営業部', 0, [1, 1, 0, 1, 0, 1]], ['総務部', 0, [0, 1, 1, 1, 1, 0]], ['営業部', 1, [0, 0, 0, 0, 0, 0]], ['', 0, [1, 0, 1, 0, 1, 1]]] as $n => [$dept, $isTest, $marks]) {
    $target = $run('INSERT INTO targets (tenant_id, tenant_no, email, department, is_test) VALUES (1, ?, ?, ?, ?)', [200 + $n, "m{$n}@example.test", $dept, $isTest]);
    $aid = $run("INSERT INTO edu_assignments (tenant_id, delivery_id, target_id, access_token, status, completed_at) VALUES (1, ?, ?, ?, 'completed', '2026-09-0{$n} 10:00:00')",
        [$delivery, $target, bin2hex(random_bytes(16))]);
    $rid = $run("INSERT INTO edu_responses (tenant_id, assignment_id, percentage, completed_at) VALUES (1, ?, ?, '2026-09-0{$n} 10:00:00')",
        [$aid, (int) round(array_sum($marks) / 6 * 100)]);
    foreach ($marks as $i => $mark) {
        $run('INSERT INTO edu_response_answers (response_id, question_id, is_correct) VALUES (?, ?, ?)', [$rid, $questions[$i], $mark]);
    }
}

$snapshot = static function () use ($delivery): string {
    $_GET = [];
    $overview = call_handler('edu_rep_handle_overview', [], 'viewer');
    $_GET = ['id' => (string) $delivery];
    $detail = call_handler('edu_rep_handle_delivery', [], 'viewer');
    $_GET = [];
    return json_encode([$overview, $detail], JSON_UNESCAPED_UNICODE);
};

// migration の前の状態にする(タグの表なし、版の記録は 20261101 の手前まですべて済み)
Db::run('DROP TABLE edu_question_tags');
Db::run('DROP TABLE edu_tags');
$versions = (new ReflectionClassConstant(MigrationRunner::class, 'VERSIONS'))->getValue();
foreach ($versions as $version) {
    if ($version !== '20261101-edu-tags') {
        Db::run('INSERT INTO schema_migrations (version) VALUES (?)', [$version]);
    }
}
$before = $snapshot();
$byCategory = json_decode($before, true)[0]['payload']['by_category'];
check(count($byCategory) === 4 && array_sum(array_column($byCategory, 'answered')) === 18, '前提: カテゴリ別の正答率に4カテゴリ、18件の解答がある');

$runner = new MigrationRunner($dbPath);
check($runner->pending() === ['20261101-edu-tags'], '分野のタグの migration だけが未適用');
check($runner->migrate() === 1, 'migration を当てる');
check((int) Db::one('SELECT COUNT(*) AS c FROM edu_question_tags')['c'] === count($questions), 'migration で全部の設問にタグが付いた');

$after = $snapshot();
check($after === $before, '概要(カテゴリ別の正答率、部署別、種類別)と配信の設問ごとの数字が migration の前後で1バイトも変わらない');
check(hash('sha256', $after) === hash('sha256', $before), '同じく sha256 も一致: ' . substr(hash('sha256', $after), 0, 16));

// タグの数字は、カテゴリ別の数字と同じになる(カテゴリをそのまま写したので)。同じ名前の2つのカテゴリは1つのタグにまとまる
$tagStats = array_column(EduTagReport::byTag(1, EDU_REP_REAL_TARGET_SQL), null, 'name');
$catStats = [];
foreach ($byCategory as $c) {
    $catStats[$c['name']] = [($catStats[$c['name']][0] ?? 0) + $c['answered'], ($catStats[$c['name']][1] ?? 0) + $c['correct']];
}
foreach ($catStats as $name => [$answered, $correct]) {
    check($tagStats[$name]['answered'] === $answered && $tagStats[$name]['correct'] === $correct,
        "タグ「{$name}」の回答数と正解の数は、同じ名前のカテゴリの合計と同じ({$correct}/{$answered})");
}

echo "ALL TESTS PASSED\n";
