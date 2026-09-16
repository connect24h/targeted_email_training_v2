<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../lib/OfficeDocumentReader.php';
require_once __DIR__ . '/../lib/SimpleXlsx.php';

function officeFixture(array $entries): string
{
    $path = tempnam(sys_get_temp_dir(), 'tet2-office-');
    $zip = new ZipArchive();
    check($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true, 'fixture ZIPを作成できる');
    foreach ($entries as $name => $content) {
        $zip->addFromString($name, $content);
    }
    $zip->close();
    $bytes = file_get_contents($path);
    unlink($path);
    return (string) $bytes;
}

$pptx = officeFixture([
    'ppt/presentation.xml' => '<p:presentation xmlns:p="p" xmlns:r="r"><p:sldIdLst><p:sldId id="256" r:id="rId1"/><p:sldId id="257" r:id="rId2"/></p:sldIdLst></p:presentation>',
    'ppt/_rels/presentation.xml.rels' => '<Relationships><Relationship Id="rId2" Target="slides/slide2.xml"/><Relationship Id="rId1" Target="slides/slide1.xml"/></Relationships>',
    'ppt/slides/slide2.xml' => '<p:sld xmlns:p="p" xmlns:a="a"><p:cSld><p:spTree>'
        . '<p:sp><p:nvSpPr><p:nvPr><p:ph type="title"/></p:nvPr></p:nvSpPr><p:txBody><a:p><a:r><a:t>報告する</a:t></a:r></a:p></p:txBody></p:sp>'
        . '<p:sp><p:txBody><a:p><a:r><a:t>不審なメールは</a:t></a:r><a:r><a:t>担当者へ報告</a:t></a:r></a:p></p:txBody></p:sp>'
        . '</p:spTree></p:cSld></p:sld>',
    'ppt/slides/slide1.xml' => '<p:sld xmlns:p="p" xmlns:a="a"><p:cSld><p:spTree>'
        . '<p:sp><p:nvSpPr><p:nvPr><p:ph type="ctrTitle"/></p:nvPr></p:nvSpPr><p:txBody><a:p><a:r><a:t>URLを確認</a:t></a:r></a:p></p:txBody></p:sp>'
        . '<p:sp><p:txBody><a:p><a:r><a:t>リンク先を開く前に確認します。</a:t></a:r></a:p></p:txBody></p:sp>'
        . '</p:spTree></p:cSld></p:sld>',
]);
$slides = OfficeDocumentReader::readPowerPoint($pptx);
check(count($slides) === 2, 'PPTXから2枚のスライドを抽出できる');
check($slides[0]['title'] === 'URLを確認', 'PowerPoint内の表示順でタイトルを抽出する');
check($slides[1]['body'] === '不審なメールは担当者へ報告', '分割された文字列を本文として連結する');

$xlsxPath = tempnam(sys_get_temp_dir(), 'tet2-xlsx-');
$xlsx = new SimpleXlsx();
$xlsx->addSheet('設問', [
    ['カテゴリスラッグ', '設問文', '種別', '選択肢（改行区切り）', '正答番号（1始まり）', '難易度', '解説', '有効'],
    ['phishing', '差出人で確認するものは？', 'single_choice', "表示名\nメールアドレス", '2', 2, 'アドレスを確認します', 1],
]);
$xlsx->saveToFile($xlsxPath);
$xlsxBytes = (string) file_get_contents($xlsxPath);
unlink($xlsxPath);
$rows = OfficeDocumentReader::readFirstWorksheet($xlsxBytes);
check(($rows[1][1] ?? '') === '差出人で確認するものは？', 'XLSXの設問文を読み取れる');
check(($rows[1][3] ?? '') === "表示名\nメールアドレス", 'XLSXセル内改行を保持する');

tet2_test_boot();
load_api('edu_questions');
$tenantId = current_user()['tenant_id'];
Db::run(
    "INSERT INTO edu_categories (tenant_id, name, slug, is_active, is_shared) VALUES (?, 'フィッシング', 'phishing-import', 1, 0)",
    [$tenantId]
);

