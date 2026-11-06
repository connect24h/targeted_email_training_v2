<?php
declare(strict_types=1);

/**
 * データビューアの「Excelで全部ダウンロード」(campaign_files.php action=export_xlsx)のテスト。
 * data_dir 内の CSV マスタ(5種)を各1シートにまとめる cf_build_data_xlsx を、合成 data_dir で検証する。
 * download() は exit するため、ここでは SimpleXlsx を saveToFile し ZipArchive でシートを確かめる。
 */

require_once __DIR__ . '/helpers.php';
tet2_test_boot();
require_once __DIR__ . '/../lib/SimpleXlsx.php';
// campaign_files.php はスクリプト型。<?php/require/try以降を剥がして定数と cf_* 関数だけを読む。
load_api('campaign_files');

check(function_exists('cf_build_data_xlsx'), 'cf_build_data_xlsx が定義されている');
check(defined('CAMPAIGN_DATA_FILES'), 'CAMPAIGN_DATA_FILES 定数が定義されている');

// 合成 data_dir を一時ディレクトリに作る(本番 data はいっさい触らない)。
$dir = sys_get_temp_dir() . '/cf_xlsx_test_' . getmypid() . '_' . random_int(1000, 9999);
@mkdir($dir, 0700, true);
register_shutdown_function(static function () use ($dir) {
    foreach (glob($dir . '/*') ?: [] as $f) { @unlink($f); }
    @rmdir($dir);
});

// list.csv と URL.csv だけ置く(5種のうち2種が存在するケース。残りは無い)。
file_put_contents($dir . '/list.csv', "email,name\na@example.test,山田太郎\nb@example.test,鈴木花子\n");
file_put_contents($dir . '/URL.csv', "no,url\n1,https://example.test/1\n");
// 列数不一致の行(超過)が切り詰められること。
file_put_contents($dir . '/kenmei.csv', "no,subject\n1,件名A,余分列\n");

$xlsx = cf_build_data_xlsx($dir);
check($xlsx instanceof SimpleXlsx, '存在ファイルがあれば SimpleXlsx を返す');

$tmp = tempnam(sys_get_temp_dir(), 'cfxlsx_') . '.xlsx';
register_shutdown_function(static fn () => @unlink($tmp));
$xlsx->saveToFile($tmp);

$zip = new ZipArchive();
check($zip->open($tmp) === true, '生成した .xlsx が ZIP として開ける');
$workbook = $zip->getFromName('xl/workbook.xml') ?: '';
// 置いた3種(list/URL/kenmei)がシートになる。認証HTMLや無いCSVはシートにしない。
check(strpos($workbook, 'name="list"') !== false, 'list シートがある');
check(strpos($workbook, 'name="URL"') !== false, 'URL シートがある');
check(strpos($workbook, 'name="kenmei"') !== false, 'kenmei シートがある');
check(strpos($workbook, 'name="honbun"') === false, '置いていない honbun はシートにしない');
check(substr_count($workbook, '<sheet ') === 3, 'シートは置いた3種だけ(3枚)');

// 1枚目(list)の中身: ヘッダ＋2行。共有文字列 or inlineStr のどちらでも「山田太郎」を含むこと。
$sheet1 = $zip->getFromName('xl/worksheets/sheet1.xml') ?: '';
$shared = $zip->getFromName('xl/sharedStrings.xml') ?: '';
$haystack = $sheet1 . $shared;
check(strpos($haystack, '山田太郎') !== false, 'list シートに山田太郎が入る');
check(strpos($haystack, '鈴木花子') !== false, 'list シートに鈴木花子が入る');
$zip->close();

// 空ディレクトリ(CSV が1つも無い)なら null。
$empty = sys_get_temp_dir() . '/cf_xlsx_empty_' . getmypid();
@mkdir($empty, 0700, true);
register_shutdown_function(static fn () => @rmdir($empty));
check(cf_build_data_xlsx($empty) === null, 'CSV が無ければ null(=ダウンロード不可)');
check(cf_build_data_xlsx('') === null, 'data_dir 空文字なら null');

echo "\n✅ campaign_files_xlsx_test 完了\n";
