<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

tet2_test_boot();
require_once __DIR__ . '/../lib/CampaignAutomationSchedule.php';
require_once __DIR__ . '/../lib/CampaignDraftFactory.php';
require_once __DIR__ . '/../lib/CampaignAutomationRunner.php';
load_api('campaign_automations');

function automationTemplate(string $kind, string $name, ?int $authFlag = null): int
{
    return Db::insert(
        'INSERT INTO templates (tenant_id, kind, name, content, auth_flag) VALUES (?, ?, ?, ?, ?)',
        [1, $kind, $name, $name . ' content', $authFlag]
    );
}

function automationSource(
    int $subjectId,
    int $bodyId,
    int $phishId,
    string $status = 'done',
    string $delivery = 'distribute',
    int $contentCount = 6
): int
{
    $campaignId = Db::insert(
        'INSERT INTO campaigns
         (tenant_id, name, status, subject_template_id, body_template_id, phish_template_id,
          from_address, link_mode, send_mode, content_delivery, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [1, 'Automation Source', $status, $subjectId, $bodyId, $phishId,
         'automation@example.test', 'link', 'normal', $delivery, 1]
    );
    foreach (range(1, $contentCount) as $contentNo) {
        Db::run(
            'INSERT INTO campaign_contents
             (campaign_id, content_no, subject_template_id, body_template_id, phish_template_id, link_mode)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$campaignId, $contentNo, $subjectId, $bodyId, $phishId, 'link']
        );
    }
    return $campaignId;
}

function automationCall(string $handler, array $options = []): array
{
    $_GET = $options['query'] ?? [];
    return call_handler(
        $handler,
        $options['body'] ?? [],
        $options['role'] ?? 'operator',
        [$options['actor'] ?? current_user()]
    );
}

function baseAutomationBody(int $sourceId, array $overrides = []): array
{
    return array_merge([
        'name' => '月次セキュリティ訓練',
        'source_campaign_id' => $sourceId,
        'frequency' => 'monthly',
        'day_of_month' => 15,
        'generation_lead_days' => 5,
        'assignment_mode' => 'rotate',
        'max_occurrences' => 6,
        'time_mode' => 'fixed',
        'send_window_start' => '09:30',
        'group_ids' => [1],
    ], $overrides);
}

$subjectId = automationTemplate('subject', 'Automation Subject');
$bodyId = automationTemplate('body', 'Automation Body');
$phishId = automationTemplate('phish_login', 'Automation Phish', 1);
$sourceId = automationSource($subjectId, $bodyId, $phishId);
$group2 = Db::insert('INSERT INTO groups (tenant_id, name, kind) VALUES (?, ?, ?)', [1, 'Automation Group 2', 'custom']);
Db::run('INSERT INTO target_group (target_id, group_id) VALUES (?, ?)', [2, $group2]);
$otherGroup = Db::insert('INSERT INTO groups (tenant_id, name, kind) VALUES (?, ?, ?)', [2, 'Other Automation Group', 'custom']);
$draftSourceId = automationSource($subjectId, $bodyId, $phishId, 'draft');
$allDeliverySourceId = automationSource($subjectId, $bodyId, $phishId, 'done', 'all');
$singleContentSourceId = automationSource($subjectId, $bodyId, $phishId, 'done', 'distribute', 1);

echo "=== campaign automations API ===\n";

check(campaign_automations_required_role('list', 'GET') === 'viewer', 'listはviewer以上');
check(campaign_automations_required_role('preview', 'GET') === 'viewer', 'previewはviewer以上');
check(campaign_automations_required_role('create', 'POST') === 'operator', 'createはoperator以上');
check(campaign_automations_required_role('generate_now', 'POST') === 'operator', 'generate_nowはoperator以上');

$response = automationCall('campaign_automations_handle_create', [
    'body' => baseAutomationBody($sourceId, ['group_ids' => [1, $group2]]),
]);
check($response['code'] === 201, 'rule作成は201');
check($GLOBALS['__TET2_TEST_CSRF_CALLS'] === 1, 'rule作成はCSRF検証を呼ぶ');
$automationId = (int) ($response['payload']['automation']['id'] ?? 0);
check($automationId > 0, '作成したrule IDを返す');
check(($response['payload']['automation']['status'] ?? '') === 'active', '初期statusはactive');
check(($response['payload']['automation']['assignment_mode'] ?? '') === 'rotate', 'rotation modeを保存する');
check((int) ($response['payload']['automation']['max_occurrences'] ?? 0) === 6, '実施回数を保存する');
check(($response['payload']['automation']['group_ids'] ?? []) === [1, $group2], 'group IDsを保存する');
check(!empty($response['payload']['automation']['next_due_at']), 'next_due_atを計算する');
check($response['payload']['automation']['send_window_end'] === null, 'fixed modeのwindow終端をNULLへ正規化する');