$importPath = tempnam(sys_get_temp_dir(), 'tet2-import-');
$importBook = new SimpleXlsx();
$importBook->addSheet('設問', [
    ['カテゴリスラッグ', '設問文', '種別', '選択肢（改行区切り）', '正答番号（1始まり）', '難易度', '解説', '有効'],
    ['phishing-import', '添付ファイルを開く前に行うことは？', 'single_choice', "確認する\nすぐ開く", '1', 1, '送信元を確認します', 1],
    ['phishing-import', '不審メールで行うことは？', 'multiple_choice', "報告する\n返信する\n削除する", '1,3', 2, '', 1],
]);
$importBook->saveToFile($importPath);
$importBytes = (string) file_get_contents($importPath);
unlink($importPath);

$r = call_handler('edu_q_handle_import_xlsx', [
    'filename' => 'questions.xlsx',
    'file_base64' => base64_encode($importBytes),
], 'operator');
check($r['code'] === 201, 'Excelから設問を一括追加できる');
check(($r['payload']['imported'] ?? 0) === 2, '追加件数を返す');
check((int) Db::one('SELECT COUNT(*) AS c FROM edu_questions WHERE tenant_id = ?', [$tenantId])['c'] === 2,
    '2設問を自組織へ保存する');

$invalidBookPath = tempnam(sys_get_temp_dir(), 'tet2-import-invalid-');
$invalidBook = new SimpleXlsx();
$invalidBook->addSheet('設問', [
    ['カテゴリスラッグ', '設問文', '種別', '選択肢（改行区切り）', '正答番号（1始まり）', '難易度', '解説', '有効'],
    ['phishing-import', '追加されない設問', 'single_choice', "A\nB", '1', 1, '', 1],
    ['missing-category', '不正カテゴリ', 'single_choice', "A\nB", '1', 1, '', 1],
]);
$invalidBook->saveToFile($invalidBookPath);
$invalidBytes = (string) file_get_contents($invalidBookPath);
unlink($invalidBookPath);
$before = (int) Db::one('SELECT COUNT(*) AS c FROM edu_questions WHERE tenant_id = ?', [$tenantId])['c'];
$r = call_handler('edu_q_handle_import_xlsx', [
    'filename' => 'invalid.xlsx',
    'file_base64' => base64_encode($invalidBytes),
], 'operator');
check($r['code'] === 400, '存在しないカテゴリを含むExcelを拒否する');
$after = (int) Db::one('SELECT COUNT(*) AS c FROM edu_questions WHERE tenant_id = ?', [$tenantId])['c'];
check($after === $before, '不正行がある場合は1件も追加しない');

$exportRows = edu_q_export_rows($tenantId);
check($exportRows[0][0] === 'カテゴリ名' && $exportRows[0][1] === 'カテゴリスラッグ', 'Excel出力にテンプレート列を含む');
check(count($exportRows) === 3, '全設問をExcel出力用の行にする');

Db::run("INSERT INTO edu_categories (tenant_id, name, slug, is_active, is_shared) VALUES (2, '他組織', 'other-only', 1, 0)");
$otherCategoryId = (int) Db::one("SELECT id FROM edu_categories WHERE tenant_id = 2 AND slug = 'other-only'")['id'];
Db::run(
    "INSERT INTO edu_questions (tenant_id, category_id, title, question_type, options, correct_answer, difficulty, is_active, is_shared)
     VALUES (2, ?, '他組織の設問', 'single_choice', '[\"A\",\"B\"]', '[0]', 1, 1, 0)",
    [$otherCategoryId]
);
$tenantRows = edu_q_export_rows($tenantId);
check(count($tenantRows) === 3, 'Excel出力に他組織の設問を含めない');

echo "ALL TESTS PASSED\n";
