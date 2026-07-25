<?php declare(strict_types=1); require __DIR__."/../lib/bootstrap.php";

const USER_ROLES = ['viewer', 'operator', 'tenant_admin', 'superadmin'];
const TENANT_ADMIN_ROLES = ['viewer', 'operator', 'tenant_admin'];
const USER_STATUSES = ['active', 'suspended'];

function users_string(array $body, string $key): string
{
    if (!array_key_exists($key, $body) || !is_string($body[$key]) || trim($body[$key]) === '') {
        json_error($key . ' は必須です', 400);
    }
    return trim($body[$key]);
}

function users_optional_string(array $body, string $key): ?string
{
    if (!array_key_exists($key, $body)) {
        return null;
    }
    if (!is_string($body[$key]) || trim($body[$key]) === '') {
        json_error($key . ' が不正です', 400);
    }
    return trim($body[$key]);
}

function users_int(array $body, string $key): int
{
    if (!array_key_exists($key, $body) || !is_int($body[$key]) || $body[$key] < 1) {
        json_error($key . ' が不正です', 400);
    }
    return $body[$key];
}

function users_query_int(string $key): ?int
{
    if (!array_key_exists($key, $_GET) || $_GET[$key] === '') {
        return null;
    }
    $value = filter_var($_GET[$key], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($value === false) {
        json_error($key . ' が不正です', 400);
    }
    return (int) $value;
}

function users_body_optional_int(array $body, string $key): ?int
{
    if (!array_key_exists($key, $body) || $body[$key] === null) {
        return null;
    }
    if (!is_int($body[$key]) || $body[$key] < 1) {
        json_error($key . ' が不正です', 400);
    }
    return $body[$key];
}

function users_is_unique_error(Throwable $e): bool
{
    return $e instanceof PDOException && str_contains($e->getMessage(), 'UNIQUE');
}

function users_role_rank(string $role): int
{
    return ['viewer' => 1, 'operator' => 2, 'tenant_admin' => 3, 'superadmin' => 4][$role] ?? 0;
}

function users_assert_manageable(array $actor, int $id): array
{
    if ($actor['role'] === 'superadmin') {
        $user = Db::one(
            'SELECT id, tenant_id, email, name, role, status, failed_count, locked_until, last_login_at, created_at
             FROM users WHERE id = ?',
            [$id]
        );
    } else {
        $user = Db::one(
            'SELECT id, tenant_id, email, name, role, status, failed_count, locked_until, last_login_at, created_at
             FROM users WHERE id = ? AND tenant_id = ?',
            [$id, (int) $actor['tenant_id']]
        );
    }
    if ($user === null) {
        json_error('ユーザが見つかりません', 404);
    }
    return $user;
}

function users_handle_list(array $actor): never
{
    $tenantId = effective_tenant_id($actor, users_query_int('tenant_id'));
    $users = Db::all(
        'SELECT id, tenant_id, email, name, role, status, failed_count, locked_until, last_login_at, created_at
         FROM users
         WHERE tenant_id = ?
         ORDER BY id',
        [$tenantId]
    );
    json_out(['success' => true, 'users' => $users]);
}

function users_validate_password(string $password): void
{
    if (strlen($password) < 8) {
        json_error('password は8文字以上です', 400);
    }
}

function users_handle_create(array $actor): never
{
    tet2_require_csrf();
    $body = json_body();
    $email = users_string($body, 'email');
    $password = users_string($body, 'password');
    $name = users_string($body, 'name');
    $role = users_string($body, 'role');

    if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        json_error('email が不正です', 400);
    }
    users_validate_password($password);
    if (!in_array($role, USER_ROLES, true)) {
        json_error('role が不正です', 400);
    }

    if ($actor['role'] === 'superadmin') {
        $tenantId = $role === 'superadmin' ? null : users_body_optional_int($body, 'tenant_id');
        if ($role !== 'superadmin' && $tenantId === null) {
            json_error('tenant_id は必須です', 400);
        }
    } else {
        if (!in_array($role, TENANT_ADMIN_ROLES, true)) {
            json_error('role が不正です', 400);
        }
        $tenantId = (int) $actor['tenant_id'];
    }

    $id = Db::insert(
        'INSERT INTO users (tenant_id, email, password_hash, name, role, status) VALUES (?, ?, ?, ?, ?, ?)',
        [$tenantId, $email, password_hash($password, PASSWORD_DEFAULT), $name, $role, 'active']
    );
    audit('user.create', 'user_id=' . $id);
    $user = Db::one(
        'SELECT id, tenant_id, email, name, role, status, failed_count, locked_until, last_login_at, created_at
         FROM users WHERE id = ?',
        [$id]
    );
    json_out(['success' => true, 'user' => $user], 201);
}