$response = automationCall('campaign_automations_handle_list', ['role' => 'viewer']);
check($response['code'] === 200, 'viewerがlistできる');
check(count($response['payload']['automations'] ?? []) === 1, '自tenantのruleだけlistする');
$response = automationCall('campaign_automations_handle_list', [
    'role' => 'superadmin',
    'actor' => ['id' => 1, 'tenant_id' => null, 'role' => 'superadmin', 'email' => 'admin@example.test'],
    'query' => ['tenant_id' => '1'],
]);
check($response['code'] === 200, 'superadminはGET文字列tenant_idを指定できる');
$response = automationCall('campaign_automations_handle_get', [
    'role' => 'viewer',
    'query' => ['id' => $automationId],
]);
check($response['code'] === 200, 'viewerがrule詳細を取得できる');

$otherAutomation = Db::insert(
    'INSERT INTO campaign_automations
     (tenant_id, name, source_campaign_id, frequency, day_of_month, generation_lead_days,
      time_mode, send_window_start, next_due_at, status, created_by)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
    [2, 'Other Rule', 3, 'monthly', 10, 5, 'fixed', '10:00', '2026-09-05 10:00:00', 'active', 2]
);
$response = automationCall('campaign_automations_handle_get', [
    'role' => 'viewer',
    'query' => ['id' => $otherAutomation],
]);
check($response['code'] === 404, '他tenant ruleは404');

$invalidBodies = [
    [baseAutomationBody($sourceId, ['day_of_month' => 29]), 'day 29を拒否'],
    [baseAutomationBody($sourceId, [
        'time_mode' => 'random_window',
        'send_window_start' => '11:00',
        'send_window_end' => '10:00',
    ]), '逆転random windowを拒否'],
    [baseAutomationBody(3), '他tenant sourceを拒否'],
    [baseAutomationBody($sourceId, ['group_ids' => [$otherGroup]]), '他tenant groupを拒否'],
    [baseAutomationBody($sourceId, ['assignment_mode' => 'random']), '不正なassignment modeを拒否'],
    [baseAutomationBody($sourceId, ['max_occurrences' => 0]), '実施回数0を拒否'],
    [baseAutomationBody($sourceId, ['max_occurrences' => 121]), '実施回数121を拒否'],
    [baseAutomationBody($sourceId, ['max_occurrences' => null]), 'rotationの実施回数未指定を拒否'],
    [baseAutomationBody($sourceId, ['max_occurrences' => 7]), 'content数を超えるrotation回数を拒否'],
    [baseAutomationBody($draftSourceId), '未完了sourceのrotationを拒否'],
    [baseAutomationBody($allDeliverySourceId), '全員配信sourceのrotationを拒否'],
    [baseAutomationBody($singleContentSourceId, ['max_occurrences' => 1]), '1contentのrotationを拒否'],
];
foreach ($invalidBodies as [$invalidBody, $message]) {
    $response = automationCall('campaign_automations_handle_create', ['body' => $invalidBody]);
    check(in_array($response['code'], [400, 404], true), $message);
}

$deletedSourceId = automationSource($subjectId, $bodyId, $phishId);
Db::run("UPDATE campaigns SET deleted_at = datetime('now') WHERE id = ?", [$deletedSourceId]);
$response = automationCall('campaign_automations_handle_create', ['body' => baseAutomationBody($deletedSourceId)]);
check($response['code'] === 404, '削除済みsourceを拒否');

