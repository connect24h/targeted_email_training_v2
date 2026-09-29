<?php declare(strict_types=1); require __DIR__."/../lib/bootstrap.php";

/**
 * 教材カテゴリ(edu_categories)管理 API。
 * templates.php と同じ流儀: action ディスパッチ / CSRF / require_role / effective_tenant_id / audit。
 * テナント分離: 全クエリで tenant_id を機械付与し、他テナントの行にはアクセスさせない。
 */

function edu_cat_query_int(string $key): ?int
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

function edu_cat_body_optional_int(array $body, string $key): ?int
{
    if (!array_key_exists($key, $body) || $body[$key] === null) {
        return null;
    }
    if (!is_int($body[$key]) || $body[$key] < 1) {
        json_error($key . ' が不正です', 400);
    }
    return $body[$key];
}

function edu_cat_string(array $body, string $key): string
{
    if (!array_key_exists($key, $body) || !is_string($body[$key]) || trim($body[$key]) === '') {
        json_error($key . ' は必須です', 400);
    }
    return trim($body[$key]);
}

function edu_cat_optional_string(array $body, string $key): ?string
{
    if (!array_key_exists($key, $body)) {
        return null;
    }
    if (!is_string($body[$key]) || trim($body[$key]) === '') {
        json_error($key . ' が不正です', 400);
    }
    return trim($body[$key]);
}

/** slug は英数字・ハイフンのみ(既存 tenants.slug 規約に倣う)。 */
function edu_cat_slug(array $body, string $key): string
{
    $slug = edu_cat_string($body, $key);
    if (!preg_match('/^[a-z0-9-]+$/', $slug)) {
        json_error('slug は英小文字・数字・ハイフンのみです', 400);
    }
    return $slug;
}

function edu_cat_id_body(array $body): int
{
    if (!array_key_exists('id', $body) || !is_int($body['id']) || $body['id'] < 1) {
        json_error('id が不正です', 400);
    }
    return $body['id'];
}

/** 閲覧可否: 自テナント or 共有(tenant_id NULL)。 */
function edu_cat_assert_visible(int $id, int $tenantId): array
{
    $row = Db::one('SELECT * FROM edu_categories WHERE id = ? AND (tenant_id = ? OR tenant_id IS NULL)', [$id, $tenantId]);
    if ($row === null) {
        json_error('カテゴリが見つかりません', 404);
    }
    return $row;
}

/**
 * 編集可否: 自テナントのカテゴリは所有者が編集可。共有カテゴリ(tenant_id NULL)は superadmin のみ編集可。
 * ハイブリッド方針: テナントは共有を閲覧・出題に使えるが編集不可、superadmin は共有も編集可。
 */
function edu_cat_assert_editable(array $actor, int $id, int $tenantId): array
{
    $row = edu_cat_assert_visible($id, $tenantId);
    if ($row['tenant_id'] === null) {
        if ($actor['role'] !== 'superadmin') {
            json_error('共有教材は編集できません(コピーして独自登録してください)', 403);
        }
        return $row; // superadmin は共有を編集可
    }
    if ((int) $row['tenant_id'] !== $tenantId) {
        json_error('他組織のデータにはアクセスできません', 403);
    }
    return $row;
}

function edu_cat_is_unique_error(Throwable $e): bool
{
    return $e instanceof PDOException && str_contains($e->getMessage(), 'UNIQUE');
}

function edu_cat_handle_list(array $actor): never
{
    // superadmin が tenant_id 未指定なら共有スコープ(番兵0)。共有教材のみ一覧できる。
    $tenantId = edu_effective_tenant_id($actor, edu_cat_query_int('tenant_id'));
    // 共有(tenant_id NULL) + 自テナント。共有を先頭に。is_shared で UI が編集可否を判別できる。
    $rows = Db::all(
        'SELECT c.id, c.tenant_id, c.name, c.slug, c.color, c.sort_order, c.is_active, c.is_shared, c.created_at,
                (SELECT COUNT(*) FROM edu_questions q WHERE q.category_id = c.id) AS question_count
         FROM edu_categories c
         WHERE c.tenant_id = ? OR c.tenant_id IS NULL
         ORDER BY (c.tenant_id IS NULL) DESC, c.sort_order, c.id',
        [$tenantId]
    );
    json_out(['success' => true, 'categories' => $rows]);
}

