<?php
declare(strict_types=1);

/**
 * 教育レポートの部署の階層(C3、G14)。dept_level で部署を1段目、2段目、全部にまとめる。
 *
 * - dept_level を付けない(または 0、all)時の出力は、C3 の前の出力と1バイトも変わらない。
 *   比べる相手の fixtures/dept_levels_edu_golden.json は、C3 の前のコード(02cbef3)で
 *   `php dept_levels_edu_report_test.php --emit-defaults` を実行して作った。
 * - 1段目と2段目では、件数を足してから率を計算し直す(平均の平均にしない)。
 * 合成データは fixtures/dept_levels_seed.php(手で数えられる数字)。
 */
require_once __DIR__ . '/helpers.php';
tet2_test_boot();
load_api('edu_report');
require_once __DIR__ . '/fixtures/dept_levels_seed.php';
$seed = dept_levels_seed();
$el = $seed['elearning'];
$aw = $seed['awareness'];

// exit する CSV の経路は別のプロセスで、本物のサニタイザと一緒に実行する(引数2はクエリ文字列)
if (($argv[1] ?? '') === '--csv') {
    $bootstrap = (string) file_get_contents(__DIR__ . '/../lib/bootstrap.php');
    preg_match('/function tet2_csv_sanitize\(.*?\n\}/s', $bootstrap, $match);
    eval($match[0]);
    parse_str((string) ($argv[2] ?? ''), $query);
    $_GET = $query;
    $GLOBALS['__TET2_TEST_ROLE'] = 'viewer';
    $query['action'] === 'tag_matrix' ? edu_rep_handle_tag_matrix(current_user()) : edu_rep_handle_delivery_depts(current_user());
}
$runCsv = static function (array $query): string {
    $proc = proc_open([PHP_BINARY, __FILE__, '--csv', http_build_query($query)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($proc) !== 0) {
        throw new RuntimeException("CSV の実行に失敗: $err");
    }
    return $out;
};
$json = static function (string $handler, array $query): array {
    $_GET = $query;
    $r = call_handler($handler, [], 'viewer');
    if ($r['code'] !== 200) {
        throw new RuntimeException("$handler が {$r['code']} を返した: " . json_encode($r['payload'], JSON_UNESCAPED_UNICODE));
    }
    return $r['payload'];
};
$enc = static fn(array $payload): string => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);

