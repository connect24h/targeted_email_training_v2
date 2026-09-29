<?php declare(strict_types=1); require __DIR__."/../lib/bootstrap.php";
require_once __DIR__ . '/../lib/EduTags.php';

/**
 * 分野のタグ(edu_tags)管理 API(G09)。edu_categories.php と同じ流儀と権限:
 * - 閲覧は viewer 以上、変更は operator 以上。CSRF、audit。
 * - 共有のタグ(tenant_id NULL)の作成と変更はシステム管理者(superadmin)だけ。テナントのタグは所有する組織だけ。
 * - 2階層まで。子のタグの親は1段目のタグに限る。テナントの子は共有か自組織の親の下、共有の子は共有の親の下だけ。
 * - 削除は、子のタグがあるか設問に付いていれば拒む(集計が黙って変わらないように)。
 */

function edu_tag_query_int(string $key): ?int
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

function edu_tag_body_optional_int(array $body, string $key): ?int
{
    if (!array_key_exists($key, $body) || $body[$key] === null) {
        return null;
    }
    if (!is_int($body[$key]) || $body[$key] < 1) {
        json_error($key . ' が不正です', 400);
    }
    return $body[$key];
}

function edu_tag_id_body(array $body): int
{
    if (!array_key_exists('id', $body) || !is_int($body['id']) || $body['id'] < 1) {
        json_error('id が不正です', 400);
    }
    return $body['id'];
}

function edu_tag_name(array $body): string
{
    $name = is_string($body['name'] ?? null) ? EduTags::normalizeName($body['name']) : '';
    if ($name === '') {
        json_error('name は必須です', 400);
    }
    if (mb_strlen($name) > EduTags::MAX_NAME) {
        json_error('タグの名前は' . EduTags::MAX_NAME . '文字以内にしてください', 400);
    }
    return $name;
}

/** 説明。キーがなければ変更なし(false)、空なら NULL(消す)。 */
function edu_tag_description(array $body): string|null|false
{
    if (!array_key_exists('description', $body)) {
        return false;
    }
    if ($body['description'] !== null && !is_string($body['description'])) {
        json_error('description が不正です', 400);
    }
    $text = trim((string) $body['description']);
    if (mb_strlen($text) > 500) {
        json_error('説明は500文字以内にしてください', 400);
    }
    return $text === '' ? null : $text;
}

function edu_tag_sort_order(array $body): ?int
{
    if (!array_key_exists('sort_order', $body) || $body['sort_order'] === null) {
        return null;
    }
    if (!is_int($body['sort_order']) || $body['sort_order'] < 0) {
        json_error('sort_order が不正です', 400);
    }
    return $body['sort_order'];
}

function edu_tag_assert_visible(int $id, int $tenantId): array
{
    $row = Db::one('SELECT * FROM edu_tags WHERE id = ? AND (tenant_id = ? OR tenant_id IS NULL)', [$id, $tenantId]);
    if ($row === null) {
        json_error('タグが見つかりません', 404);
    }
    return $row;
}

/** 編集可否: 共有のタグは superadmin だけ、テナントのタグは所有する組織だけ(edu_cat_assert_editable と同じ)。 */
function edu_tag_assert_editable(array $actor, int $id, int $tenantId): array
{
    $row = edu_tag_assert_visible($id, $tenantId);
    if ($row['tenant_id'] === null) {
        if ($actor['role'] !== 'superadmin') {
            json_error('共有のタグは編集できません(システム管理者だけが編集できます)', 403);
        }
        return $row;
    }
    if ((int) $row['tenant_id'] !== $tenantId) {
        json_error('他組織のデータにはアクセスできません', 403);
    }
    return $row;
}

/**
 * 親に選べるか: 見える1段目のタグで、持ち主の組み合わせが正しい(共有の子は共有の親、テナントの子は共有か同じ組織の親)。
 * $ownerTenant は子のタグの持ち主(NULL は共有)。
 */
function edu_tag_assert_parent(int $parentId, ?int $ownerTenant, int $tenantId, ?int $selfId = null): void
{
    if ($selfId !== null && $parentId === $selfId) {
        json_error('自分自身を親にはできません', 400);
    }
    $parent = Db::one('SELECT * FROM edu_tags WHERE id = ? AND (tenant_id = ? OR tenant_id IS NULL)', [$parentId, $tenantId]);
    if ($parent === null) {
        json_error('親のタグが見つかりません', 404);
    }
    if ($parent['parent_id'] !== null) {
        json_error('タグは2階層までです(子のタグの下には作れません)', 400);
    }
    $parentOwner = $parent['tenant_id'] !== null ? (int) $parent['tenant_id'] : null;
    if ($parentOwner !== null && $parentOwner !== $ownerTenant) {
        json_error('共有のタグは、共有のタグの下にだけ作れます', 400);
    }
}

function edu_tag_is_unique_error(Throwable $e): bool
{
    return $e instanceof PDOException && str_contains($e->getMessage(), 'UNIQUE');
}

/** 書き込み。同じ親の下の同じ名前(一意の索引)は 409 にする。 */
function edu_tag_write(callable $write): int
{
    try {
        return (int) $write();
    } catch (PDOException $e) {
        if (edu_tag_is_unique_error($e)) {
            json_error('同じ親の下に同じ名前のタグがあります', 409);
        }
        throw $e;
    }
}

