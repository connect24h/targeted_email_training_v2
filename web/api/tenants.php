<?php declare(strict_types=1); require __DIR__."/../lib/bootstrap.php";
require_once __DIR__ . '/../lib/TenantPurge.php';
require_once __DIR__ . '/../lib/PasswordPolicy.php';

/**
 * テナントの管理(superadmin だけ)。
 *   list / get                  : 一覧と詳細(数字、ユーザ、最近の操作)
 *   create / update             : 作成(最初の管理者も作れる)と編集(名前、状態 active/suspended、管理の項目)
 *   delete / restore / purge    : 論理削除(停止中だけ)、復元(停止中に戻す)、完全削除(保持期間の後、slug で確認)
 */

/** 管理の項目(T6)。更新できる列名はこの固定値だけ。 */
const TENANT_MANAGED_FIELDS = ['contact_name', 'contact_email', 'contract_end_date', 'target_limit', 'memo'];

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

function tenants_query_int(string $key): int
{
    $value = filter_var($_GET[$key] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($value === false) {
        json_error($key . ' が不正です', 400);
    }
    return (int) $value;
}

function tenants_is_unique_error(Throwable $e): bool
{
    return $e instanceof PDOException && str_contains($e->getMessage(), 'UNIQUE');
}

/**
 * 管理の項目を検証し、body にあるものだけを返す(空文字と null は「消す」= NULL)。
 * @return array<string, string|int|null>
 */
function tenants_managed_fields(array $body): array
{
    $out = [];
    foreach (TENANT_MANAGED_FIELDS as $key) {
        if (!array_key_exists($key, $body)) {
            continue;
        }
        $value = $body[$key];
        if ($value === null || (is_string($value) && trim($value) === '')) {
            $out[$key] = null;
            continue;
        }
        if ($key === 'target_limit') {
            if (is_string($value) && preg_match('/^[1-9][0-9]{0,6}$/D', trim($value)) === 1) {
                $value = (int) trim($value);
            }
            if (!is_int($value) || $value < 1 || $value > 9999999) {
                json_error('対象者数の上限は正の整数で入力してください（空なら上限なし）', 400);
            }
            $out[$key] = $value;
            continue;
        }
        if (!is_string($value)) {
            json_error($key . ' が不正です', 400);
        }
        $value = trim($value);
        if ($key === 'contact_email' && (filter_var($value, FILTER_VALIDATE_EMAIL) === false || strlen($value) > 254)) {
            json_error('担当者のメールの形が正しくありません', 400);
        }
        if ($key === 'contract_end_date') {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
            if ($date === false || $date->format('Y-m-d') !== $value) {
                json_error('契約の終了日は YYYY-MM-DD の日付で入力してください', 400);
            }
        }
        if ($key === 'contact_name' && mb_strlen($value) > 100) {
            json_error('担当者の名前は100文字以内で入力してください', 400);
        }
        if ($key === 'memo' && mb_strlen($value) > 2000) {
            json_error('メモは2000文字以内で入力してください', 400);
        }
        $out[$key] = $value;
    }
    return $out;
}

/** 一覧と詳細で使う数字つきのテナントの行。$id を渡すとその1件だけ。 */
function tenants_rows(?int $id = null): array
{
    $where = $id !== null ? 'WHERE t.id = ?' : '';
    $rows = Db::all(
        "SELECT t.id, t.name, t.slug, t.data_dir, t.status, t.created_at, t.deleted_at,
                t.contact_name, t.contact_email, t.contract_end_date, t.target_limit, t.memo,
                (SELECT COUNT(*) FROM users u WHERE u.tenant_id = t.id) AS user_count,
                (SELECT COUNT(*) FROM targets x WHERE x.tenant_id = t.id AND x.status = 'active' AND x.is_test = 0) AS target_count,
                (SELECT COUNT(*) FROM campaigns c WHERE c.tenant_id = t.id AND c.deleted_at IS NULL) AS campaign_count,
                (SELECT COUNT(*) FROM campaigns c WHERE c.tenant_id = t.id AND c.status IN ('running','scheduled')) AS campaign_active_count,
                (SELECT COUNT(*) FROM edu_deliveries d WHERE d.tenant_id = t.id) AS edu_delivery_count,
                (SELECT COUNT(*) FROM edu_deliveries d WHERE d.tenant_id = t.id AND d.status IN ('running','scheduled')) AS edu_active_count,
                (SELECT MAX(ct.sent_at) FROM campaign_targets ct JOIN campaigns c ON c.id = ct.campaign_id
                  WHERE c.tenant_id = t.id AND ct.send_status = 'sent') AS last_sent_at,
                (SELECT MAX(u.last_login_at) FROM users u WHERE u.tenant_id = t.id) AS last_login_at
         FROM tenants t {$where}
         ORDER BY t.id",
        $id !== null ? [$id] : []
    );
    $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo'));
    $today = TenantStatus::today();
    foreach ($rows as &$row) {
        foreach (['user_count', 'target_count', 'campaign_count', 'campaign_active_count', 'edu_delivery_count', 'edu_active_count'] as $key) {
            $row[$key] = (int) $row[$key];
        }
        $row['id'] = (int) $row['id'];
        $row['target_limit'] = $row['target_limit'] !== null ? (int) $row['target_limit'] : null;
        $row['over_target_limit'] = $row['target_limit'] !== null && $row['target_count'] > $row['target_limit'];
        $contractLeft = TenantStatus::contractDaysLeft($row['contract_end_date'] ?? null, $today);
        $row['contract_days_left'] = $contractLeft;
        $row['contract_expiring'] = $contractLeft !== null && $contractLeft <= TenantStatus::CONTRACT_WARN_DAYS;
        $retention = $row['status'] === TenantStatus::DELETED ? TenantStatus::retentionDaysLeft($row['deleted_at'] ?? null, $now) : null;
        $row['retention_days_left'] = $retention;
        $row['purge_available'] = $retention === 0;
    }
    unset($row);
    return $rows;
}

function tenants_assert_exists(int $id): array
{
    $tenant = Db::one('SELECT * FROM tenants WHERE id = ?', [$id]);
    if ($tenant === null) {
        json_error('テナントが見つかりません', 404);
    }
    return $tenant;
}

function tenants_handle_list(): never
{
    json_out([
        'success' => true,
        'tenants' => tenants_rows(),
        'retention_days' => TenantStatus::RETENTION_DAYS,
        'contract_warn_days' => TenantStatus::CONTRACT_WARN_DAYS,
    ]);
}

/** 詳細: 数字、ユーザの一覧、最近の操作(監査ログ 20件)。 */
function tenants_handle_get(): never
{
    $id = tenants_query_int('id');
    tenants_assert_exists($id);
    $tenant = tenants_rows($id)[0];
    $users = Db::all(
        'SELECT id, email, name, role, status, last_login_at, created_at FROM users WHERE tenant_id = ? ORDER BY id',
        [$id]
    );
    // テナントのユーザの操作と、superadmin がこのテナントに対して行ったテナントの操作(detail が tenant_id=<id> で始まる)
    $audit = Db::all(
        "SELECT a.id, a.occurred_at, a.action, a.detail, a.ip, u.email AS user_email
         FROM audit_log a LEFT JOIN users u ON u.id = a.user_id
         WHERE a.tenant_id = ?
            OR (a.action LIKE 'tenant.%' AND (a.detail = ? OR substr(a.detail, 1, ?) = ?))
         ORDER BY a.id DESC LIMIT 20",
        [$id, 'tenant_id=' . $id, strlen('tenant_id=' . $id . ','), 'tenant_id=' . $id . ',']
    );
    json_out(['success' => true, 'tenant' => $tenant, 'users' => $users, 'audit' => $audit]);
}

/**
 * 作成。任意で最初の管理者(tenant_admin)も同じトランザクションで作る。
 * 初期パスワードの扱いは既存のユーザ作成(users.php)と同じ: 画面で入力し、PasswordPolicy の決まりに従う。メールは送らない。
 */
function tenants_handle_create(): never
{
    tet2_require_csrf();
    $body = json_body();
    $name = tenants_string($body, 'name');
    $slug = tenants_string($body, 'slug');
    if (!TenantStatus::isValidSlug($slug)) {
        json_error('slug が不正です', 400);
    }
    $fields = tenants_managed_fields($body);

    $admin = null;
    $adminEmail = isset($body['admin_email']) && is_string($body['admin_email']) ? trim($body['admin_email']) : '';
    if ($adminEmail !== '') {
        if (filter_var($adminEmail, FILTER_VALIDATE_EMAIL) === false) {
            json_error('管理者のメールの形が正しくありません', 400);
        }
        $adminName = isset($body['admin_name']) && is_string($body['admin_name']) ? trim($body['admin_name']) : '';
        if ($adminName === '') {
            json_error('管理者の名前を入力してください', 400);
        }
        $adminPassword = isset($body['admin_password']) && is_string($body['admin_password']) ? $body['admin_password'] : '';
        $violation = PasswordPolicy::violation($adminPassword);
        if ($violation !== null) {
            json_error('管理者の初期' . $violation, 400);
        }
        $admin = ['email' => $adminEmail, 'name' => $adminName, 'password' => $adminPassword];
    }
    if (Db::one('SELECT id FROM tenants WHERE slug = ?', [$slug]) !== null) {
        json_error('slug は既に使用されています', 409);
    }
    if ($admin !== null && Db::one('SELECT id FROM users WHERE email = ?', [$admin['email']]) !== null) {
        json_error('管理者のメールは既に使用されています', 409);
    }

    [$id, $userId] = Db::tx(static function () use ($name, $slug, $fields, $admin): array {
        $columns = array_merge(['name', 'slug', 'data_dir', 'status'], array_keys($fields));
        $values = array_merge([$name, $slug, TenantStatus::dataRoot() . '/' . $slug, TenantStatus::ACTIVE], array_values($fields));
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $tenantId = Db::insert('INSERT INTO tenants (' . implode(', ', $columns) . ") VALUES ({$placeholders})", $values);
        $userId = null;
        if ($admin !== null) {
            $userId = Db::insert(
                'INSERT INTO users (tenant_id, email, password_hash, name, role, status) VALUES (?, ?, ?, ?, ?, ?)',
                [$tenantId, $admin['email'], password_hash($admin['password'], PASSWORD_DEFAULT), $admin['name'], 'tenant_admin', 'active']
            );
        }
        return [$tenantId, $userId];
    });
    audit('tenant.create', 'tenant_id=' . $id . ',slug=' . $slug);
    if ($userId !== null) {
        audit('user.create', 'user_id=' . $userId . ',tenant_id=' . $id . ',role=tenant_admin');
    }
    $tenant = tenants_rows($id)[0];
    json_out(['success' => true, 'tenant' => $tenant, 'admin_user_id' => $userId], 201);
}

function tenants_handle_update(): never
{
    tet2_require_csrf();
    $body = json_body();
    $id = tenants_int($body, 'id');
    $name = tenants_optional_string($body, 'name');
    $status = tenants_optional_string($body, 'status');
    $fields = tenants_managed_fields($body);
    if ($name === null && $status === null && $fields === []) {
        json_error('更新項目がありません', 400);
    }
    if ($status !== null && !in_array($status, [TenantStatus::ACTIVE, TenantStatus::SUSPENDED], true)) {
        json_error('status が不正です', 400);
    }
    $current = tenants_assert_exists($id);
    if ($status !== null && $current['status'] === TenantStatus::DELETED) {
        json_error('削除済みのテナントの状態は変えられません。先に復元してください', 409);
    }

    $sets = [];
    $params = [];
    if ($name !== null) {
        $sets[] = 'name = ?';
        $params[] = $name;
    }
    if ($status !== null) {
        $sets[] = 'status = ?';
        $params[] = $status;
    }
    foreach ($fields as $key => $value) {
        // 列名は TENANT_MANAGED_FIELDS の固定値だけ
        $sets[] = $key . ' = ?';
        $params[] = $value;
    }
    $params[] = $id;
    Db::run('UPDATE tenants SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);
    $changed = array_merge($name !== null ? ['name'] : [], $status !== null ? ['status'] : [], array_keys($fields));
    audit('tenant.update', 'tenant_id=' . $id . ',fields=' . implode('|', $changed));
    if ($status !== null && $status !== $current['status']) {
        audit($status === TenantStatus::SUSPENDED ? 'tenant.suspend' : 'tenant.activate', 'tenant_id=' . $id);
    }
    json_out(['success' => true, 'tenant' => tenants_rows($id)[0]]);
}

/** 確認のために入力した slug(API 側でも一致を確かめる)。 */
function tenants_confirm_slug(array $body, array $tenant): void
{
    $confirm = isset($body['confirm_slug']) && is_string($body['confirm_slug']) ? trim($body['confirm_slug']) : '';
    if (!hash_equals((string) $tenant['slug'], $confirm)) {
        json_error('確認のために入力した slug が一致しません', 400);
    }
}

/** 論理削除。停止中で、実行中・予約中のキャンペーンと教育の配信がないテナントだけ。 */
function tenants_handle_delete(): never
{
    tet2_require_csrf();
    $body = json_body();
    $id = tenants_int($body, 'id');
    $tenant = tenants_assert_exists($id);
    if ($tenant['status'] === TenantStatus::DELETED) {
        json_error('このテナントは既に削除済みです', 409);
    }
    if ($tenant['status'] !== TenantStatus::SUSPENDED) {
        json_error('有効なテナントは削除できません。先に停止してください', 409);
    }
    tenants_confirm_slug($body, $tenant);
    $blockers = TenantStatus::activeWorkBlockers($id);
    if ($blockers !== []) {
        json_error($blockers[0], 409);
    }
    $updated = Db::txImmediate(static function () use ($id): int {
        // 確認と更新の間に状態や予定が変わっていないことを、書き込みの予約を取った中で確かめ直す
        if (TenantStatus::activeWorkBlockers($id) !== []) {
            return 0;
        }
        return Db::run(
            "UPDATE tenants SET status = 'deleted', deleted_at = datetime('now','localtime') WHERE id = ? AND status = 'suspended'",
            [$id]
        );
    });
    if ($updated !== 1) {
        json_error('削除の条件を満たさなくなりました。画面を更新してやり直してください', 409);
    }
    audit('tenant.delete', 'tenant_id=' . $id . ',name=' . $tenant['name'] . ',slug=' . $tenant['slug']);
    json_out(['success' => true, 'tenant' => tenants_rows($id)[0]]);
}

/** 復元。削除済みを停止中に戻す(有効にするのは別の操作)。 */
function tenants_handle_restore(): never
{
    tet2_require_csrf();
    $body = json_body();
    $id = tenants_int($body, 'id');
    $tenant = tenants_assert_exists($id);
    if ($tenant['status'] !== TenantStatus::DELETED) {
        json_error('削除済みのテナントだけを復元できます', 409);
    }
    Db::run("UPDATE tenants SET status = 'suspended', deleted_at = NULL WHERE id = ? AND status = 'deleted'", [$id]);
    audit('tenant.restore', 'tenant_id=' . $id);
    json_out(['success' => true, 'tenant' => tenants_rows($id)[0]]);
}

/** 完全削除。保持期間を過ぎた削除済みのテナントだけ。手順は TenantPurge。 */
function tenants_handle_purge(?TenantPurge $purger = null): never
{
    tet2_require_csrf();
    $body = json_body();
    $id = tenants_int($body, 'id');
    $tenant = tenants_assert_exists($id);
    tenants_confirm_slug($body, $tenant);
    $purger ??= new TenantPurge();
    try {
        $result = $purger->purge($id, (string) $tenant['slug']);
    } catch (TenantPurgeError $e) {
        json_error($e->getMessage(), $e->getCode() >= 400 ? $e->getCode() : 500);
    }
    $nonZero = array_filter($result['rows'], static fn(int $n): bool => $n > 0);
    audit('tenant.purge', 'tenant_id=' . $id . ',name=' . $tenant['name'] . ',slug=' . $tenant['slug']
        . ',rows=' . $result['total'] . ',tables=' . json_encode($nonZero, JSON_UNESCAPED_UNICODE)
        . ',evacuated_to=' . $result['evacuated_to']);
    json_out([
        'success' => true,
        'rows' => $result['rows'],
        'total' => $result['total'],
        'evacuated_to' => $result['evacuated_to'],
        'files_moved' => $result['files_moved'],
    ]);
}

try {
    require_role('superadmin');
    $action = $_GET['action'] ?? '';
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($action === 'list' && $method === 'GET') {
        tenants_handle_list();
    }
    if ($action === 'get' && $method === 'GET') {
        tenants_handle_get();
    }
    if ($action === 'create' && $method === 'POST') {
        tenants_handle_create();
    }
    if ($action === 'update' && $method === 'POST') {
        tenants_handle_update();
    }
    if ($action === 'delete' && $method === 'POST') {
        tenants_handle_delete();
    }
    if ($action === 'restore' && $method === 'POST') {
        tenants_handle_restore();
    }
    if ($action === 'purge' && $method === 'POST') {
        tenants_handle_purge();
    }
    json_error('不正なアクションです', 400);
} catch (Throwable $e) {
    if (tenants_is_unique_error($e)) {
        json_error(str_contains($e->getMessage(), 'users.email') ? '管理者のメールは既に使用されています' : 'slug は既に使用されています', 409);
    }
    error_log($e->getMessage());
    json_error('サーバエラー', 500);
}
