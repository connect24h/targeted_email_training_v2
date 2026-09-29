<?php
declare(strict_types=1);

/**
 * 部署の階層の共通の処理(lib/DeptPath.php、C3、G14)。区切り(「/」と全角の「／」)、段の前後の空白、空の段、空の部署。
 */
require_once __DIR__ . '/helpers.php';
tet2_test_boot();
require_once __DIR__ . '/../lib/DeptPath.php';

// ---- 段に分ける ----
check(DeptPath::segments('営業本部/東日本営業部/第1課') === ['営業本部', '東日本営業部', '第1課'], '「/」で段に分ける');
check(DeptPath::segments('営業本部／西日本営業部') === ['営業本部', '西日本営業部'], '全角の「／」でも分ける');
check(DeptPath::segments(' 営業本部 / 東日本営業部 /　第2課　') === ['営業本部', '東日本営業部', '第2課'], '段の前後の空白(全角を含む)を除く');
check(DeptPath::segments('/営業本部//東日本営業部/') === ['営業本部', '東日本営業部'], '空の段は無視する');
check(DeptPath::segments('営業本部/ /第1課') === ['営業本部', '第1課'], '空白だけの段も無視する');
check(DeptPath::segments('') === [] && DeptPath::segments(null) === [] && DeptPath::segments(' / ／ ') === [], '空の部署は段なし');
check(DeptPath::segments('営業部') === ['営業部'], '区切りのない部署は1段');

// ---- 段ごとの名前 ----
$d = '営業本部/東日本営業部/第1課';
check(DeptPath::label($d, 1) === '営業本部', '1段目は最初の段');
check(DeptPath::label($d, 2) === '営業本部 / 東日本営業部', '2段目は最初の2段を「 / 」でつなぐ');
check(DeptPath::label($d, 0) === $d, '0(全部)は今までと同じ文字列');
check(DeptPath::label('  営業本部／西日本営業部 ', 0) === '営業本部／西日本営業部', '0(全部)は前後の空白だけを除き、区切りの書き方は変えない');
check(DeptPath::label('営業本部／西日本営業部', 2) === '営業本部 / 西日本営業部', '全角の区切りも2段目は「 / 」でつなぐ');
check(DeptPath::label('管理本部', 2) === '管理本部', '段が足りない部署はある段だけ');
check(DeptPath::label(' 営業本部 / 東日本営業部 ', 1) === '営業本部', '空白があっても同じ1段目になる');
foreach ([0, 1, 2] as $level) {
    check(DeptPath::label(null, $level) === '(未設定)' && DeptPath::label('  ', $level) === '(未設定)', "空の部署は(未設定)(段 {$level})");
}
check(DeptPath::label('//', 1) === '(未設定)', '区切りだけの部署は1段目で(未設定)');

// ---- クエリの段 ----
foreach ([[null, 0], ['', 0], ['all', 0], ['0', 0], ['1', 1], ['2', 2]] as [$raw, $want]) {
    check(DeptPath::parseLevel($raw) === $want, 'dept_level=' . var_export($raw, true) . " は {$want}");
}
foreach (['3', '-1', 'x', '1.5', '01', ['1']] as $bad) {
    $thrown = false;
    try {
        DeptPath::parseLevel($bad);
    } catch (InvalidArgumentException $e) {
        $thrown = true;
    }
    check($thrown, 'dept_level=' . json_encode($bad) . ' は受け付けない');
}

// ---- まとめ直し: 数を足す(率は足さない) ----
$rows = [
    ['department' => '営業本部/東日本営業部/第1課', 'n' => 2, 'ok' => 1, 'rate' => 50.0],
    ['department' => '営業本部／西日本営業部', 'n' => 1, 'ok' => 1, 'rate' => 100.0],
    ['department' => '(未設定)', 'n' => 3, 'ok' => 0, 'rate' => 0.0],
    ['department' => '管理本部', 'n' => 1, 'ok' => 0, 'rate' => 0.0],
];
check(DeptPath::regroup($rows, 'department', 1, ['n', 'ok']) === [
    ['department' => '(未設定)', 'n' => 3, 'ok' => 0],
    ['department' => '営業本部', 'n' => 3, 'ok' => 2],
    ['department' => '管理本部', 'n' => 1, 'ok' => 0],
], '1段目でまとめ、数だけを足し、名前の順に並べる(率の列は捨てる)');

echo "ALL TESTS PASSED\n";