function users_handle_update(array $actor): never
{
    tet2_require_csrf();
    $body = json_body();
    $id = users_int($body, 'id');
    $target = users_assert_manageable($actor, $id);
    $name = users_optional_string($body, 'name');
    $role = users_optional_string($body, 'role');
    $status = users_optional_string($body, 'status');
    $password = users_optional_string($body, 'password');

    if ($name === null && $role === null && $status === null && $password === null) {
        json_error('更新項目がありません', 400);
    }
    users_validate_update($actor, $target, $role, $status, $password);
    $passwordHash = $password !== null ? password_hash($password, PASSWORD_DEFAULT) : null;

    Db::run(
        'UPDATE users
         SET name = COALESCE(?, name),
             role = COALESCE(?, role),
             status = COALESCE(?, status),
             password_hash = COALESCE(?, password_hash),
             failed_count = CASE WHEN ? IS NOT NULL THEN 0 ELSE failed_count END,
             locked_until = CASE WHEN ? IS NOT NULL THEN NULL ELSE locked_until END,
             tenant_id = CASE WHEN ? = ? THEN NULL ELSE tenant_id END
         WHERE id = ?',
        [$name, $role, $status, $passwordHash, $passwordHash, $passwordHash, $role, 'superadmin', $id]
    );
    audit('user.update', 'user_id=' . $id);
    $user = users_assert_manageable($actor, $id);
    json_out(['success' => true, 'user' => $user]);
}

function users_validate_update(array $actor, array $target, ?string $role, ?string $status, ?string $password): void
{
    if ($role !== null && !in_array($role, USER_ROLES, true)) {
        json_error('role が不正です', 400);
    }
    if ($actor['role'] !== 'superadmin' && $role !== null && !in_array($role, TENANT_ADMIN_ROLES, true)) {
        json_error('role が不正です', 400);
    }
    if ($status !== null && !in_array($status, USER_STATUSES, true)) {
        json_error('status が不正です', 400);
    }
    if ($password !== null) {
        users_validate_password($password);
    }
    if ((int) $actor['id'] === (int) $target['id']) {
        if ($role !== null && users_role_rank($role) < users_role_rank((string) $actor['role'])) {
            json_error('自分自身の role 降格はできません', 400);
        }
        if ($status === 'suspended') {
            json_error('自分自身の status 停止はできません', 400);
        }
    }
    if ($role !== null && $role !== 'superadmin' && $target['tenant_id'] === null) {
        json_error('tenant_id が無いユーザをこの role には変更できません', 400);
    }
}

function users_handle_delete(array $actor): never
{
    tet2_require_csrf();
    $body = json_body();
    $id = users_int($body, 'id');
    if ((int) $actor['id'] === $id) {
        json_error('自分自身は削除できません', 400);
    }
    users_assert_manageable($actor, $id);
    // campaigns.created_by は users(id) への外部キー。作成者を残したまま DELETE すると
    // FK 制約違反で例外→サーバエラーになる。作成者参照を NULL 化してから削除する(原子的)。
    // キャンペーン自体は残す(履歴保持)。誰が消したかは下の audit_log で追跡可能。
    Db::tx(function () use ($id): void {
        Db::run('UPDATE campaigns SET created_by = NULL WHERE created_by = ?', [$id]);
        Db::run('DELETE FROM users WHERE id = ?', [$id]);
    });
    audit('user.delete', 'user_id=' . $id);
    json_out(['success' => true]);
}

try {
    $actor = require_role('tenant_admin');
    $action = $_GET['action'] ?? '';
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($action === 'list' && $method === 'GET') {
        users_handle_list($actor);
    }
    if ($action === 'create' && $method === 'POST') {
        users_handle_create($actor);
    }
    if ($action === 'update' && $method === 'POST') {
        users_handle_update($actor);
    }
    if ($action === 'delete' && $method === 'POST') {
        users_handle_delete($actor);
    }
    json_error('不正なアクションです', 400);
} catch (Throwable $e) {
    if (users_is_unique_error($e)) {
        json_error('email は既に使用されています', 409);
    }
    error_log($e->getMessage());
    json_error('サーバエラー', 500);
}