$beforePreviewRuns = (int) Db::one('SELECT COUNT(*) AS n FROM campaign_automation_runs')['n'];
$response = automationCall('campaign_automations_handle_preview', [
    'role' => 'viewer',
    'query' => ['id' => $automationId],
]);
check($response['code'] === 200, 'previewは200');
check((int) ($response['payload']['preview']['target_count'] ?? 0) === 2, 'previewはactive対象者を重複除去して数える');
check((int) ($response['payload']['preview']['content_count'] ?? 0) === 6, 'previewはcontent数を返す');
check((int) ($response['payload']['preview']['completed_occurrences'] ?? -1) === 0, 'previewは完了回数を返す');
check(!empty($response['payload']['preview']['occurrence_key']), 'previewはoccurrence keyを返す');
check((int) Db::one('SELECT COUNT(*) AS n FROM campaign_automation_runs')['n'] === $beforePreviewRuns, 'previewはDBを変更しない');

$response = automationCall('campaign_automations_handle_update', [
    'body' => ['id' => $automationId, 'day_of_month' => 20, 'group_ids' => [1]],
]);
check($response['code'] === 200, 'rule更新は200');
check((int) $response['payload']['automation']['day_of_month'] === 20, 'dayを更新する');
check($response['payload']['automation']['group_ids'] === [1], 'group対応を置換する');

$response = automationCall('campaign_automations_handle_pause', ['body' => ['id' => $automationId]]);
check($response['code'] === 200 && $response['payload']['automation']['status'] === 'paused', 'pauseする');
$response = automationCall('campaign_automations_handle_generate_now', ['body' => ['id' => $automationId]]);
check($response['code'] === 409, 'paused ruleはgenerateできない');
$response = automationCall('campaign_automations_handle_resume', ['body' => ['id' => $automationId]]);
check($response['code'] === 200 && $response['payload']['automation']['status'] === 'active', 'resumeする');

$beforeSchedules = (int) Db::one('SELECT COUNT(*) AS n FROM send_schedule')['n'];
$response = automationCall('campaign_automations_handle_generate_now', ['body' => ['id' => $automationId]]);
check($response['code'] === 201, 'generate_nowは201');
$draftId = (int) ($response['payload']['campaign']['id'] ?? 0);
check($draftId > 0, '生成draft IDを返す');
$draft = Db::one('SELECT status FROM campaigns WHERE id = ?', [$draftId]);
check($draft !== null && $draft['status'] === 'draft', '生成campaignはdraft');
$run = Db::one('SELECT * FROM campaign_automation_runs WHERE generated_campaign_id = ?', [$draftId]);
check($run !== null && $run['status'] === 'generated', 'runをgeneratedで記録する');
check((int) Db::one('SELECT COUNT(*) AS n FROM send_schedule')['n'] === $beforeSchedules, 'send_scheduleを作らない');
$response = automationCall('campaign_automations_handle_list', ['role' => 'viewer']);
$listedAutomation = $response['payload']['automations'][0] ?? [];
check(($listedAutomation['last_run_status'] ?? null) === 'generated', 'listで最終run結果を返す');
check((int) ($listedAutomation['last_generated_campaign_id'] ?? 0) === $draftId, 'listで最終draft IDを返す');
$auditJson = json_encode($GLOBALS['__TET2_TEST_AUDIT'], JSON_UNESCAPED_UNICODE);
check(!str_contains((string) $auditJson, '@example.test'), 'auditへPIIを記録しない');

$beforeDuplicate = (int) Db::one('SELECT COUNT(*) AS n FROM campaigns')['n'];
$response = automationCall('campaign_automations_handle_generate_now', ['body' => ['id' => $automationId]]);
check($response['code'] === 409, '同一occurrenceの再生成は409');
check((int) Db::one('SELECT COUNT(*) AS n FROM campaigns')['n'] === $beforeDuplicate, '重複draftを作らない');

Db::run('UPDATE campaign_automations SET max_occurrences = 1 WHERE id = ?', [$automationId]);
$response = automationCall('campaign_automations_handle_pause', ['body' => ['id' => $automationId]]);
check($response['code'] === 200, '完了済みruleをpauseできる');
$response = automationCall('campaign_automations_handle_resume', ['body' => ['id' => $automationId]]);
check($response['code'] === 409, '上限回数を完了したruleはresumeを拒否する');

