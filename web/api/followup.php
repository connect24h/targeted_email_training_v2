<?php declare(strict_types=1); require __DIR__."/../lib/bootstrap.php";

function followup_query_int(string $key): int
{
    if (!array_key_exists($key, $_GET) || $_GET[$key] === '') {
        json_error($key . ' は必須です', 400);
    }
    $value = filter_var($_GET[$key], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($value === false) {
        json_error($key . ' が不正です', 400);
    }
    return (int) $value;
}

function followup_body_int(array $body, string $key): int
{
    if (!array_key_exists($key, $body) || !is_int($body[$key]) || $body[$key] < 1) {
        json_error($key . ' が不正です', 400);
    }
    return $body[$key];
}

function followup_body_optional_int(array $body, string $key): ?int
{
    if (!array_key_exists($key, $body) || $body[$key] === null) {
        return null;
    }
    if (!is_int($body[$key]) || $body[$key] < 1) {
        json_error($key . ' が不正です', 400);
    }
    return $body[$key];
}

function followup_tenant_from_query(array $actor): int
{
    $requested = isset($_GET['tenant_id']) ? (int) $_GET['tenant_id'] : null;
    return effective_tenant_id($actor, $requested);
}

function followup_failures_sql(): string
{
    return "SELECT DISTINCT t.id AS target_id, t.email, t.name, t.company, t.department,
            MAX(CASE WHEN e.event_type='auth' THEN 1 ELSE 0 END) AS did_auth,
            MAX(CASE WHEN e.event_type='click' THEN 1 ELSE 0 END) AS did_click
        FROM events e
        JOIN campaign_targets ct ON ct.tracking_id = e.tracking_id AND ct.campaign_id = e.campaign_id
        JOIN targets t ON t.id = ct.target_id
        WHERE e.campaign_id = ? AND e.tenant_id = ? AND e.event_type IN ('click','auth')
        GROUP BY t.id, t.email, t.name, t.company, t.department";
}

function followup_failures(int $campaignId, int $tenantId): array
{
    return Db::all(followup_failures_sql(), [$campaignId, $tenantId]);
}

function followup_assert_group_owned(int $groupId, int $tenantId): void
{
    $group = Db::one('SELECT id FROM groups WHERE id = ? AND tenant_id = ?', [$groupId, $tenantId]);
    if ($group === null) {
        json_error('グループが見つかりません', 404);
    }
}

function followup_insert_target_group(int $targetId, int $groupId, int $tenantId): int
{
    // target_group は tenant_id を持たないため、INSERT 時にも両側の所有を確認する。
    return Db::run(
        'INSERT OR IGNORE INTO target_group (target_id, group_id)
         SELECT ?, ?
         WHERE EXISTS (SELECT 1 FROM targets WHERE id = ? AND tenant_id = ?)
           AND EXISTS (SELECT 1 FROM groups WHERE id = ? AND tenant_id = ?)',
        [$targetId, $groupId, $targetId, $tenantId, $groupId, $tenantId]
    );
}

function followup_handle_failures(array $actor): never
{
    $campaignId = followup_query_int('campaign_id');
    $tenantId = followup_tenant_from_query($actor);
    assert_campaign_owned($campaignId, $tenantId);

    $failures = followup_failures($campaignId, $tenantId);
    json_out([
        'success' => true,
        'campaign_id' => $campaignId,
        'failures' => $failures,
        'count' => count($failures),
    ]);
}

function followup_handle_to_group(array $actor): never
{
    tet2_require_csrf();
    $body = json_body();
    $campaignId = followup_body_int($body, 'campaign_id');
    $groupId = followup_body_int($body, 'group_id');
    $tenantId = effective_tenant_id($actor, followup_body_optional_int($body, 'tenant_id'));
    assert_campaign_owned($campaignId, $tenantId);
    followup_assert_group_owned($groupId, $tenantId);

    $added = 0;
    foreach (followup_failures($campaignId, $tenantId) as $failure) {
        $added += followup_insert_target_group((int) $failure['target_id'], $groupId, $tenantId);
    }
    audit('followup.to_group', 'campaign=' . $campaignId . ',group=' . $groupId . ',added=' . $added);
    json_out(['success' => true, 'added' => $added, 'group_id' => $groupId]);
}

try {
    $action = $_GET['action'] ?? '';
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $actor = require_role($method === 'GET' ? 'viewer' : 'operator');

    if ($action === 'failures' && $method === 'GET') {
        followup_handle_failures($actor);
    }
    if ($action === 'to_group' && $method === 'POST') {
        followup_handle_to_group($actor);
    }
    json_error('不正なアクションです', 400);
} catch (RuntimeException $e) {
    json_error($e->getMessage(), 400);
} catch (Throwable $e) {
    error_log($e->getMessage());
    json_error('サーバエラー', 500);
}