function edu_cat_handle_get(array $actor): never
{
    // superadmin が tenant_id 未指定なら共有スコープ(番兵0)。共有教材を単体取得できる。
    $tenantId = edu_effective_tenant_id($actor, edu_cat_query_int('tenant_id'));
    $id = edu_cat_query_int('id');
    if ($id === null) {
        json_error('id が不正です', 400);
    }
    json_out(['success' => true, 'category' => edu_cat_assert_visible($id, $tenantId)]);
}

/**
 * fork: 共有カテゴリ(+その設問)を自テナントにコピーして独自登録する。
 * コピー後は自テナント所有なので編集可能。slug 衝突時は末尾に -copy を付す。
 */
function edu_cat_handle_fork(array $actor): never
{
    tet2_require_csrf();
    $body = json_body();
    $tenantId = effective_tenant_id($actor, edu_cat_body_optional_int($body, 'tenant_id'));
    $srcId = edu_cat_id_body($body);
    $src = edu_cat_assert_visible($srcId, $tenantId);
    if ($src['tenant_id'] !== null) {
        json_error('コピーできるのは共有教材のみです', 400);
    }

    $newId = Db::tx(function () use ($src, $tenantId): int {
        // slug 衝突回避
        $slug = (string) $src['slug'];
        if (Db::one('SELECT 1 FROM edu_categories WHERE tenant_id = ? AND slug = ?', [$tenantId, $slug]) !== null) {
            $slug .= '-copy';
        }
        $catId = Db::insert(
            'INSERT INTO edu_categories (tenant_id, name, slug, color, sort_order, is_active, is_shared)
             VALUES (?, ?, ?, ?, ?, 1, 0)',
            [$tenantId, $src['name'], $slug, $src['color'], $src['sort_order']]
        );
        // 共有カテゴリ配下の設問もコピー。分野のタグ(共有のタグだけが付いている)も同じものを付ける
        $qs = Db::all('SELECT * FROM edu_questions WHERE category_id = ?', [(int) $src['id']]);
        foreach ($qs as $q) {
            $newQuestionId = Db::insert(
                'INSERT INTO edu_questions (tenant_id, category_id, title, question_type, options, correct_answer, explanation, difficulty, is_active, is_shared)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0)',
                [$tenantId, $catId, $q['title'], $q['question_type'], $q['options'], $q['correct_answer'], $q['explanation'], $q['difficulty'], $q['is_active']]
            );
            Db::run(
                'INSERT INTO edu_question_tags (question_id, tag_id)
                 SELECT ?, qt.tag_id FROM edu_question_tags qt INNER JOIN edu_tags g ON g.id = qt.tag_id
                 WHERE qt.question_id = ? AND g.tenant_id IS NULL',
                [$newQuestionId, (int) $q['id']]
            );
        }
        return $catId;
    });
    audit('edu_category.fork', 'src=' . $srcId . ',new=' . $newId);
    json_out(['success' => true, 'category' => edu_cat_assert_visible($newId, $tenantId)], 201);
}

function edu_cat_handle_create(array $actor): never
{
    tet2_require_csrf();
    $body = json_body();
    $tenantId = effective_tenant_id($actor, edu_cat_body_optional_int($body, 'tenant_id'));
    $name = edu_cat_string($body, 'name');
    $slug = edu_cat_slug($body, 'slug');
    $color = edu_cat_optional_string($body, 'color');
    $sortOrder = 0;
    if (array_key_exists('sort_order', $body)) {
        if (!is_int($body['sort_order']) || $body['sort_order'] < 0) {
            json_error('sort_order が不正です', 400);
        }
        $sortOrder = $body['sort_order'];
    }

    // 共有カテゴリ(tenant_id NULL)の新規作成は superadmin のみ。通常は自テナントに作る。
    $createShared = ($actor['role'] === 'superadmin') && !empty($body['shared']);
    $ownerTenant = $createShared ? null : $tenantId;
    $isShared = $createShared ? 1 : 0;
    $id = Db::insert(
        'INSERT INTO edu_categories (tenant_id, name, slug, color, sort_order, is_active, is_shared)
         VALUES (?, ?, ?, ?, ?, 1, ?)',
        [$ownerTenant, $name, $slug, $color, $sortOrder, $isShared]
    );
    audit('edu_category.create', 'category_id=' . $id . ($createShared ? ',shared' : ''));
    json_out(['success' => true, 'category' => edu_cat_assert_visible($id, $tenantId)], 201);
}