check(function_exists('campaign_automations_handle_delete'), '定期ルール削除handlerがある');
check(campaign_automations_required_role('delete', 'POST') === 'operator', '削除はoperator以上');
$response = automationCall('campaign_automations_handle_delete', [
    'body' => ['id' => $automationId],
    'actor' => ['id' => 2, 'tenant_id' => 2, 'role' => 'operator'],
]);
check($response['code'] === 404, '他tenantからルールを削除できない');
$response = automationCall('campaign_automations_handle_delete', ['body' => ['id' => 0]]);
check($response['code'] === 400, '削除idを検証する');
Db::run("UPDATE campaigns SET deleted_at=datetime('now') WHERE id=?", [$sourceId]);
$staleRule = Db::one('SELECT * FROM campaign_automations WHERE id=?', [$automationId]);
// 別接続のrunnerがclaimを書き込んでいる間はDELETEを割り込ませない。
$runnerConnection = new PDO('sqlite:' . getenv('TET2_DB_PATH'), null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$runnerConnection->exec('PRAGMA foreign_keys=ON');
$runnerConnection->beginTransaction();
$claim = $runnerConnection->prepare("INSERT INTO campaign_automation_runs (automation_id, occurrence_key, selected_send_at, status) VALUES (?, 'race-test', '2030-01-01 09:00:00', 'claimed')");
$claim->execute([$automationId]);
Db::pdo()->exec('PRAGMA busy_timeout=1');
$locked = false;
try {
    automationCall('campaign_automations_handle_delete', ['body' => ['id' => $automationId]]);
} catch (PDOException $error) {
    $locked = (int) ($error->errorInfo[1] ?? 0) === 5;
} finally {
    $runnerConnection->rollBack();
    Db::pdo()->exec('PRAGMA busy_timeout=5000');
}
check($locked && Db::one('SELECT id FROM campaign_automations WHERE id=?', [$automationId]) !== null, '生成transaction中は削除が部分的に進まない');
$campaignsBeforeDelete = Db::all('SELECT * FROM campaigns ORDER BY id');
$schedulesBeforeDelete = Db::all('SELECT * FROM send_schedule ORDER BY id');
$eventsBeforeDelete = Db::all('SELECT * FROM events ORDER BY id');
$response = automationCall('campaign_automations_handle_delete', ['body' => ['id' => $automationId]]);
check($response['code'] === 200, '削除済sourceのルールも削除できる');
check($GLOBALS['__TET2_TEST_CSRF_CALLS'] === 1, '削除でCSRFを検証する');
check(Db::one('SELECT id FROM campaign_automations WHERE id=?', [$automationId]) === null, 'ルールを物理削除する');
check(Db::all('SELECT * FROM campaign_automation_groups WHERE automation_id=?', [$automationId]) === [], '対象group対応を削除する');
check(Db::all('SELECT * FROM campaign_automation_runs WHERE automation_id=?', [$automationId]) === [], '生成履歴を削除する');
check(Db::all('SELECT * FROM campaigns ORDER BY id') === $campaignsBeforeDelete, '生成済campaignを全項目保持する');
check(Db::all('SELECT * FROM send_schedule ORDER BY id') === $schedulesBeforeDelete, '送信scheduleを保持する');
check(Db::all('SELECT * FROM events ORDER BY id') === $eventsBeforeDelete, '訓練eventを保持する');
check(($GLOBALS['__TET2_TEST_AUDIT'][0]['action'] ?? '') === 'campaign_automation.delete', '削除を監査記録する');
$response = automationCall('campaign_automations_handle_delete', ['body' => ['id' => $automationId]]);
check($response['code'] === 404, '削除済ruleの再削除は404');
$result = (new CampaignAutomationRunner())->generateOne($automationId, ['tenant_id' => 1]);
check($result['status'] === 'not_found', '削除済ruleから生成できない');
$occurrence = (new CampaignAutomationSchedule())->next($staleRule);
$occurrence['selected_send_at'] = $occurrence['send_window_start_at'];
$staleRejected = false;
try {
    (new ReflectionMethod(CampaignAutomationRunner::class, 'generateAtomic'))->invoke(
        new CampaignAutomationRunner(), $staleRule,
        ['occurrence' => $occurrence, 'target_ids' => [1], 'content_no_by_target' => null, 'created_by' => 1]
    );
} catch (PDOException $error) {
    $staleRejected = (int) ($error->errorInfo[1] ?? 0) === 19;
}
check($staleRejected, '削除直前にruleを読んだrunnerもFKで生成を拒否される');
check(Db::all('SELECT * FROM campaigns ORDER BY id') === $campaignsBeforeDelete, '競合後に孤立draftが残らない');
check(Db::all('PRAGMA foreign_key_check') === [], '削除後も参照整合性を維持する');

echo "ALL TESTS PASSED\n";