function edu_tag_handle_list(array $actor): never
{
    // superadmin が tenant_id 未指定なら共有スコープ(番兵0)。共有のタグだけを一覧する。
    $tenantId = edu_effective_tenant_id($actor, edu_tag_query_int('tenant_id'));
    json_out(['success' => true, 'tags' => EduTags::visible($tenantId)]);
}

function edu_tag_handle_create(array $actor): never
{
    tet2_require_csrf();
    $body = json_body();
    $tenantId = edu_effective_tenant_id($actor, edu_tag_body_optional_int($body, 'tenant_id'));
    $name = edu_tag_name($body);
    $description = edu_tag_description($body);
    $sortOrder = edu_tag_sort_order($body) ?? 0;
    // 共有のタグ(tenant_id NULL)を作れるのは superadmin だけ。組織を選んでいない superadmin は共有に作る。
    $createShared = $actor['role'] === 'superadmin' && (!empty($body['shared']) || $tenantId === 0);
    $owner = $createShared ? null : $tenantId;
    $parentId = edu_tag_body_optional_int($body, 'parent_id');
    if ($parentId !== null) {
        edu_tag_assert_parent($parentId, $owner, $tenantId);
    }
    $id = edu_tag_write(static fn(): int => Db::insert(
        'INSERT INTO edu_tags (tenant_id, parent_id, name, description, sort_order) VALUES (?, ?, ?, ?, ?)',
        [$owner, $parentId, $name, $description === false ? null : $description, $sortOrder]
    ));
    audit('edu_tag.create', 'tag_id=' . $id . ($createShared ? ',shared' : ''));
    json_out(['success' => true, 'tag' => EduTags::find($id, $tenantId)], 201);
}

function edu_tag_handle_update(array $actor): never
{
    tet2_require_csrf();
    $body = json_body();
    $tenantId = edu_effective_tenant_id($actor, edu_tag_body_optional_int($body, 'tenant_id'));
    $id = edu_tag_id_body($body);
    $row = edu_tag_assert_editable($actor, $id, $tenantId);

    $name = array_key_exists('name', $body) ? edu_tag_name($body) : null;
    $description = edu_tag_description($body);
    $sortOrder = edu_tag_sort_order($body);
    $moveParent = array_key_exists('parent_id', $body);
    $parentId = $moveParent ? edu_tag_body_optional_int($body, 'parent_id') : null;
    if ($name === null && $description === false && $sortOrder === null && !$moveParent) {
        json_error('更新項目がありません', 400);
    }
    if ($moveParent && $parentId !== null) {
        if (Db::one('SELECT 1 FROM edu_tags WHERE parent_id = ? LIMIT 1', [$id]) !== null) {
            json_error('子のタグがあるタグは、ほかのタグの下に移せません', 400);
        }
        edu_tag_assert_parent($parentId, $row['tenant_id'] !== null ? (int) $row['tenant_id'] : null, $tenantId, $id);
    }

    // 権限は assert_editable で担保済み。id 単独で更新(共有=tenant_id NULL でも動く)。
    edu_tag_write(static fn(): int => Db::run(
        'UPDATE edu_tags
         SET name = COALESCE(?, name),
             description = CASE WHEN ? THEN ? ELSE description END,
             sort_order = COALESCE(?, sort_order),
             parent_id = CASE WHEN ? THEN ? ELSE parent_id END
         WHERE id = ?',
        [$name, $description !== false ? 1 : 0, $description === false ? null : $description, $sortOrder,
         $moveParent ? 1 : 0, $parentId, $id]
    ));
    audit('edu_tag.update', 'tag_id=' . $id);
    json_out(['success' => true, 'tag' => EduTags::find($id, $tenantId)]);
}

function edu_tag_handle_delete(array $actor): never
{
    tet2_require_csrf();
    $body = json_body();
    $tenantId = edu_effective_tenant_id($actor, edu_tag_body_optional_int($body, 'tenant_id'));
    $id = edu_tag_id_body($body);
    edu_tag_assert_editable($actor, $id, $tenantId);

    // 子のタグ(ほかの組織の子も含む)か、設問(ほかの組織の設問も含む)に付いていれば消さない
    if (Db::one('SELECT 1 FROM edu_tags WHERE parent_id = ? LIMIT 1', [$id]) !== null) {
        json_error('子のタグがあるタグは削除できません(先に子のタグを削除するか、ほかの親へ移してください)', 409);
    }
    if (Db::one('SELECT 1 FROM edu_question_tags WHERE tag_id = ? LIMIT 1', [$id]) !== null) {
        json_error('設問に付いているタグは削除できません(先に設問からこのタグを外してください)', 409);
    }
    Db::run('DELETE FROM edu_tags WHERE id = ?', [$id]);
    audit('edu_tag.delete', 'tag_id=' . $id);
    json_out(['success' => true]);
}

try {
    $action = $_GET['action'] ?? '';
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $actor = require_role($method === 'GET' ? 'viewer' : 'operator');

    if ($action === 'list' && $method === 'GET') {
        edu_tag_handle_list($actor);
    }
    if ($action === 'create' && $method === 'POST') {
        edu_tag_handle_create($actor);
    }
    if ($action === 'update' && $method === 'POST') {
        edu_tag_handle_update($actor);
    }
    if ($action === 'delete' && $method === 'POST') {
        edu_tag_handle_delete($actor);
    }
    json_error('不正なアクションです', 400);
} catch (Throwable $e) {
    error_log($e->getMessage());
    json_error('サーバエラー', 500);
}
