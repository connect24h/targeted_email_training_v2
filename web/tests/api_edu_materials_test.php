<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../lib/OfficeDocumentReader.php';

tet2_test_boot();
check(file_exists(__DIR__ . '/../api/edu_materials.php'), '教材APIがある');
load_api('edu_materials');

$slides = [
    ['title' => '不審メールに気づく', 'body' => '送信者とURLを確認します。'],
    ['title' => '失敗後の行動', 'body' => '速やかに報告します。'],
];

$r = call_handler('edu_m_handle_create', [
    'title' => '標的型メール訓練フォローアップ',
    'description' => '訓練失敗者向け',
    'slides' => $slides,
], 'operator');
check($r['code'] === 201, 'EM-1: スライド教材を作成できる');
$materialId = (int) $r['payload']['material']['id'];

$r = call_handler('edu_m_handle_update', [
    'id' => $materialId,
    'slides' => [['title' => '差し替え後', 'body' => '新しい教材本文']],
], 'operator');
check($r['code'] === 200, 'EM-2: スライド教材を差し替えられる');
check(count($r['payload']['material']['slides'] ?? []) === 1, 'EM-2: 差し替え後のスライドを返す');

$r = call_handler('edu_m_handle_create', [
    'title' => '不正教材',
    'slides' => [['title' => '', 'body' => '本文']],
], 'operator');
check($r['code'] === 400, 'EM-3: 空タイトルのスライドを拒否する');

Db::run("INSERT INTO edu_materials (tenant_id, title, slides, is_active) VALUES (2, '他テナント教材', '[]', 1)");
$otherId = (int) Db::one('SELECT id FROM edu_materials WHERE tenant_id = 2 ORDER BY id DESC LIMIT 1')['id'];
$r = call_handler('edu_m_handle_update', [
    'id' => $otherId,
    'title' => '不正更新',
], 'operator');
check($r['code'] === 404, 'EM-4: 他テナント教材を更新できない');

$pptxPath = tempnam(sys_get_temp_dir(), 'tet2-pptx-');
$pptx = new ZipArchive();
$pptx->open($pptxPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
$pptx->addFromString('ppt/slides/slide1.xml', '<p:sld xmlns:p="p" xmlns:a="a"><p:sp><p:nvSpPr><p:nvPr><p:ph type="title"/></p:nvPr></p:nvSpPr><p:txBody><a:p><a:r><a:t>取込タイトル</a:t></a:r></a:p></p:txBody></p:sp><p:sp><p:txBody><a:p><a:r><a:t>取込本文</a:t></a:r></a:p></p:txBody></p:sp></p:sld>');
$pptx->close();
$pptxBytes = (string) file_get_contents($pptxPath);
unlink($pptxPath);
$r = call_handler('edu_m_handle_import_pptx', [
    'filename' => 'training.pptx',
    'file_base64' => base64_encode($pptxBytes),
], 'operator');
check($r['code'] === 200, 'EM-5: PowerPointを教材スライドへ変換できる');
check(($r['payload']['slides'][0]['title'] ?? '') === '取込タイトル', 'EM-5: PowerPointタイトルを返す');
check(Db::one("SELECT id FROM edu_materials WHERE title = 'training'") === null, 'EM-5: 確認前には教材を保存しない');

$r = call_handler('edu_m_handle_import_pptx', [
    'filename' => 'training.ppt',
    'file_base64' => base64_encode($pptxBytes),
], 'operator');
check($r['code'] === 400, 'EM-6: 旧PowerPoint形式を拒否する');

// EM-7: 一覧の形式(本の版、スライド版、文字)と、使っている配信の数(この組織の配信だけ)
$page = static function (string $title, int $width, int $height): int {
    Db::run("INSERT INTO edu_materials (tenant_id, title, slides, format, page_count, is_active) VALUES (1, ?, '[]', 'page_images', 1, 1)", [$title]);
    $id = (int) Db::one('SELECT MAX(id) AS id FROM edu_materials')['id'];
    Db::run("INSERT INTO edu_material_pages (material_id, page_no, image_name, width, height) VALUES (?, 1, 'page-001.jpg', ?, ?)", [$id, $width, $height]);
    return $id;
};
$bookId = $page('本の版', 1240, 1748);
$wideId = $page('横長の教材', 1920, 1080);
$namedId = $page('ランサムウェア（スライド版）', 1240, 1748);
Db::run("INSERT INTO edu_deliveries (tenant_id, title, material_id) VALUES (1, '配信A', ?), (1, '配信B', ?), (2, '他組織の配信', ?)", [$bookId, $bookId, $bookId]);
$r = call_handler('edu_m_handle_list', [], 'viewer');
$byId = array_column($r['payload']['materials'], null, 'id');
check($r['code'] === 200, 'EM-7: viewer も教材の一覧を読める');
check($byId[$bookId]['kind'] === 'book' && $byId[$wideId]['kind'] === 'slide' && $byId[$namedId]['kind'] === 'slide',
    'EM-7: 縦長の PDF は本の版、横長か題名に「スライド版」があればスライド版');
check($byId[$materialId]['kind'] === 'text', 'EM-7: 文字のスライドは text');
check($byId[$bookId]['delivery_count'] === 2 && $byId[$wideId]['delivery_count'] === 0,
    'EM-7: 使っている配信の数は、この組織の配信だけを数える');
check(!array_key_exists('first_width', $byId[$bookId]), 'EM-7: 推定に使った列は返さない');

echo "ALL TESTS PASSED\n";
