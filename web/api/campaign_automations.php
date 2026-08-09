<?php declare(strict_types=1); require __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/CampaignAutomationSchedule.php';
require_once __DIR__ . '/../lib/CampaignDraftFactory.php';
require_once __DIR__ . '/../lib/CampaignAutomationRunner.php';

const CAMPAIGN_AUTOMATION_FREQUENCIES = ['monthly', 'quarterly'];
const CAMPAIGN_AUTOMATION_TIME_MODES = ['fixed', 'random_window'];

function campaign_automations_required_role(string $action, string $method): string
{
    if ($method === 'GET' && in_array($action, ['list', 'get', 'preview'], true)) {
        return 'viewer';
    }
    return 'operator';
}

function campaign_automations_int(array $data, string $key, int $minimum = 1): int
{
    if (!array_key_exists($key, $data) || !is_int($data[$key]) || $data[$key] < $minimum) {
        json_error($key . ' が不正です', 400);
    }
    return $data[$key];
}

function campaign_automations_optional_int(array $data, string $key): ?int
{
    if (!array_key_exists($key, $data) || $data[$key] === null) {
        return null;
    }
    return campaign_automations_int($data, $key);
}

function campaign_automations_string(array $data, string $key): string
{
    if (!array_key_exists($key, $data) || !is_string($data[$key]) || trim($data[$key]) === '') {
        json_error($key . ' は必須です', 400);
    }
    return trim($data[$key]);
}

/** @return array<int, int> */
function campaign_automations_int_array(array $data, string $key): array
{
    if (!array_key_exists($key, $data) || !is_array($data[$key]) || $data[$key] === []) {
        json_error($key . ' は1件以上必須です', 400);
    }
    $ids = [];
    foreach ($data[$key] as $id) {
        if (!is_int($id) || $id < 1) {
            json_error($key . ' の要素が不正です', 400);
        }
        $ids[$id] = $id;
    }
    sort($ids);
    return array_values($ids);
}

function campaign_automations_query_id(): int
{
    $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false) {
        json_error('id が不正です', 400);
    }
    return (int) $id;
}

function campaign_automations_tenant(array $actor, array $data): int
{
    return effective_tenant_id($actor, campaign_automations_optional_int($data, 'tenant_id'));
}

