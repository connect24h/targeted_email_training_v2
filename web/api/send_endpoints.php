<?php declare(strict_types=1); require __DIR__."/../lib/bootstrap.php";

/**
 * 送信エンドポイントのマスタ(send_endpoints)管理 API。
 * ビーコンベースURL(kind='beacon')と送信元アドレス(kind='from')の登録元。
 * edu_tags.php と同じ流儀と権限:
 * - 閲覧(list)は viewer 以上、変更(save/delete)は tenant_admin 以上。CSRF、audit。
 * - 共有の行(tenant_id NULL)の作成・変更・削除はシステム管理者(superadmin)だけ。テナントの行は所有する組織だけ。
 * - list はテナントの行＋共有の行を返す。kind で絞れる。
 * - kind='beacon' は http(s):// の URL(campaigns.php の beacon 検証と同じ正規表現)、kind='from' はメール形式。
 */

const SEND_ENDPOINT_KINDS = ['beacon', 'from'];
// campaigns_optional_beacon_base と同一のビーコンURL検証(スキームは http/https に限定、ホストの形は緩く許す)。
const SEND_ENDPOINT_BEACON_RE = '#^https?://[^\s/][^\s]*$#';

function send_endpoint_query_int(string $key): ?int
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

function send_endpoint_body_optional_int(array $body, string $key): ?int
{
    if (!array_key_exists($key, $body) || $body[$key] === null) {
        return null;
    }
    if (!is_int($body[$key]) || $body[$key] < 1) {
        json_error($key . ' が不正です', 400);
    }
    return $body[$key];
}

function send_endpoint_kind(array $src): string
{
    $kind = is_string($src['kind'] ?? null) ? $src['kind'] : '';
    if (!in_array($kind, SEND_ENDPOINT_KINDS, true)) {
        json_error("kind は 'beacon' か 'from' を指定してください", 400);
    }
    return $kind;
}

/** kind に応じて value を検証して正規化する。 */
function send_endpoint_value(array $body, string $kind): string
{
    $value = is_string($body['value'] ?? null) ? trim($body['value']) : '';
    if ($value === '') {
        json_error('value は必須です', 400);
    }
    if (mb_strlen($value) > 500) {
        json_error('value は500文字以内にしてください', 400);
    }
    if ($kind === 'beacon') {
        if (!preg_match(SEND_ENDPOINT_BEACON_RE, $value)) {
            json_error('ビーコンは http:// または https:// で始まる URL を指定してください', 400);
        }
    } else { // from
        if (filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            json_error('送信元はメールアドレス形式で指定してください', 400);
        }
    }
    return $value;
}

function send_endpoint_label(array $body): ?string
{
    if (!array_key_exists('label', $body) || $body['label'] === null) {
        return null;
    }
    if (!is_string($body['label'])) {
        json_error('label が不正です', 400);
    }
    $label = trim($body['label']);
    if (mb_strlen($label) > 100) {
        json_error('label は100文字以内にしてください', 400);
    }
    return $label === '' ? null : $label;
}

function send_endpoint_sort_order(array $body): ?int
{
    if (!array_key_exists('sort_order', $body) || $body['sort_order'] === null) {
        return null;
    }
    if (!is_int($body['sort_order']) || $body['sort_order'] < 0) {
        json_error('sort_order が不正です', 400);
    }
    return $body['sort_order'];
}

function send_endpoint_id_body(array $body): int
{
    if (!array_key_exists('id', $body) || !is_int($body['id']) || $body['id'] < 1) {
        json_error('id が不正です', 400);
    }
    return $body['id'];
}

/** 見える行(自テナント＋共有)を返す。 */
function send_endpoint_assert_visible(int $id, int $tenantId): array
{
    $row = Db::one('SELECT * FROM send_endpoints WHERE id = ? AND (tenant_id = ? OR tenant_id IS NULL)', [$id, $tenantId]);
    if ($row === null) {
        json_error('エンドポイントが見つかりません', 404);
    }
    return $row;
}

/** 編集可否: 共有(tenant_id NULL)は superadmin だけ、テナントの行は所有する組織だけ(edu_tag と同じ)。 */
function send_endpoint_assert_editable(array $actor, int $id, int $tenantId): array
{
    $row = send_endpoint_assert_visible($id, $tenantId);
    if ($row['tenant_id'] === null) {
        if ($actor['role'] !== 'superadmin') {
            json_error('共有のエンドポイントは編集できません(システム管理者だけが編集できます)', 403);
        }
        return $row;
    }
    if ((int) $row['tenant_id'] !== $tenantId) {
        json_error('他組織のデータにはアクセスできません', 403);
    }
    return $row;
}

function send_endpoint_is_unique_error(Throwable $e): bool
{
    return $e instanceof PDOException && str_contains($e->getMessage(), 'UNIQUE');
}

function send_endpoint_write(callable $write): int
{
    try {
        return (int) $write();
    } catch (PDOException $e) {
        if (send_endpoint_is_unique_error($e)) {
            json_error('同じ値のエンドポイントが既に登録されています', 409);
        }
        throw $e;
    }
}

