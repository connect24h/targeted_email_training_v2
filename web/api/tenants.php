<?php declare(strict_types=1); require __DIR__."/../lib/bootstrap.php";

function tenants_string(array $body, string $key): string
{
    if (!array_key_exists($key, $body) || !is_string($body[$key]) || trim($body[$key]) === '') {
        json_error($key . ' は必須です', 400);
    }
    return trim($body[$key]);
}

function tenants_optional_string(array $body, string $key): ?string
{
    if (!array_key_exists($key, $body)) {
        return null;
    }
    if (!is_string($body[$key]) || trim($body[$key]) === '') {
        json_error($key . ' が不正です', 400);
    }
    return trim($body[$key]);
}

function tenants_int(array $body, string $key): int
{
    if (!array_key_exists($key, $body) || !is_int($body[$key]) || $body[$key] < 1) {
        json_error($key . ' が不正です', 400);
    }
    return $body[$key];
}

function tenants_is_unique_error(Throwable $e): bool
{
    return $e instanceof PDOException && str_contains($e->getMessage(), 'UNIQUE');
}

function tenants_handle_list(): never
{
    $rows = Db::all(
        'SELECT t.id, t.name, t.slug, t.data_dir, t.status, t.created_at,
                COUNT(DISTINCT u.id) AS user_count,
                COUNT(DISTINCT tg.id) AS target_count
         FROM tenants t
         LEFT JOIN users u ON u.tenant_id = t.id
         LEFT JOIN targets tg ON tg.tenant_id = t.id
         GROUP BY t.id, t.name, t.slug, t.data_dir, t.status, t.created_at
         ORDER BY t.id',
        []
    );
    json_out(['success' => true, 'tenants' => $rows]);
}

function tenants_handle_create(): never
{
    tet2_require_csrf();
    $body = json_body();
    $name = tenants_string($body, 'name');
    $slug = tenants_string($body, 'slug');
    if (preg_match('/^[a-z0-9][a-z0-9-]{1,30}$/', $slug) !== 1) {
        json_error('slug が不正です', 400);
    }

    $id = Db::insert(
        'INSERT INTO tenants (name, slug, data_dir, status) VALUES (?, ?, ?, ?)',
        [$name, $slug, '/opt/training/tet2-data/' . $slug, 'active']
    );
    audit('tenant.create', 'tenant_id=' . $id);
    $tenant = Db::one('SELECT * FROM tenants WHERE id = ?', [$id]);
    json_out(['success' => true, 'tenant' => $tenant], 201);
}

function tenants_handle_update(): never
{
    tet2_require_csrf();
    $body = json_body();
    $id = tenants_int($body, 'id');
    $name = tenants_optional_string($body, 'name');
    $status = tenants_optional_string($body, 'status');
    if ($name === null && $status === null) {
        json_error('更新項目がありません', 400);
    }
    if ($status !== null && !in_array($status, ['active', 'suspended'], true)) {
        json_error('status が不正です', 400);
    }
    if (Db::one('SELECT id FROM tenants WHERE id = ?', [$id]) === null) {
        json_error('テナントが見つかりません', 404);
    }

    Db::run('UPDATE tenants SET name = COALESCE(?, name), status = COALESCE(?, status) WHERE id = ?', [$name, $status, $id]);
    audit('tenant.update', 'tenant_id=' . $id);
    $tenant = Db::one('SELECT * FROM tenants WHERE id = ?', [$id]);
    json_out(['success' => true, 'tenant' => $tenant]);
}

try {
    require_role('superadmin');
    $action = $_GET['action'] ?? '';
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($action === 'list' && $method === 'GET') {
        tenants_handle_list();
    }
    if ($action === 'create' && $method === 'POST') {
        tenants_handle_create();
    }
    if ($action === 'update' && $method === 'POST') {
        tenants_handle_update();
    }
    json_error('不正なアクションです', 400);
} catch (Throwable $e) {
    if (tenants_is_unique_error($e)) {
        json_error('slug は既に使用されています', 409);
    }
    error_log($e->getMessage());
    json_error('サーバエラー', 500);
}
