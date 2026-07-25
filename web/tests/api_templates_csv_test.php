<?php
declare(strict_types=1);

/**
 * テンプレート CSV 一括登録(add/upsert)のテスト(2026-07-19)。
 * HTML本文(引用符内改行)を含むCSVのパース、add/upsert の挙動、権限を固定する。
 */

require_once __DIR__ . '/helpers.php';
tet2_test_boot();
load_api('templates');

// HTML本文(改行・カンマ・引用符入り)を含むCSVを生成する。
function make_csv(array $rows): string
{
    $fp = fopen('php://temp', 'r+');
    fputcsv($fp, ['name', 'kind', 'format', 'content', 'auth_flag', 'scenario_key']);
    foreach ($rows as $r) { fputcsv($fp, $r); }
    rewind($fp);
    $s = stream_get_contents($fp);
    fclose($fp);
    return $s;
}

$multilineHtml = "<html>\n<body>\n<p>お世話になります、\"引用\"入り</p>\n#\$1\$#\n</body>\n</html>";
$csv = make_csv([
    ['CSV_TEST_件名A', 'subject', 'text', '件名A本文', '', 'csvtest'],
    ['CSV_TEST_本文A', 'body', 'html', $multilineHtml, '', 'csvtest'],
    ['CSV_TEST_偽ログイン', 'phish_login', 'html', '<html>login</html>', '1', ''],
]);

// operator は 403
$r = call_handler('templates_handle_import_csv', ['csv' => $csv, 'mode' => 'add'], 'operator');
check($r['code'] === 403, 'operator の CSV import → 403');

// tenant_admin で add → 3件追加、HTML本文が改行込みで保存される
$r = call_handler('templates_handle_import_csv', ['csv' => $csv, 'mode' => 'add'], 'tenant_admin');
check($r['code'] === 200 && $r['payload']['added'] === 3, 'add で3件追加');
$bodyRow = Db::one("SELECT content, format FROM templates WHERE name='CSV_TEST_本文A' AND kind='body'");
check($bodyRow !== null && str_contains($bodyRow['content'], "\n") && str_contains($bodyRow['content'], '"引用"'),
    'HTML本文が改行・引用符込みで正しくパース・保存される');
check(Db::one("SELECT auth_flag FROM templates WHERE name='CSV_TEST_偽ログイン'")['auth_flag'] == 1, 'phish の auth_flag=1 が保存される');
check(Db::one("SELECT scenario_key FROM templates WHERE name='CSV_TEST_件名A'")['scenario_key'] === 'csvtest', 'scenario_key が保存される');

// 同じCSVを add で再import → 全件スキップ(同名)
$r = call_handler('templates_handle_import_csv', ['csv' => $csv, 'mode' => 'add'], 'tenant_admin');
check($r['code'] === 200 && $r['payload']['added'] === 0 && $r['payload']['skipped'] === 3, 'add 再importは同名3件スキップ');

// 内容を変えて upsert → 更新される
$csv2 = make_csv([['CSV_TEST_件名A', 'subject', 'text', '更新後の件名A', '', 'csvtest']]);
$r = call_handler('templates_handle_import_csv', ['csv' => $csv2, 'mode' => 'upsert'], 'tenant_admin');
check($r['code'] === 200 && $r['payload']['updated'] === 1, 'upsert で1件更新');
check(Db::one("SELECT content FROM templates WHERE name='CSV_TEST_件名A'")['content'] === '更新後の件名A', 'upsert の内容が反映される');

// 不正な kind の行はスキップ(errors に記録)
$csvBad = make_csv([['CSV_TEST_不正', 'invalid_kind', 'text', '内容', '', '']]);
$r = call_handler('templates_handle_import_csv', ['csv' => $csvBad, 'mode' => 'add'], 'tenant_admin');
check($r['payload']['skipped'] === 1 && count($r['payload']['errors']) === 1, '不正な kind の行はスキップされエラー記録');

echo "ALL TESTS PASSED\n";
