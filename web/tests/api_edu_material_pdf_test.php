<?php
declare(strict_types=1);

// 教材の PDF 取込 API(分割アップロード、変換、ページの一覧、画像の場所とアクセスの範囲)。08 設計書の G16。
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/fixtures/pdf_fixture.php';
require_once __DIR__ . '/../lib/EduMedia.php';
require_once __DIR__ . '/../lib/OfficeDocumentReader.php';

$root = sys_get_temp_dir() . '/tet2-edu-media-api-' . getmypid();
putenv("TET2_EDU_MEDIA_DIR={$root}");
register_shutdown_function(static function () use ($root) {
    exec('rm -rf ' . escapeshellarg($root));
});

tet2_test_boot();
load_api('edu_materials');
$tenantId = (int) current_user()['tenant_id'];

$pdf = tet2_test_make_pdf(['Lesson page one', 'Lesson page two', 'Lesson page three']);

// --- 分割アップロード → 取込 ---
$r = call_handler('edu_m_handle_upload_begin', []);
check($r['code'] === 200 && preg_match('/^[0-9a-f]{32}$/', $r['payload']['upload_id'] ?? ''), 'MP-1: アップロードを始められる');
$uploadId = $r['payload']['upload_id'];
check($GLOBALS['__TET2_TEST_CSRF_CALLS'] === 1, 'MP-1: アップロードの開始に CSRF を要求する');
$chunks = str_split($pdf, (int) ceil(strlen($pdf) / 2));
foreach ($chunks as $i => $chunk) {
    $r = call_handler('edu_m_handle_upload_chunk', ['upload_id' => $uploadId, 'index' => $i, 'data_base64' => base64_encode($chunk)]);
    check($r['code'] === 200, "MP-2: 分割 {$i} を受け取る");
}
check(($r['payload']['received_bytes'] ?? 0) === strlen($pdf), 'MP-2: 受け取った合計が PDF の大きさと一致する');

$r = call_handler('edu_m_handle_import_pdf', ['upload_id' => $uploadId, 'filename' => '初動報告.pdf', 'title' => '初動報告の教材']);
check($r['code'] === 201, 'MP-3: PDF を取り込んで教材を作る');
$material = $r['payload']['material'];
check($material['format'] === 'page_images' && $material['page_count'] === 3, 'MP-4: ページ画像の教材として3ページで保存する');
check(count($material['pages']) === 3 && str_contains($material['pages'][1]['page_text'], 'Lesson page two'),
    'MP-5: ページごとの文字を持つ');
check(count($material['slides']) === 3 && $material['slides'][0]['title'] === 'ページ 1',
    'MP-6: 文字だけで表示する経路のためにスライドとしても持つ');
check(($material['source_name'] ?? '') === '初動報告.pdf', 'MP-7: 元のファイル名を記録する');
check((int) Db::one('SELECT is_active FROM edu_materials WHERE id = ?', [$material['id']])['is_active'] === 1,
    'MP-7: 変換が終わった教材は表示の状態になる');
check(!is_dir($root . '/tmp/' . $uploadId), 'MP-8: 取込後に一時ファイルを消す');

$materialId = $material['id'];
$_GET = ['id' => (string) $materialId];
$r = call_handler('edu_m_handle_get', [], 'viewer');
check($r['code'] === 200 && count($r['payload']['material']['pages']) === 3, 'MP-9: 閲覧者も教材のページ一覧を取れる');

$GLOBALS['__TET2_TEST_ROLE'] = 'viewer';
$file = edu_m_page_image_file(current_user(), $materialId, 2);
check(is_file($file['path']) && $file['mime'] === 'image/jpeg' && str_ends_with($file['path'], '/page-002.jpg'),
    'MP-10: ページ画像のファイルの場所を決める');
$_GET = [];

// --- 他のテナントの教材の画像は取れない ---
Db::run("INSERT INTO tenants (id, name, slug, data_dir) VALUES (99, '他組織', 'other99', '/tmp/other99')");
$otherId = Db::insert(
    "INSERT INTO edu_materials (tenant_id, title, slides, format, page_count) VALUES (99, '他組織の教材', '[]', 'page_images', 1)"
);
Db::run("INSERT INTO edu_material_pages (material_id, page_no, image_name) VALUES (?, 1, 'page-001.jpg')", [$otherId]);
$denied = false;
try {
    edu_m_page_image_file(current_user(), $otherId, 1);
} catch (Tet2TestExit $e) {
    $denied = $e->httpCode === 404;
}
check($denied, 'MP-11: 他のテナントの教材のページ画像は取れない');

// --- 壊れた PDF は教材を残さない ---
$r = call_handler('edu_m_handle_upload_begin', []);
$badId = $r['payload']['upload_id'];
call_handler('edu_m_handle_upload_chunk', ['upload_id' => $badId, 'index' => 0, 'data_base64' => base64_encode('not a pdf at all')]);
$before = (int) Db::one('SELECT COUNT(*) AS n FROM edu_materials')['n'];
$r = call_handler('edu_m_handle_import_pdf', ['upload_id' => $badId, 'filename' => 'bad.pdf']);
check($r['code'] === 400, 'MP-12: PDF でないファイルは取り込まない');
check((int) Db::one('SELECT COUNT(*) AS n FROM edu_materials')['n'] === $before, 'MP-12: 失敗したら教材の行を残さない');

// --- 他の利用者のアップロードは使えない ---
$r = call_handler('edu_m_handle_upload_begin', []);
$foreign = $r['payload']['upload_id'];
$meta = json_decode((string) file_get_contents($root . '/tmp/' . $foreign . '/meta.json'), true);
$meta['user_id'] = 999;
file_put_contents($root . '/tmp/' . $foreign . '/meta.json', json_encode($meta));
$r = call_handler('edu_m_handle_upload_chunk', ['upload_id' => $foreign, 'index' => 0, 'data_base64' => base64_encode('%PDF-')]);
check($r['code'] === 400, 'MP-13: 他の利用者が始めたアップロードには追記できない');

// --- 途中で失敗した分割アップロードを破棄できる ---
$r = call_handler('edu_m_handle_upload_begin', []);
$discard = $r['payload']['upload_id'];
$r = call_handler('edu_m_handle_upload_discard', ['upload_id' => $discard]);
check($r['code'] === 200 && !is_dir($root . '/tmp/' . $discard), 'MP-14: 分割アップロードを破棄できる');
$r = call_handler('edu_m_handle_upload_discard', ['upload_id' => $discard]);
check($r['code'] === 200, 'MP-14: 破棄済みのアップロードを重ねて破棄してもエラーにしない');

echo "ALL TESTS PASSED\n";
