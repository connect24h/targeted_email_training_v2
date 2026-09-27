<?php declare(strict_types=1); require __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/OfficeDocumentReader.php';
require_once __DIR__ . '/../lib/EduMedia.php';

/** スライド教材管理。本文はplain textとして保存し、受講画面側でescapeして表示する。 */

function edu_m_query_int(string $key): ?int
{
    if (!isset($_GET[$key]) || $_GET[$key] === '') {
        return null;
    }
    $value = filter_var($_GET[$key], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($value === false) {
        json_error($key . ' が不正です', 400);
    }
    return (int) $value;
}

function edu_m_body_int(array $body, string $key): ?int
{
    if (!array_key_exists($key, $body) || $body[$key] === null) {
        return null;
    }
    if (!is_int($body[$key]) || $body[$key] < 1) {
        json_error($key . ' が不正です', 400);
    }
    return $body[$key];
}

function edu_m_text(array $body, string $key, bool $required = true): ?string
{
    if (!array_key_exists($key, $body)) {
        if ($required) {
            json_error($key . ' は必須です', 400);
        }
        return null;
    }
    if (!is_string($body[$key])) {
        json_error($key . ' が不正です', 400);
    }
    $value = trim($body[$key]);
    if ($required && $value === '') {
        json_error($key . ' は必須です', 400);
    }
    return $value;
}

function edu_m_slides(array $body): ?array
{
    if (!array_key_exists('slides', $body)) {
        return null;
    }
    if (!is_array($body['slides']) || count($body['slides']) < 1 || count($body['slides']) > 50) {
        json_error('slides は1〜50件で指定してください', 400);
    }
    $slides = [];
    foreach ($body['slides'] as $slide) {
        if (!is_array($slide) || !is_string($slide['title'] ?? null) || !is_string($slide['body'] ?? null)) {
            json_error('slides の形式が不正です', 400);
        }
        $title = trim($slide['title']);
        $content = trim($slide['body']);
        if ($title === '' || mb_strlen($title) > 200 || $content === '' || mb_strlen($content) > 10000) {
            json_error('スライドのタイトルまたは本文が不正です', 400);
        }
        $slides[] = ['title' => $title, 'body' => $content];
    }
    return $slides;
}

function edu_m_present(array $row): array
{
    $row['id'] = (int) $row['id'];
    $row['tenant_id'] = $row['tenant_id'] !== null ? (int) $row['tenant_id'] : null;
    $row['is_active'] = (int) $row['is_active'];
    $row['is_shared'] = (int) $row['is_shared'];
    $row['slides'] = json_decode((string) $row['slides'], true) ?: [];
    $row['slide_count'] = count($row['slides']);
    $row['format'] = (string) ($row['format'] ?? 'text_slides');
    $row['page_count'] = (int) ($row['page_count'] ?? 0);
    $row['rev'] = edu_m_rev($row['id'], $row['format']);
    return $row;
}

/**
 * ページ画像の版。画像の URL に付け、差し替えの後にブラウザが古いページ画像を使わないようにする。
 * ページの行は差し替えのたびに作り直され、AUTOINCREMENT の id は前の値に戻らないので、最小の id を版にする。
 */
function edu_m_rev(int $materialId, string $format): string
{
    if ($format !== 'page_images') {
        return '';
    }
    $row = Db::one('SELECT MIN(id) AS rev FROM edu_material_pages WHERE material_id = ?', [$materialId]);
    return (string) ($row['rev'] ?? '');
}

/** ページ画像の教材のページ一覧(画像は page_image で取る)。 */
function edu_m_pages(int $materialId): array
{
    $rows = Db::all(
        'SELECT page_no, width, height, page_text FROM edu_material_pages WHERE material_id = ? ORDER BY page_no',
        [$materialId]
    );
    return array_map(static fn(array $r): array => [
        'page_no' => (int) $r['page_no'], 'width' => (int) $r['width'], 'height' => (int) $r['height'],
        'page_text' => (string) $r['page_text'],
    ], $rows);
}

function edu_m_find(int $id, int $tenantId): ?array
{
    return Db::one(
        'SELECT * FROM edu_materials WHERE id = ? AND (tenant_id = ? OR tenant_id IS NULL)',
        [$id, $tenantId]
    );
}

function edu_m_handle_list(array $actor): never
{
    $tenantId = effective_tenant_id($actor, edu_m_query_int('tenant_id'));
    $rows = Db::all(
        'SELECT * FROM edu_materials
         WHERE (tenant_id = ? OR tenant_id IS NULL) AND is_active = 1
         ORDER BY is_shared DESC, id',
        [$tenantId]
    );
    json_out(['success' => true, 'materials' => array_map('edu_m_present', $rows)]);
}

function edu_m_handle_create(array $actor): never
{
    tet2_require_csrf();
    $body = json_body();
    $tenantId = effective_tenant_id($actor, edu_m_body_int($body, 'tenant_id'));
    $title = edu_m_text($body, 'title');
    $description = edu_m_text($body, 'description', false) ?? '';
    $slides = edu_m_slides($body);
    if ($slides === null || mb_strlen((string) $title) > 200 || mb_strlen($description) > 1000) {
        json_error('教材の入力が不正です', 400);
    }
    $id = Db::insert(
        'INSERT INTO edu_materials (tenant_id, title, description, slides, is_active, is_shared)
         VALUES (?, ?, ?, ?, 1, 0)',
        [$tenantId, $title, $description, json_encode($slides, JSON_UNESCAPED_UNICODE)]
    );
    audit('edu_material.create', 'material_id=' . $id);
    json_out(['success' => true, 'material' => edu_m_present(edu_m_find($id, $tenantId))], 201);
}

function edu_m_handle_update(array $actor): never
{
    tet2_require_csrf();
    $body = json_body();
    $tenantId = effective_tenant_id($actor, edu_m_body_int($body, 'tenant_id'));
    $id = edu_m_body_int($body, 'id');
    if ($id === null) {
        json_error('id が不正です', 400);
    }
    $row = edu_m_find($id, $tenantId);
    if ($row === null) {
        json_error('教材が見つかりません', 404);
    }
    if ($row['tenant_id'] === null && ($actor['role'] ?? '') !== 'superadmin') {
        json_error('共有教材を編集できるのはsuperadminだけです', 403);
    }
    $title = edu_m_text($body, 'title', false);
    $description = edu_m_text($body, 'description', false);
    $slides = edu_m_slides($body);
    if ($title === null && $description === null && $slides === null) {
        json_error('更新項目がありません', 400);
    }
    if (($title !== null && ($title === '' || mb_strlen($title) > 200))
        || ($description !== null && mb_strlen($description) > 1000)) {
        json_error('教材の入力が不正です', 400);
    }
    Db::run(
        "UPDATE edu_materials SET title=COALESCE(?,title), description=COALESCE(?,description),
         slides=COALESCE(?,slides), updated_at=datetime('now','localtime') WHERE id=?",
        [$title, $description, $slides !== null ? json_encode($slides, JSON_UNESCAPED_UNICODE) : null, $id]
    );
    audit('edu_material.update', 'material_id=' . $id);
    json_out(['success' => true, 'material' => edu_m_present(edu_m_find($id, $tenantId))]);
}

function edu_m_handle_get(array $actor): never
{
    $tenantId = effective_tenant_id($actor, edu_m_query_int('tenant_id'));
    $id = edu_m_query_int('id');
    $row = $id !== null ? edu_m_find($id, $tenantId) : null;
    if ($row === null) {
        json_error('教材が見つかりません', 404);
    }
    $material = edu_m_present($row);
    $material['pages'] = $material['format'] === 'page_images' ? edu_m_pages($material['id']) : [];
    json_out(['success' => true, 'material' => $material]);
}

/** PDF の分割アップロードを始める。PHP の受信の上限を超える PDF を4MBずつ受け取るため。 */
function edu_m_handle_upload_begin(array $actor): never
{
    tet2_require_csrf();
    $body = json_body();
    $tenantId = effective_tenant_id($actor, edu_m_body_int($body, 'tenant_id'));
    try {
        $uploadId = EduMedia::beginUpload($tenantId, (int) $actor['id']);
    } catch (RuntimeException $error) {
        json_error($error->getMessage(), 500);
    }
    json_out(['success' => true, 'upload_id' => $uploadId, 'chunk_bytes' => 4_000_000]);
}

function edu_m_handle_upload_chunk(array $actor): never
{
    tet2_require_csrf();
    $body = json_body();
    $tenantId = effective_tenant_id($actor, edu_m_body_int($body, 'tenant_id'));
    $uploadId = is_string($body['upload_id'] ?? null) ? $body['upload_id'] : '';
    $index = $body['index'] ?? null;
    $encoded = is_string($body['data_base64'] ?? null) ? $body['data_base64'] : '';
    if (!is_int($index) || $index < 0 || $encoded === '' || strlen($encoded) > 5_700_000) {
        json_error('分割の指定が不正です', 400);
    }
    $bytes = base64_decode($encoded, true);
    if (!is_string($bytes)) {
        json_error('分割を読み取れません', 400);
    }
    try {
        $total = EduMedia::appendChunk($uploadId, $tenantId, (int) $actor['id'], $index, $bytes);
    } catch (RuntimeException $error) {
        json_error($error->getMessage(), 400);
    }
    json_out(['success' => true, 'received_bytes' => $total]);
}

/** 途中で失敗した分割アップロードを破棄する(画面から呼ぶ)。 */
function edu_m_handle_upload_discard(array $actor): never
{
    tet2_require_csrf();
    $body = json_body();
    $tenantId = effective_tenant_id($actor, edu_m_body_int($body, 'tenant_id'));
    $uploadId = is_string($body['upload_id'] ?? null) ? $body['upload_id'] : '';
    try {
        EduMedia::discardUpload($uploadId, $tenantId, (int) $actor['id']);
    } catch (RuntimeException) {
        // 既に消えている、または自分のアップロードではない。どちらも破棄済みとして扱う
    }
    json_out(['success' => true]);
}

/** アップロードした PDF をページ画像に変換し、ページ画像の教材として保存する。 */
function edu_m_handle_import_pdf(array $actor): never
{
    tet2_require_csrf();
    $body = json_body();
    $tenantId = effective_tenant_id($actor, edu_m_body_int($body, 'tenant_id'));
    $uploadId = is_string($body['upload_id'] ?? null) ? $body['upload_id'] : '';
    $filename = is_string($body['filename'] ?? null) ? trim($body['filename']) : '';
    $title = edu_m_text($body, 'title', false);
    $description = edu_m_text($body, 'description', false) ?? '';
    if ($filename === '' || !preg_match('/\.pdf$/i', $filename)) {
        json_error('PDF ファイルを指定してください', 400);
    }
    $title = $title !== null && $title !== ''
        ? $title : mb_substr((string) preg_replace('/\.pdf$/i', '', basename($filename)), 0, 200);
    if ($title === '' || mb_strlen($title) > 200 || mb_strlen($description) > 1000) {
        json_error('教材の入力が不正です', 400);
    }
    try {
        $pdfPath = EduMedia::uploadPath($uploadId, $tenantId, (int) $actor['id']);
    } catch (RuntimeException $error) {
        json_error($error->getMessage(), 400);
    }
    if (function_exists('set_time_limit')) {
        @set_time_limit(180);   // 34ページで約15秒。大きな PDF でも途中で打ち切らない
    }
    // 先に教材の行を作って id を決め、その id の置き場へ変換する。行は変換が終わるまで非表示(is_active=0)にし、
    // 途中で処理が止まっても、ページのない教材が一覧や配信に出ないようにする。失敗したら行を消す
    $id = Db::insert(
        "INSERT INTO edu_materials (tenant_id, title, description, slides, is_active, is_shared, format, page_count, source_name)
         VALUES (?, ?, ?, '[]', 0, 0, 'page_images', 0, ?)",
        [$tenantId, $title, $description, mb_substr(basename($filename), 0, 200)]
    );
    try {
        $pages = EduMedia::convertPdf($pdfPath, EduMedia::materialDir($tenantId, $id));
    } catch (RuntimeException $error) {
        Db::run('DELETE FROM edu_materials WHERE id = ?', [$id]);
        EduMedia::discardUpload($uploadId, $tenantId, (int) $actor['id']);
        error_log('edu_materials.import_pdf: ' . $error->getMessage() . ' ' . EduMedia::$lastError);
        json_error($error->getMessage(), 400);
    }
    Db::tx(static function () use ($id, $pages): void {
        foreach ($pages as $p) {
            Db::run(
                'INSERT INTO edu_material_pages (material_id, page_no, image_name, page_text, width, height)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [$id, $p['page_no'], $p['image_name'], $p['page_text'], $p['width'], $p['height']]
            );
        }
        // 文字だけで表示する経路(旧い受講画面や取込処理)のため、ページの文字をスライドとしても持つ
        $slides = array_map(static fn(array $p): array => [
            'title' => 'ページ ' . $p['page_no'],
            'body' => $p['page_text'] !== '' ? mb_substr($p['page_text'], 0, 10000) : '（画像のページ）',
        ], $pages);
        Db::run(
            "UPDATE edu_materials SET page_count = ?, slides = ?, is_active = 1, updated_at = datetime('now','localtime') WHERE id = ?",
            [count($pages), json_encode($slides, JSON_UNESCAPED_UNICODE), $id]
        );
    });
    EduMedia::discardUpload($uploadId, $tenantId, (int) $actor['id']);
    audit('edu_material.import_pdf', 'material_id=' . $id . ' pages=' . count($pages));
    $material = edu_m_present(edu_m_find($id, $tenantId));
    $material['pages'] = edu_m_pages($id);
    json_out(['success' => true, 'material' => $material], 201);
}

/**
 * ページ画像の教材の PDF を差し替える(表紙を足した改訂版など)。教材の id は変えないので、配信と受講のリンクはそのまま使える。
 * 新しいページは別の場所へ変換し、DB を入れ替えてから置き場を入れ替える。変換に失敗したら元のページをそのまま残す。
 */
function edu_m_handle_replace_pdf(array $actor): never
{
    tet2_require_csrf();
    $body = json_body();
    $tenantId = effective_tenant_id($actor, edu_m_body_int($body, 'tenant_id'));
    $id = edu_m_body_int($body, 'id');
    $uploadId = is_string($body['upload_id'] ?? null) ? $body['upload_id'] : '';
    $filename = is_string($body['filename'] ?? null) ? trim($body['filename']) : '';
    if ($id === null) {
        json_error('id が不正です', 400);
    }
    if ($filename === '' || !preg_match('/\.pdf$/i', $filename)) {
        json_error('PDF ファイルを指定してください', 400);
    }
    $row = edu_m_find($id, $tenantId);
    if ($row === null) {
        json_error('教材が見つかりません', 404);
    }
    if ($row['tenant_id'] === null && ($actor['role'] ?? '') !== 'superadmin') {
        json_error('共有教材を編集できるのはsuperadminだけです', 403);
    }
    if (($row['format'] ?? '') !== 'page_images') {
        json_error('PDF で差し替えられるのは PDF の教材だけです', 409);
    }
    try {
        $pdfPath = EduMedia::uploadPath($uploadId, $tenantId, (int) $actor['id']);
    } catch (RuntimeException $error) {
        json_error($error->getMessage(), 400);
    }
    if (function_exists('set_time_limit')) {
        @set_time_limit(180);
    }
    $owner = $row['tenant_id'] !== null ? (int) $row['tenant_id'] : null;
    $dir = EduMedia::materialDir($owner, $id);
    $next = $dir . '.new-' . bin2hex(random_bytes(4));
    try {
        $pages = EduMedia::convertPdf($pdfPath, $next);
    } catch (RuntimeException $error) {
        EduMedia::discardUpload($uploadId, $tenantId, (int) $actor['id']);
        error_log('edu_materials.replace_pdf: ' . $error->getMessage() . ' ' . EduMedia::$lastError);
        json_error($error->getMessage(), 400);
    }
    try {
        edu_m_store_replaced_pages($id, $pages, $filename);
    } catch (Throwable $error) {
        EduMedia::discardDir($next);
        throw $error;
    }
    EduMedia::swapDir($dir, $next);
    EduMedia::discardUpload($uploadId, $tenantId, (int) $actor['id']);
    audit('edu_material.replace_pdf', 'material_id=' . $id . ' pages=' . count($pages));
    $material = edu_m_present(edu_m_find($id, $tenantId));
    $material['pages'] = edu_m_pages($id);
    json_out(['success' => true, 'material' => $material]);
}

/** 差し替えたページを DB に入れる(ページの行を作り直し、教材のページ数と文字を新しくする)。 */
function edu_m_store_replaced_pages(int $id, array $pages, string $filename): void
{
    Db::tx(static function () use ($id, $pages, $filename): void {
        Db::run('DELETE FROM edu_material_pages WHERE material_id = ?', [$id]);
        foreach ($pages as $p) {
            Db::run(
                'INSERT INTO edu_material_pages (material_id, page_no, image_name, page_text, width, height)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [$id, $p['page_no'], $p['image_name'], $p['page_text'], $p['width'], $p['height']]
            );
        }
        $slides = array_map(static fn(array $p): array => [
            'title' => 'ページ ' . $p['page_no'],
            'body' => $p['page_text'] !== '' ? mb_substr($p['page_text'], 0, 10000) : '（画像のページ）',
        ], $pages);
        Db::run(
            "UPDATE edu_materials SET page_count = ?, slides = ?, source_name = ?, updated_at = datetime('now','localtime') WHERE id = ?",
            [count($pages), json_encode($slides, JSON_UNESCAPED_UNICODE), mb_substr(basename($filename), 0, 200), $id]
        );
    });
}

/**
 * 管理画面でページ画像を表示するときのファイルの場所。閲覧できる教材(自組織か共有)のページだけを返す。
 *
 * @return array{path:string,mime:string}
 */
function edu_m_page_image_file(array $actor, int $materialId, int $pageNo): array
{
    $tenantId = effective_tenant_id($actor, edu_m_query_int('tenant_id'));
    $row = edu_m_find($materialId, $tenantId);
    if ($row === null || ($row['format'] ?? '') !== 'page_images') {
        json_error('教材が見つかりません', 404);
    }
    $page = Db::one(
        'SELECT image_name FROM edu_material_pages WHERE material_id = ? AND page_no = ?',
        [$materialId, $pageNo]
    );
    if ($page === null || !EduMedia::safeName((string) $page['image_name'])) {
        json_error('ページが見つかりません', 404);
    }
    $owner = $row['tenant_id'] !== null ? (int) $row['tenant_id'] : null;
    $path = EduMedia::materialDir($owner, $materialId) . '/' . $page['image_name'];
    if (!is_file($path)) {
        json_error('ページの画像がありません', 404);
    }
    return ['path' => $path, 'mime' => EduMedia::mimeOf((string) $page['image_name'])];
}

/** 画像を送り出す(非公開の画像なので、ブラウザ以外に保存させない)。 */
function edu_m_send_file(array $file): never
{
    header('Content-Type: ' . $file['mime']);
    header('Content-Length: ' . filesize($file['path']));
    header('Cache-Control: private, max-age=3600');
    header('X-Content-Type-Options: nosniff');
    readfile($file['path']);
    exit;
}

function edu_m_handle_import_pptx(array $actor): never
{
    tet2_require_csrf();
    $body = json_body();
    effective_tenant_id($actor, edu_m_body_int($body, 'tenant_id'));
    $filename = is_string($body['filename'] ?? null) ? trim($body['filename']) : '';
    $encoded = is_string($body['file_base64'] ?? null) ? $body['file_base64'] : '';
    if ($filename === '' || !preg_match('/\.pptx$/i', $filename) || $encoded === '') {
        json_error('PowerPoint（.pptx）ファイルを指定してください', 400);
    }
    if (strlen($encoded) > 7_000_000) {
        json_error('PowerPointファイルは5MB以内にしてください', 400);
    }
    $bytes = base64_decode($encoded, true);
    if (!is_string($bytes)) {
        json_error('PowerPointファイルを読み取れません', 400);
    }
    try {
        $slides = OfficeDocumentReader::readPowerPoint($bytes);
    } catch (RuntimeException $error) {
        json_error($error->getMessage(), 400);
    }
    $suggestedTitle = mb_substr((string) preg_replace('/\.pptx$/i', '', basename($filename)), 0, 200);
    audit('edu_material.import_pptx', 'slides=' . count($slides));
    json_out(['success' => true, 'title' => $suggestedTitle, 'slides' => $slides]);
}

try {
    $action = $_GET['action'] ?? '';
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $actor = require_role($method === 'GET' ? 'viewer' : 'operator');
    if ($action === 'list' && $method === 'GET') {
        edu_m_handle_list($actor);
    }
    if ($action === 'create' && $method === 'POST') {
        edu_m_handle_create($actor);
    }
    if ($action === 'update' && $method === 'POST') {
        edu_m_handle_update($actor);
    }
    if ($action === 'import_pptx' && $method === 'POST') {
        edu_m_handle_import_pptx($actor);
    }
    if ($action === 'get' && $method === 'GET') {
        edu_m_handle_get($actor);
    }
    if ($action === 'upload_begin' && $method === 'POST') {
        edu_m_handle_upload_begin($actor);
    }
    if ($action === 'upload_chunk' && $method === 'POST') {
        edu_m_handle_upload_chunk($actor);
    }
    if ($action === 'upload_discard' && $method === 'POST') {
        edu_m_handle_upload_discard($actor);
    }
    if ($action === 'import_pdf' && $method === 'POST') {
        edu_m_handle_import_pdf($actor);
    }
    if ($action === 'replace_pdf' && $method === 'POST') {
        edu_m_handle_replace_pdf($actor);
    }
    if ($action === 'page_image' && $method === 'GET') {
        $materialId = edu_m_query_int('id');
        $pageNo = edu_m_query_int('page');
        if ($materialId === null || $pageNo === null) {
            json_error('id と page を指定してください', 400);
        }
        edu_m_send_file(edu_m_page_image_file($actor, $materialId, $pageNo));
    }
    json_error('不正なアクションです', 400);
} catch (Throwable $error) {
    error_log('edu_materials: ' . $error->getMessage());
    json_error('サーバエラー', 500);
}