/** list: テナントの行＋共有の行。kind 指定で絞る。sort_order→id 順。 */
function send_endpoint_handle_list(array $actor): never
{
    $tenantId = edu_effective_tenant_id($actor, send_endpoint_query_int('tenant_id'));
    $kind = null;
    if (isset($_GET['kind']) && $_GET['kind'] !== '') {
        $kind = $_GET['kind'];
        if (!in_array($kind, SEND_ENDPOINT_KINDS, true)) {
            json_error("kind は 'beacon' か 'from' を指定してください", 400);
        }
    }
    $sql = 'SELECT * FROM send_endpoints WHERE (tenant_id = ? OR tenant_id IS NULL)';
    $params = [$tenantId];
    if ($kind !== null) {
        $sql .= ' AND kind = ?';
        $params[] = $kind;
    }
    // 共有を後ろ、テナントを前に。同じ持ち主内は sort_order→id。
    $sql .= ' ORDER BY (tenant_id IS NULL), kind, sort_order, id';
    $rows = Db::all($sql, $params);
    $endpoints = array_map(static function (array $r): array {
        return [
            'id'         => (int) $r['id'],
            'tenant_id'  => $r['tenant_id'] !== null ? (int) $r['tenant_id'] : null,
            'shared'     => $r['tenant_id'] === null,
            'kind'       => (string) $r['kind'],
            'value'      => (string) $r['value'],
            'label'      => $r['label'] !== null ? (string) $r['label'] : null,
            'sort_order' => (int) $r['sort_order'],
            'is_active'  => (int) $r['is_active'],
        ];
    }, $rows);
    json_out(['success' => true, 'endpoints' => $endpoints]);
}

function send_endpoint_handle_create(array $actor): never
{
    tet2_require_csrf();
    $body = json_body();
    $tenantId = edu_effective_tenant_id($actor, send_endpoint_body_optional_int($body, 'tenant_id'));
    $kind = send_endpoint_kind($body);
    $value = send_endpoint_value($body, $kind);
    $label = send_endpoint_label($body);
    $sortOrder = send_endpoint_sort_order($body) ?? 0;
    // 共有の行(tenant_id NULL)を作れるのは superadmin だけ。組織を選んでいない superadmin(番兵0)は共有に作る。
    $createShared = $actor['role'] === 'superadmin' && (!empty($body['shared']) || $tenantId === 0);
    $owner = $createShared ? null : $tenantId;
    if ($owner === 0) {
        json_error('組織を指定してください', 400);
    }
    $id = send_endpoint_write(static fn(): int => Db::insert(
        'INSERT INTO send_endpoints (tenant_id, kind, value, label, sort_order) VALUES (?, ?, ?, ?, ?)',
        [$owner, $kind, $value, $label, $sortOrder]
    ));
    audit('send_endpoint.create', 'id=' . $id . ',kind=' . $kind . ($createShared ? ',shared' : ''));
    json_out(['success' => true, 'id' => $id], 201);
}

function send_endpoint_handle_update(array $actor): never
{
    tet2_require_csrf();
    $body = json_body();
    $tenantId = edu_effective_tenant_id($actor, send_endpoint_body_optional_int($body, 'tenant_id'));
    $id = send_endpoint_id_body($body);
    $row = send_endpoint_assert_editable($actor, $id, $tenantId);
    $kind = (string) $row['kind']; // kind は変更しない(値の検証の基準)

    $value = array_key_exists('value', $body) ? send_endpoint_value($body, $kind) : null;
    $labelGiven = array_key_exists('label', $body);
    $label = $labelGiven ? send_endpoint_label($body) : null;
    $sortOrder = send_endpoint_sort_order($body);
    $activeGiven = array_key_exists('is_active', $body);
    $active = null;
    if ($activeGiven) {
        if (!is_bool($body['is_active']) && $body['is_active'] !== 0 && $body['is_active'] !== 1) {
            json_error('is_active が不正です', 400);
        }
        $active = ($body['is_active'] === true || $body['is_active'] === 1) ? 1 : 0;
    }
    if ($value === null && !$labelGiven && $sortOrder === null && !$activeGiven) {
        json_error('更新項目がありません', 400);
    }
    send_endpoint_write(static fn(): int => Db::run(
        "UPDATE send_endpoints
         SET value = COALESCE(?, value),
             label = CASE WHEN ? THEN ? ELSE label END,
             sort_order = COALESCE(?, sort_order),
             is_active = COALESCE(?, is_active),
             updated_at = datetime('now','localtime')
         WHERE id = ?",
        [$value, $labelGiven ? 1 : 0, $label, $sortOrder, $active, $id]
    ));
    audit('send_endpoint.update', 'id=' . $id);
    json_out(['success' => true]);
}

function send_endpoint_handle_delete(array $actor): never
{
    tet2_require_csrf();
    $body = json_body();
    $tenantId = edu_effective_tenant_id($actor, send_endpoint_body_optional_int($body, 'tenant_id'));
    $id = send_endpoint_id_body($body);
    send_endpoint_assert_editable($actor, $id, $tenantId);
    // 参照は JSON の値のコピーとして各キャンペーンへ入るため、削除しても過去のキャンペーンの確定値は壊れない。
    Db::run('DELETE FROM send_endpoints WHERE id = ?', [$id]);
    audit('send_endpoint.delete', 'id=' . $id);
    json_out(['success' => true]);
}

try {
    $action = $_GET['action'] ?? '';
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    // 変更は tenant_admin 以上(共有の変更はさらに superadmin 限定を assert_editable で担保)。
    $actor = require_role($method === 'GET' ? 'viewer' : 'tenant_admin');

    if ($action === 'list' && $method === 'GET') {
        send_endpoint_handle_list($actor);
    }
    if ($action === 'create' && $method === 'POST') {
        send_endpoint_handle_create($actor);
    }
    if ($action === 'update' && $method === 'POST') {
        send_endpoint_handle_update($actor);
    }
    if ($action === 'delete' && $method === 'POST') {
        send_endpoint_handle_delete($actor);
    }
    json_error('不正なアクションです', 400);
} catch (Throwable $e) {
    error_log($e->getMessage());
    json_error('サーバエラー', 500);
}
