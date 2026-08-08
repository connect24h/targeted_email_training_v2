<?php declare(strict_types=1); require __DIR__."/../lib/bootstrap.php";

const GROUP_KINDS = ['department', 'custom'];

function groups_string(array $body, string $key): string
{
    if (!array_key_exists($key, $body) || !is_string($body[$key]) || trim($body[$key]) === '') {
        json_error($key . ' は必須です', 400);
    }
    return trim($body[$key]);
}

function groups_optional_string(array $body, string $key): ?string
{
    if (!array_key_exists($key, $body)) {
        return null;
    }
    if (!is_string($body[$key]) || trim($body[$key]) === '') {
        json_error($key . ' が不正です', 400);
    }
    return trim($body[$key]);
}

function groups_int(array $body, string $key): int
{
    if (!array_key_exists($key, $body) || !is_int($body[$key]) || $body[$key] < 1) {
        json_error($key . ' が不正です', 400);
    }
    return $body[$key];
}

function groups_query_int(string $key): ?int
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

function groups_body_optional_int(array $body, string $key): ?int
{
    if (!array_key_exists($key, $body) || $body[$key] === null) {
        return null;
    }
    if (!is_int($body[$key]) || $body[$key] < 1) {
        json_error($key . ' が不正です', 400);
    }
    return $body[$key];
}

function groups_int_array(array $body, string $key): array
{
    if (!array_key_exists($key, $body) || !is_array($body[$key])) {
        json_error($key . ' は必須です', 400);
    }
    $ids = [];
    foreach ($body[$key] as $id) {
        if (!is_int($id) || $id < 1) {
            json_error($key . ' が不正です', 400);
        }
        $ids[] = $id;
    }
    return array_values(array_unique($ids));
}

function groups_is_unique_error(Throwable $e): bool
{
    return $e instanceof PDOException && str_contains($e->getMessage(), 'UNIQUE');
}

function groups_assert_owned(int $groupId, int $tenantId): array
{
    $group = Db::one('SELECT * FROM groups WHERE id = ? AND tenant_id = ?', [$groupId, $tenantId]);
    if ($group === null) {
        json_error('グループが見つかりません', 404);
    }
    return $group;
}

function groups_assert_targets_owned(array $targetIds, int $tenantId): void
{
    foreach ($targetIds as $targetId) {
        assert_target_owned($targetId, $tenantId);
    }
}

function groups_validate_kind(string $kind): void
{
    if (!in_array($kind, GROUP_KINDS, true)) {
        json_error('kind が不正です', 400);
    }
}

function groups_handle_list(array $actor): never
{
    $tenantId = effective_tenant_id($actor, groups_query_int('tenant_id'));
    // target_count は現役(アーカイブ=退職を除く)メンバー数。空グループの視認用。
    $groups = Db::all(
        "SELECT g.id, g.tenant_id, g.name, g.kind, COUNT(DISTINCT t.id) AS target_count
         FROM groups g
         LEFT JOIN target_group tg ON tg.group_id = g.id
         LEFT JOIN targets t ON t.id = tg.target_id AND t.tenant_id = ? AND t.status != 'archived'
         WHERE g.tenant_id = ?
         GROUP BY g.id, g.tenant_id, g.name, g.kind
         ORDER BY g.id",
        [$tenantId, $tenantId]
    );
    json_out(['success' => true, 'groups' => $groups]);
}

function groups_handle_create(array $actor): never
{
    tet2_require_csrf();
    $body = json_body();
    $tenantId = effective_tenant_id($actor, groups_body_optional_int($body, 'tenant_id'));
    $name = groups_string($body, 'name');
    $kind = groups_optional_string($body, 'kind') ?? 'custom';
    groups_validate_kind($kind);

    $id = Db::insert(
        'INSERT INTO groups (tenant_id, name, kind) VALUES (?, ?, ?)',
        [$tenantId, $name, $kind]
    );
    audit('group.create', 'group_id=' . $id);
    $group = groups_assert_owned($id, $tenantId);
    json_out(['success' => true, 'group' => $group], 201);
}

function groups_handle_update(array $actor): never
{
    tet2_require_csrf();
    $body = json_body();
    $tenantId = effective_tenant_id($actor, groups_body_optional_int($body, 'tenant_id'));
    $id = groups_int($body, 'id');
    groups_assert_owned($id, $tenantId);
    $name = groups_optional_string($body, 'name');
    $kind = groups_optional_string($body, 'kind');

    if ($name === null && $kind === null) {
        json_error('更新項目がありません', 400);
    }
    if ($kind !== null) {
        groups_validate_kind($kind);
    }

    Db::run(
        'UPDATE groups SET name = COALESCE(?, name), kind = COALESCE(?, kind) WHERE id = ? AND tenant_id = ?',
        [$name, $kind, $id, $tenantId]
    );
    audit('group.update', 'group_id=' . $id);
    $group = groups_assert_owned($id, $tenantId);
    json_out(['success' => true, 'group' => $group]);
}

