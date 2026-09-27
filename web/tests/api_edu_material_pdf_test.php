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

// --- PDF の差し替え(表紙を足した改訂版などを、配信のリンクを変えずに入れ替える) ---
$upload = static function (string $bytes): string {
    $id = call_handler('edu_m_handle_upload_begin', [])['payload']['upload_id'];
    call_handler('edu_m_handle_upload_chunk', ['upload_id' => $id, 'index' => 0, 'data_base64' => base64_encode($bytes)]);
    return $id;
};
$_GET = ['id' => (string) $materialId];
$revBefore = call_handler('edu_m_handle_get', [], 'viewer')['payload']['material']['rev'] ?? null;
$_GET = [];
check(is_string($revBefore) && $revBefore !== '', 'MP-15: 教材は画像の版(rev)を持つ');
$GLOBALS['__TET2_TEST_AUDIT'] = [];
$replaceId = $upload(tet2_test_make_pdf(['Cover page', 'Lesson page one', 'Lesson page two', 'Lesson page three']));
$r = call_handler('edu_m_handle_replace_pdf', ['id' => $materialId, 'upload_id' => $replaceId, 'filename' => '初動報告-表紙つき.pdf']);
check($r['code'] === 200, 'MP-16: PDF を差し替えられる');
$replaced = $r['payload']['material'];
check($replaced['id'] === $materialId && $replaced['page_count'] === 4 && count($replaced['pages']) === 4,
    'MP-16: 同じ教材のまま4ページになる');
check(str_contains($replaced['pages'][0]['page_text'], 'Cover page') && $replaced['slides'][3]['title'] === 'ページ 4',
    'MP-16: ページの文字とスライドも新しくなる');
check(($replaced['source_name'] ?? '') === '初動報告-表紙つき.pdf' && $replaced['title'] === '初動報告の教材',
    'MP-16: 元のファイル名は新しくなり、表題は変わらない');
check(($replaced['rev'] ?? null) !== $revBefore, 'MP-17: 画像の版が変わる(ブラウザに古いページ画像を使わせない)');
$dir = EduMedia::materialDir($tenantId, $materialId);
check(is_file($dir . '/page-004.jpg'), 'MP-17: 新しいページ画像を置く');
check(glob($dir . '.*') === [], 'MP-17: 作業用のディレクトリを残さない');
check(!is_dir($root . '/tmp/' . $replaceId), 'MP-17: 差し替え後に一時ファイルを消す');
check(in_array('edu_material.replace_pdf', array_column($GLOBALS['__TET2_TEST_AUDIT'], 'action'), true), 'MP-17: 監査ログを残す');

$textId = Db::insert("INSERT INTO edu_materials (tenant_id, title, slides) VALUES (?, '文字の教材', '[]')", [$tenantId]);
$r = call_handler('edu_m_handle_replace_pdf', ['id' => $textId, 'upload_id' => $upload(tet2_test_make_pdf(['x'])), 'filename' => 'x.pdf']);
check($r['code'] === 409, 'MP-18: 文字の教材は PDF で差し替えない');
$r = call_handler('edu_m_handle_replace_pdf', ['id' => $otherId, 'upload_id' => $upload(tet2_test_make_pdf(['x'])), 'filename' => 'x.pdf']);
check($r['code'] === 404, 'MP-18: 他のテナントの教材は差し替えられない');
$sharedId = Db::insert("INSERT INTO edu_materials (tenant_id, title, slides, format, page_count, is_shared) VALUES (NULL, '共有の教材', '[]', 'page_images', 1, 1)");
$r = call_handler('edu_m_handle_replace_pdf', ['id' => $sharedId, 'upload_id' => $upload(tet2_test_make_pdf(['x'])), 'filename' => 'x.pdf']);
check($r['code'] === 403, 'MP-18: 共有の教材を差し替えられるのは superadmin だけ');

$r = call_handler('edu_m_handle_replace_pdf', ['id' => $materialId, 'upload_id' => $upload('not a pdf at all'), 'filename' => 'bad.pdf']);
check($r['code'] === 400, 'MP-19: PDF でないファイルでは差し替えない');
$kept = Db::one('SELECT page_count, source_name FROM edu_materials WHERE id = ?', [$materialId]);
check((int) $kept['page_count'] === 4 && $kept['source_name'] === '初動報告-表紙つき.pdf' && is_file($dir . '/page-004.jpg')
    && (int) Db::one('SELECT COUNT(*) AS n FROM edu_material_pages WHERE material_id = ?', [$materialId])['n'] === 4,
    'MP-19: 失敗したら元のページをそのまま残す');
check(glob($dir . '.*') === [], 'MP-19: 失敗しても作業用のディレクトリを残さない');

echo "ALL TESTS PASSED\n";