function edu_cat_handle_update(array $actor): never
{
    tet2_require_csrf();
    $body = json_body();
    // superadmin が tenant_id 未指定なら共有スコープ(番兵0)で共有教材を編集可能にする。
    $tenantId = edu_effective_tenant_id($actor, edu_cat_body_optional_int($body, 'tenant_id'));
    $id = edu_cat_id_body($body);
    edu_cat_assert_editable($actor, $id, $tenantId); // 共有はsuperadminのみ、テナント固有は所有者のみ

    $name = edu_cat_optional_string($body, 'name');
    $color = edu_cat_optional_string($body, 'color');
    $sortOrder = null;
    if (array_key_exists('sort_order', $body) && $body['sort_order'] !== null) {
        if (!is_int($body['sort_order']) || $body['sort_order'] < 0) {
            json_error('sort_order が不正です', 400);
        }
        $sortOrder = $body['sort_order'];
    }
    $isActive = null;
    if (array_key_exists('is_active', $body) && $body['is_active'] !== null) {
        $isActive = $body['is_active'] ? 1 : 0;
    }

    if ($name === null && $color === null && $sortOrder === null && $isActive === null) {
        json_error('更新項目がありません', 400);
    }

    // 権限は assert_editable で担保済み。id 単独で更新(共有=tenant_id NULL でも動く)。
    Db::run(
        'UPDATE edu_categories
         SET name = COALESCE(?, name),
             color = COALESCE(?, color),
             sort_order = COALESCE(?, sort_order),
             is_active = COALESCE(?, is_active)
         WHERE id = ?',
        [$name, $color, $sortOrder, $isActive, $id]
    );
    audit('edu_category.update', 'category_id=' . $id);
    json_out(['success' => true, 'category' => edu_cat_assert_visible($id, $tenantId)]);
}

function edu_cat_handle_delete(array $actor): never
{
    tet2_require_csrf();
    $body = json_body();
    // superadmin が tenant_id 未指定なら共有スコープ(番兵0)で共有教材を削除可能にする。
    $tenantId = edu_effective_tenant_id($actor, edu_cat_body_optional_int($body, 'tenant_id'));
    $id = edu_cat_id_body($body);
    edu_cat_assert_editable($actor, $id, $tenantId); // 共有はsuperadminのみ削除可

    // 設問が紐づくカテゴリは削除不可(誤削除防止)。カテゴリと同スコープの設問で判定。
    $used = Db::one('SELECT id FROM edu_questions WHERE category_id = ? LIMIT 1', [$id]);
    if ($used !== null) {
        json_error('設問が存在するカテゴリは削除できません', 409);
    }

    Db::run('DELETE FROM edu_categories WHERE id = ?', [$id]);
    audit('edu_category.delete', 'category_id=' . $id);
    json_out(['success' => true]);
}

try {
    $action = $_GET['action'] ?? '';
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $actor = require_role($method === 'GET' ? 'viewer' : 'operator');

    if ($action === 'list' && $method === 'GET') {
        edu_cat_handle_list($actor);
    }
    if ($action === 'get' && $method === 'GET') {
        edu_cat_handle_get($actor);
    }
    if ($action === 'create' && $method === 'POST') {
        edu_cat_handle_create($actor);
    }
    if ($action === 'update' && $method === 'POST') {
        edu_cat_handle_update($actor);
    }
    if ($action === 'delete' && $method === 'POST') {
        edu_cat_handle_delete($actor);
    }
    if ($action === 'fork' && $method === 'POST') {
        edu_cat_handle_fork($actor);
    }
    json_error('不正なアクションです', 400);
} catch (Throwable $e) {
    if (edu_cat_is_unique_error($e)) {
        json_error('カテゴリスラッグは既に存在します', 409);
    }
    error_log($e->getMessage());
    json_error('サーバエラー', 500);
}