function groups_handle_delete(array $actor): never
{
    tet2_require_csrf();
    $body = json_body();
    $tenantId = effective_tenant_id($actor, groups_body_optional_int($body, 'tenant_id'));
    $id = groups_int($body, 'id');
    groups_assert_owned($id, $tenantId);

    Db::run('DELETE FROM groups WHERE id = ? AND tenant_id = ?', [$id, $tenantId]);
    audit('group.delete', 'group_id=' . $id);
    json_out(['success' => true]);
}

function groups_handle_add_targets(array $actor): never
{
    tet2_require_csrf();
    $body = json_body();
    $tenantId = effective_tenant_id($actor, groups_body_optional_int($body, 'tenant_id'));
    $groupId = groups_int($body, 'group_id');
    $targetIds = groups_int_array($body, 'target_ids');
    groups_assert_owned($groupId, $tenantId);
    groups_assert_targets_owned($targetIds, $tenantId);

    Db::tx(function () use ($groupId, $targetIds, $tenantId): void {
        foreach ($targetIds as $targetId) {
            Db::run(
                'INSERT OR IGNORE INTO target_group (target_id, group_id)
                 SELECT ?, ?
                 WHERE EXISTS (SELECT 1 FROM targets WHERE id = ? AND tenant_id = ?)
                   AND EXISTS (SELECT 1 FROM groups WHERE id = ? AND tenant_id = ?)',
                [$targetId, $groupId, $targetId, $tenantId, $groupId, $tenantId]
            );
        }
    });
    audit('group.add_targets', 'group_id=' . $groupId);
    json_out(['success' => true]);
}

function groups_handle_remove_targets(array $actor): never
{
    tet2_require_csrf();
    $body = json_body();
    $tenantId = effective_tenant_id($actor, groups_body_optional_int($body, 'tenant_id'));
    $groupId = groups_int($body, 'group_id');
    $targetIds = groups_int_array($body, 'target_ids');
    groups_assert_owned($groupId, $tenantId);
    groups_assert_targets_owned($targetIds, $tenantId);

    Db::tx(function () use ($groupId, $targetIds, $tenantId): void {
        foreach ($targetIds as $targetId) {
            Db::run(
                'DELETE FROM target_group
                 WHERE target_id IN (SELECT id FROM targets WHERE id = ? AND tenant_id = ?)
                   AND group_id IN (SELECT id FROM groups WHERE id = ? AND tenant_id = ?)',
                [$targetId, $tenantId, $groupId, $tenantId]
            );
        }
    });
    audit('group.remove_targets', 'group_id=' . $groupId);
    json_out(['success' => true]);
}

/** グループの現在のメンバー(対象者)一覧を返す。メンバー管理UI用。 */
function groups_handle_members(array $actor): never
{
    $tenantId = effective_tenant_id($actor, groups_query_int('tenant_id'));
    $groupId = groups_query_int('group_id');
    if ($groupId === null) {
        json_error('group_id が不正です', 400);
    }
    groups_assert_owned($groupId, $tenantId);
    $members = Db::all(
        "SELECT t.id, t.email, t.name, t.company, t.department
         FROM target_group tg
         JOIN targets t ON t.id = tg.target_id
         WHERE tg.group_id = ? AND t.tenant_id = ? AND t.status != 'archived'
         ORDER BY t.tenant_no",
        [$groupId, $tenantId]
    );
    json_out(['success' => true, 'members' => $members]);
}

try {
    $action = $_GET['action'] ?? '';
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $actor = require_role($method === 'GET' ? 'viewer' : 'operator');

    if ($action === 'list' && $method === 'GET') {
        groups_handle_list($actor);
    }
    if ($action === 'create' && $method === 'POST') {
        groups_handle_create($actor);
    }
    if ($action === 'update' && $method === 'POST') {
        groups_handle_update($actor);
    }
    if ($action === 'delete' && $method === 'POST') {
        groups_handle_delete($actor);
    }
    if ($action === 'add_targets' && $method === 'POST') {
        groups_handle_add_targets($actor);
    }
    if ($action === 'members' && $method === 'GET') {
        groups_handle_members($actor);
    }
    if ($action === 'remove_targets' && $method === 'POST') {
        groups_handle_remove_targets($actor);
    }
    json_error('不正なアクションです', 400);
} catch (Throwable $e) {
    if (groups_is_unique_error($e)) {
        json_error('name は既に使用されています', 409);
    }
    error_log($e->getMessage());
    json_error('サーバエラー', 500);
}