function campaign_automations_query_tenant(array $actor): int
{
    if (!array_key_exists('tenant_id', $_GET) || $_GET['tenant_id'] === '') {
        return effective_tenant_id($actor, null);
    }
    $tenantId = filter_var($_GET['tenant_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($tenantId === false) {
        json_error('tenant_id が不正です', 400);
    }
    return effective_tenant_id($actor, (int) $tenantId);
}

function campaign_automations_assert_owned(int $id, int $tenantId): array
{
    $row = Db::one('SELECT * FROM campaign_automations WHERE id = ? AND tenant_id = ?', [$id, $tenantId]);
    if ($row === null) {
        json_error('自動化ルールが見つかりません', 404);
    }
    return $row;
}

function campaign_automations_assert_source(int $sourceId, int $tenantId): void
{
    $source = Db::one(
        'SELECT id FROM campaigns WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL',
        [$sourceId, $tenantId]
    );
    if ($source === null) {
        json_error('元キャンペーンが見つかりません', 404);
    }
}

/** @param array<int, int> $groupIds */
function campaign_automations_assert_groups(array $groupIds, int $tenantId): void
{
    foreach ($groupIds as $groupId) {
        if (Db::one('SELECT id FROM groups WHERE id = ? AND tenant_id = ?', [$groupId, $tenantId]) === null) {
            json_error('グループが見つかりません', 404);
        }
    }
}

/** @return array<int, int> */
function campaign_automations_group_ids(int $automationId): array
{
    $rows = Db::all(
        'SELECT group_id FROM campaign_automation_groups WHERE automation_id = ? ORDER BY group_id',
        [$automationId]
    );
    return array_map(static fn(array $row): int => (int) $row['group_id'], $rows);
}

function campaign_automations_row(int $id, int $tenantId): array
{
    $row = Db::one(
        'SELECT ca.*, c.name AS source_campaign_name,
                (SELECT COUNT(*) FROM campaign_automation_runs r WHERE r.automation_id = ca.id) AS run_count,
                lr.status AS last_run_status, lr.error_code AS last_run_error_code,
                lr.generated_campaign_id AS last_generated_campaign_id,
                COALESCE(lr.finished_at, lr.created_at) AS last_run_at
         FROM campaign_automations ca
         JOIN campaigns c ON c.id = ca.source_campaign_id
         LEFT JOIN campaign_automation_runs lr ON lr.id = (
             SELECT r2.id FROM campaign_automation_runs r2
             WHERE r2.automation_id = ca.id ORDER BY r2.id DESC LIMIT 1
         )
         WHERE ca.id = ? AND ca.tenant_id = ?',
        [$id, $tenantId]
    );
    if ($row === null) {
        json_error('自動化ルールが見つかりません', 404);
    }
    $row['group_ids'] = campaign_automations_group_ids($id);
    return $row;
}

function campaign_automations_rule_data(array $body, ?array $existing = null): array
{
    $value = static fn(string $key): mixed => array_key_exists($key, $body)
        ? $body[$key]
        : ($existing[$key] ?? null);
    $candidate = [
        'name' => $value('name'),
        'source_campaign_id' => $value('source_campaign_id'),
        'frequency' => $value('frequency'),
        'day_of_month' => $value('day_of_month'),
        'generation_lead_days' => $value('generation_lead_days'),
        'time_mode' => $value('time_mode'),
        'send_window_start' => $value('send_window_start'),
        'send_window_end' => $value('send_window_end'),
    ];
    $candidate['name'] = campaign_automations_string($candidate, 'name');
    if (mb_strlen($candidate['name']) > 200) {
        json_error('name は200文字以内です', 400);
    }
    $candidate['source_campaign_id'] = campaign_automations_int($candidate, 'source_campaign_id');
    $candidate['day_of_month'] = campaign_automations_int($candidate, 'day_of_month');
    $candidate['generation_lead_days'] = campaign_automations_int($candidate, 'generation_lead_days', 0);
    if ($candidate['time_mode'] === 'fixed') {
        $candidate['send_window_end'] = null;
    }
    try {
        (new CampaignAutomationSchedule())->next($candidate);
    } catch (InvalidArgumentException $error) {
        json_error($error->getMessage(), 400);
    }
    return $candidate;
}

function campaign_automations_next(array $rule): array
{
    try {
        return (new CampaignAutomationSchedule())->next($rule);
    } catch (InvalidArgumentException $error) {
        json_error($error->getMessage(), 400);
    }
}

/** @param array<int, int> $groupIds */
function campaign_automations_replace_groups(int $automationId, array $groupIds): void
{
    Db::run('DELETE FROM campaign_automation_groups WHERE automation_id = ?', [$automationId]);
    foreach ($groupIds as $groupId) {
        Db::run(
            'INSERT INTO campaign_automation_groups (automation_id, group_id) VALUES (?, ?)',
            [$automationId, $groupId]
        );
    }
}

/** @return array<int, int> */
function campaign_automations_target_ids(int $automationId, int $tenantId): array
{
    $rows = Db::all(
        "SELECT DISTINCT t.id
         FROM campaign_automation_groups cag
         JOIN target_group tg ON tg.group_id = cag.group_id
         JOIN targets t ON t.id = tg.target_id
         WHERE cag.automation_id = ? AND t.tenant_id = ? AND t.status = 'active'
         ORDER BY t.id",
        [$automationId, $tenantId]
    );
    return array_map(static fn(array $row): int => (int) $row['id'], $rows);
}

function campaign_automations_handle_list(array $actor): never
{
    $tenantId = campaign_automations_query_tenant($actor);
    $rows = Db::all('SELECT id FROM campaign_automations WHERE tenant_id = ? ORDER BY id DESC', [$tenantId]);
    $automations = array_map(
        static fn(array $row): array => campaign_automations_row((int) $row['id'], $tenantId),
        $rows
    );
    json_out(['success' => true, 'automations' => $automations]);
}

function campaign_automations_handle_get(array $actor): never
{
    $tenantId = campaign_automations_query_tenant($actor);
    json_out(['success' => true, 'automation' => campaign_automations_row(campaign_automations_query_id(), $tenantId)]);
}

function campaign_automations_handle_create(array $actor): never
{
    tet2_require_csrf();
    $body = json_body();
    $tenantId = campaign_automations_tenant($actor, $body);
    $data = campaign_automations_rule_data($body);
    $groupIds = campaign_automations_int_array($body, 'group_ids');
    campaign_automations_assert_source($data['source_campaign_id'], $tenantId);
    campaign_automations_assert_groups($groupIds, $tenantId);
    $next = campaign_automations_next($data);
    $id = Db::tx(function () use ($actor, $tenantId, $data, $groupIds, $next): int {
        $id = Db::insert(
            'INSERT INTO campaign_automations
             (tenant_id, name, source_campaign_id, frequency, day_of_month, generation_lead_days,
              time_mode, send_window_start, send_window_end, next_due_at, status, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$tenantId, $data['name'], $data['source_campaign_id'], $data['frequency'],
             $data['day_of_month'], $data['generation_lead_days'], $data['time_mode'],
             $data['send_window_start'], $data['send_window_end'], $next['next_due_at'],
             'active', (int) ($actor['id'] ?? 0)]
        );
        campaign_automations_replace_groups($id, $groupIds);
        return $id;
    });
    audit('campaign_automation.create', 'automation_id=' . $id);
    json_out(['success' => true, 'automation' => campaign_automations_row($id, $tenantId)], 201);
}

function campaign_automations_handle_update(array $actor): never
{
    tet2_require_csrf();
    $body = json_body();
    $tenantId = campaign_automations_tenant($actor, $body);
    $id = campaign_automations_int($body, 'id');
    $existing = campaign_automations_assert_owned($id, $tenantId);
    $data = campaign_automations_rule_data($body, $existing);
    $groupIds = array_key_exists('group_ids', $body)
        ? campaign_automations_int_array($body, 'group_ids')
        : campaign_automations_group_ids($id);
    campaign_automations_assert_source($data['source_campaign_id'], $tenantId);
    campaign_automations_assert_groups($groupIds, $tenantId);
    $next = campaign_automations_next($data);
    Db::tx(function () use ($id, $data, $groupIds, $next): void {
        Db::run(
            "UPDATE campaign_automations SET name=?, source_campaign_id=?, frequency=?, day_of_month=?,
             generation_lead_days=?, time_mode=?, send_window_start=?, send_window_end=?, next_due_at=?,
             updated_at=datetime('now','localtime') WHERE id=?",
            [$data['name'], $data['source_campaign_id'], $data['frequency'], $data['day_of_month'],
             $data['generation_lead_days'], $data['time_mode'], $data['send_window_start'],
             $data['send_window_end'], $next['next_due_at'], $id]
        );
        campaign_automations_replace_groups($id, $groupIds);
    });
    audit('campaign_automation.update', 'automation_id=' . $id);
    json_out(['success' => true, 'automation' => campaign_automations_row($id, $tenantId)]);
}

function campaign_automations_set_status(array $actor, string $status): never
{
    tet2_require_csrf();
    $body = json_body();
    $tenantId = campaign_automations_tenant($actor, $body);
    $id = campaign_automations_int($body, 'id');
    $rule = campaign_automations_assert_owned($id, $tenantId);
    $nextDueAt = $status === 'active' ? campaign_automations_next($rule)['next_due_at'] : $rule['next_due_at'];
    Db::run(
        "UPDATE campaign_automations SET status=?, next_due_at=?, updated_at=datetime('now','localtime') WHERE id=?",
        [$status, $nextDueAt, $id]
    );
    audit('campaign_automation.' . $status, 'automation_id=' . $id);
    json_out(['success' => true, 'automation' => campaign_automations_row($id, $tenantId)]);
}

function campaign_automations_handle_pause(array $actor): never
{
    campaign_automations_set_status($actor, 'paused');
}

function campaign_automations_handle_resume(array $actor): never
{
    campaign_automations_set_status($actor, 'active');
}

function campaign_automations_handle_preview(array $actor): never
{
    $tenantId = campaign_automations_query_tenant($actor);
    $id = campaign_automations_query_id();
    $rule = campaign_automations_assert_owned($id, $tenantId);
    campaign_automations_assert_source((int) $rule['source_campaign_id'], $tenantId);
    $preview = campaign_automations_next($rule);
    $preview['target_count'] = count(campaign_automations_target_ids($id, $tenantId));
    $preview['source_campaign_id'] = (int) $rule['source_campaign_id'];
    $preview['group_ids'] = campaign_automations_group_ids($id);
    json_out(['success' => true, 'preview' => $preview]);
}

function campaign_automations_handle_generate_now(array $actor): never
{
    tet2_require_csrf();
    $body = json_body();
    $tenantId = campaign_automations_tenant($actor, $body);
    $id = campaign_automations_int($body, 'id');
    $rule = campaign_automations_assert_owned($id, $tenantId);
    if ($rule['status'] !== 'active') {
        json_error('停止中の自動化ルールです', 409);
    }
    $result = (new CampaignAutomationRunner())->generateOne($id, [
        'tenant_id' => $tenantId,
        'created_by' => (int) ($actor['id'] ?? 0),
    ]);
    if ($result['status'] === 'duplicate') {
        json_error('この予定回のdraftは既に生成済みです', 409);
    }
    if ($result['status'] === 'failed') {
        $code = $result['error_code'] === 'source_not_found' ? 404 : 409;
        json_error('draft生成に失敗しました: ' . $result['error_code'], $code);
    }
    if ($result['status'] !== 'generated') {
        json_error('自動化ルールを実行できません', 409);
    }
    $runId = (int) $result['run_id'];
    $draftId = (int) $result['campaign_id'];
    audit('campaign_automation.generate', "automation_id={$id},run_id={$runId},campaign_id={$draftId}");
    $campaign = Db::one('SELECT * FROM campaigns WHERE id = ? AND tenant_id = ?', [$draftId, $tenantId]);
    json_out(['success' => true, 'run_id' => $runId, 'campaign' => $campaign], 201);
}

try {
    $action = (string) ($_GET['action'] ?? '');
    $method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $actor = require_role(campaign_automations_required_role($action, $method));
    if ($action === 'list' && $method === 'GET') campaign_automations_handle_list($actor);
    if ($action === 'get' && $method === 'GET') campaign_automations_handle_get($actor);
    if ($action === 'preview' && $method === 'GET') campaign_automations_handle_preview($actor);
    if ($action === 'create' && $method === 'POST') campaign_automations_handle_create($actor);
    if ($action === 'update' && $method === 'POST') campaign_automations_handle_update($actor);
    if ($action === 'pause' && $method === 'POST') campaign_automations_handle_pause($actor);
    if ($action === 'resume' && $method === 'POST') campaign_automations_handle_resume($actor);
    if ($action === 'generate_now' && $method === 'POST') campaign_automations_handle_generate_now($actor);
    json_error('不正なアクションです', 400);
} catch (Throwable $error) {
    error_log($error->getMessage());
    json_error('サーバエラー', 500);
}
