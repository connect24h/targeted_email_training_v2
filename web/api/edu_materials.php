<?php declare(strict_types=1); require __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/OfficeDocumentReader.php';

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
    return $row;
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
    json_error('不正なアクションです', 400);
} catch (Throwable $error) {
    error_log('edu_materials: ' . $error->getMessage());
    json_error('サーバエラー', 500);
}
