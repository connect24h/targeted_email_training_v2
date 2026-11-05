<?php
declare(strict_types=1);

/**
 * SimpleXlsx のグラフ機能のテスト。
 * 外部ツール(soffice)に依存せず、生成した .xlsx を ZipArchive で開いて
 * 必要なパート・参照・XML 片の有無を検査する(綴りミスによるパート欠落を捕捉)。
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../lib/SimpleXlsx.php';

$tmp = tempnam(sys_get_temp_dir(), 'xlsxchart_') . '.xlsx';
register_shutdown_function(static fn () => @unlink($tmp));

$xlsx = new SimpleXlsx();
// シート1: 棒グラフ用(会社別の率を模す)。A=カテゴリ, B=率1, C=率2。
$xlsx->addSheet('会社別', [
    ['会社', '表示率', '認証率'],
    ['A社', 50.0, 10.0],
    ['B社', 30.0, 5.0],
    ['C社', 70.0, 20.0],
]);
$xlsx->addChart('会社別', 'bar', '会社別 表示率・認証率', ['col' => 0, 'from' => 2, 'to' => 4], [
    ['col' => 1, 'from' => 2, 'to' => 4, 'name' => '表示率'],
    ['col' => 2, 'from' => 2, 'to' => 4, 'name' => '認証率'],
], ['fromCol' => 4, 'fromRow' => 1, 'toCol' => 12, 'toRow' => 16]);

// シート2: 折れ線グラフ用(日別タイムラインを模す)。
$xlsx->addSheet('日別', [
    ['日付', '表示', '認証'],
    ['11/1', 3, 1],
    ['11/2', 5, 2],
]);
$xlsx->addChart('日別', 'line', '日別 表示・認証の推移', ['col' => 0, 'from' => 2, 'to' => 3], [
    ['col' => 1, 'from' => 2, 'to' => 3, 'name' => '表示'],
    ['col' => 2, 'from' => 2, 'to' => 3, 'name' => '認証'],
], ['fromCol' => 4, 'fromRow' => 1, 'toCol' => 12, 'toRow' => 16]);

// グラフ無しのシートも混ぜ、drawing 参照が付かないことを確認。
$xlsx->addSheet('素データ', [['x', 'y'], [1, 2]]);

$xlsx->saveToFile($tmp);

// --- ZIP として開ける ---
$zip = new ZipArchive();
check($zip->open($tmp) === true, '生成した .xlsx が ZIP として開ける');

$get = static function (string $name) use ($zip): string {
    $s = $zip->getFromName($name);
    return $s === false ? '' : $s;
};

// --- 必要パートが存在する ---
check($get('xl/charts/chart1.xml') !== '', 'chart1.xml が存在する');
check($get('xl/charts/chart2.xml') !== '', 'chart2.xml が存在する');
check($get('xl/drawings/drawing1.xml') !== '', 'drawing1.xml が存在する(会社別)');
check($get('xl/drawings/drawing2.xml') !== '', 'drawing2.xml が存在する(日別)');
check($get('xl/drawings/_rels/drawing1.xml.rels') !== '', 'drawing1 の rels が存在する');
check($get('xl/worksheets/_rels/sheet1.xml.rels') !== '', 'sheet1 の rels が存在する(drawing 参照)');

// --- Content_Types に Override がある ---
$ct = $get('[Content_Types].xml');
check(strpos($ct, '/xl/charts/chart1.xml') !== false, 'Content_Types に chart1 の Override');
check(strpos($ct, '/xl/drawings/drawing1.xml') !== false, 'Content_Types に drawing1 の Override');
check(substr_count($ct, 'drawingml.chart+xml') === 2, 'chart の Override が2つ(=グラフ2個)');

// --- chart XML の中身 ---
$c1 = $get('xl/charts/chart1.xml');
check(strpos($c1, '<c:barChart>') !== false, 'chart1 は barChart');
check(strpos($c1, '会社別') !== false || strpos($c1, '表示率') !== false, 'chart1 にタイトル/系列名が入る');
check(strpos($c1, "会社別!") !== false, 'chart1 がシート名つきのセル範囲を参照する');
check(strpos($c1, '$A$2:$A$4') !== false, 'chart1 のカテゴリ範囲が $A$2:$A$4');
check(strpos($c1, '$B$2:$B$4') !== false, 'chart1 の系列1の値範囲が $B$2:$B$4');

$c2 = $get('xl/charts/chart2.xml');
check(strpos($c2, '<c:lineChart>') !== false, 'chart2 は lineChart');

// --- worksheet 側に drawing 参照がある/無い ---
$ws1 = $get('xl/worksheets/sheet1.xml');
check(strpos($ws1, '<drawing r:id="rIdDrawing"/>') !== false, 'グラフありシートに <drawing> 参照がある');
$ws3 = $get('xl/worksheets/sheet3.xml');
check(strpos($ws3, '<drawing') === false, 'グラフ無しシートには <drawing> 参照が無い');

// --- drawing rels が chart を指す ---
$dr1 = $get('xl/drawings/_rels/drawing1.xml.rels');
check(strpos($dr1, '../charts/chart1.xml') !== false, 'drawing1 rels が chart1 を指す');

$zip->close();

echo "\n✅ simplexlsx_chart_test 完了\n";