/** 既定の出力(比べる対象)。$extra を全部のクエリに足す。推移は今月で窓が動くので比べない。 */
$defaults = static function (array $extra) use ($json, $runCsv, $enc, $el, $aw): array {
    $tags = $json('edu_rep_handle_tags', $extra);
    unset($tags['trend']);
    return [
        'overview' => $enc($json('edu_rep_handle_overview', $extra)),
        'delivery_depts_el' => $enc($json('edu_rep_handle_delivery_depts', ['id' => (string) $el] + $extra)),
        'delivery_depts_aw' => $enc($json('edu_rep_handle_delivery_depts', ['id' => (string) $aw] + $extra)),
        'delivery_depts_csv' => $runCsv(['action' => 'delivery_depts', 'id' => (string) $el, 'format' => 'csv'] + $extra),
        'tags' => $enc($tags),
        'tag_matrix' => $enc($json('edu_rep_handle_tag_matrix', $extra)),
        'tag_matrix_csv' => $runCsv(['action' => 'tag_matrix', 'format' => 'csv'] + $extra),
    ];
};
if (($argv[1] ?? '') === '--emit-defaults') {
    echo json_encode($defaults([]), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
    exit(0);
}

// ---- 既定の出力は C3 の前と同じ ----
$golden = json_decode((string) file_get_contents(__DIR__ . '/fixtures/dept_levels_edu_golden.json'), true);
foreach ([[], ['dept_level' => '0'], ['dept_level' => 'all']] as $extra) {
    $label = $extra === [] ? 'dept_level なし' : 'dept_level=' . $extra['dept_level'];
    $now = $defaults($extra);
    foreach ($golden as $key => $before) {
        check($now[$key] === $before, "{$label}: {$key} は C3 の前の出力と同じ");
    }
}

// ---- 概要の部署別ランキング: 解答の点数を足して割り直す ----
$rank = static fn(string $level): array => array_map(static fn(array $d): array => [$d['department'], $d['respondent_count'], $d['average_score']],
    $json('edu_rep_handle_overview', ['dept_level' => $level])['by_department']);
check($rank('1') === [['営業本部', 5, 75.0], ['管理本部', 3, 70.0], ['(未設定)', 1, 50.0]],
    '1段目: 営業本部は A100 A40 B60 C90 H85 の平均 75(部署ごとの平均の平均 80.6 ではない)。全角、空白、空の段も同じ本部');
check($rank('2') === [['営業本部 / 東日本営業部', 5, 75.0], ['管理本部', 1, 70.0], ['管理本部 / 総務部', 2, 70.0], ['(未設定)', 1, 50.0]],
    '2段目: 「本部 / 部」でまとめ、段が1つの部署はそのまま。同じ平均は名前の順');

// ---- 配信の部署ごと(JSON と CSV) ----
$depts = static fn(int $id, string $level): array => array_map(static fn(array $d): array => [$d['department'], $d['assigned'], $d['completed'],
    $d['incomplete'], $d['passed'], $d['on_time_passed'], $d['pass_rate'], $d['on_time_pass_rate']],
    $json('edu_rep_handle_delivery_depts', ['id' => (string) $id, 'dept_level' => $level])['departments']);
check($depts($el, '1') === [
    ['(未設定)', 1, 1, 0, 0, 0, 0.0, 0.0],
    ['営業本部', 5, 4, 1, 3, 2, 60.0, 40.0],
    ['管理本部', 2, 2, 0, 1, 1, 50.0, 50.0],
], '1段目: 対象、完了、合格を足し、合格率と期限内合格率を対象者から計算し直す(テスト用と削除済みは入らない)');
check($depts($el, '2') === [
    ['(未設定)', 1, 1, 0, 0, 0, 0.0, 0.0],
    ['営業本部 / 東日本営業部', 4, 4, 0, 3, 2, 75.0, 50.0],
    ['営業本部 / 西日本営業部', 1, 0, 1, 0, 0, 0.0, 0.0],
    ['管理本部', 1, 1, 0, 0, 0, 0.0, 0.0],
    ['管理本部 / 総務部', 1, 1, 0, 1, 1, 100.0, 100.0],
], '2段目: 部までまとめる');
check($depts($aw, '1') === [['営業本部', 1, 1, 0, null, null, null, null], ['管理本部', 1, 1, 0, null, null, null, null]],
    'アウェアネス(合格点なし)は1段目でも合格率を出さない');
$csv = $runCsv(['action' => 'delivery_depts', 'id' => (string) $el, 'format' => 'csv', 'dept_level' => '1']);
check(str_contains($csv, "営業本部,5,4,1,3,60,40\r\n") && str_contains($csv, "管理本部,2,2,0,1,50,50\r\n")
    && !str_contains($csv, '第1課'), 'CSV も1段目でまとめる');

// ---- 部署 × 分野(C1): 解答の数と正解の数を足して正答率を計算し直す ----
$matrix = static function (string $level) use ($json): array {
    $m = $json('edu_rep_handle_tag_matrix', ['dept_level' => $level])['matrix'];
    return [array_column($m['tags'], 'name'), array_map(static fn(array $d): array => [$d['department'],
        array_map(static fn(array $c): array => [$c['answered'], $c['correct'], $c['correct_rate']], $d['cells'])], $m['departments'])];
};
check($matrix('1') === [['フィッシング', 'パスワード'], [
    ['(未設定)', [[1, 0, 0.0], [0, 0, null]]],
    ['営業本部', [[4, 2, 50.0], [3, 2, 66.7]]],
    ['管理本部', [[1, 1, 100.0], [2, 1, 50.0]]],
]], '1段目の部署×分野: A○B×C○H× で 2/4、A○B×H○ で 2/3');
check($matrix('2') === [['フィッシング', 'パスワード'], [
    ['(未設定)', [[1, 0, 0.0], [0, 0, null]]],
    ['営業本部 / 東日本営業部', [[4, 2, 50.0], [3, 2, 66.7]]],
    ['管理本部', [[0, 0, null], [1, 1, 100.0]]],
    ['管理本部 / 総務部', [[1, 1, 100.0], [1, 0, 0.0]]],
]], '2段目の部署×分野');
$tags = $json('edu_rep_handle_tags', ['dept_level' => '1']);
check($tags['matrix'] === $json('edu_rep_handle_tag_matrix', ['dept_level' => '1'])['matrix'], '分野のタブ(tags)の表も dept_level でまとめる');
$csv = $runCsv(['action' => 'tag_matrix', 'format' => 'csv', 'dept_level' => '1']);
check(str_contains($csv, "営業本部,50,4,66.7,3\r\n"), '部署×分野の CSV も1段目でまとめる');

// ---- 階層の有無(画面で選択を出すか) ----
$levels = $json('edu_rep_handle_dept_levels', []);
check($levels['has_hierarchy'] === true && $levels['max_depth'] === 3, '組織1は「/」で区切った部署があり、最も深いのは3段');
Db::run("UPDATE targets SET department = REPLACE(REPLACE(department, '/', '-'), '／', '-') WHERE tenant_id = 1 AND is_test = 0 AND status = 'active'");
$levels = $json('edu_rep_handle_dept_levels', []);
check($levels['has_hierarchy'] === false && $levels['max_depth'] <= 1, 'テスト用と削除済みの部署だけに区切りがあっても、階層ありにしない');

// ---- 入力の検査 ----
foreach (['3', '-1', 'x', '1.5'] as $bad) {
    $_GET = ['dept_level' => $bad];
    $r = call_handler('edu_rep_handle_overview', [], 'viewer');
    check($r['code'] === 400, "dept_level={$bad} は 400");
}

echo "ALL TESTS PASSED\n";
