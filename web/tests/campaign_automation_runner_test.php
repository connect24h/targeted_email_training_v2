<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

tet2_test_boot();
require_once __DIR__ . '/../lib/CampaignAutomationSchedule.php';
require_once __DIR__ . '/../lib/CampaignDraftFactory.php';
require_once __DIR__ . '/../lib/CampaignAutomationRunner.php';

function runnerTemplate(string $kind, string $name, ?int $authFlag = null): int
{
    return Db::insert(
        'INSERT INTO templates (tenant_id, kind, name, content, auth_flag) VALUES (?, ?, ?, ?, ?)',
        [1, $kind, $name, $name . ' content', $authFlag]
    );
}

function runnerSource(int $subjectId, int $bodyId, int $phishId, int $contentCount = 1): int
{
    $id = Db::insert(
        'INSERT INTO campaigns
         (tenant_id, name, status, subject_template_id, body_template_id, phish_template_id,
          from_address, link_mode, send_mode, content_delivery, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [1, 'Runner Source', 'done', $subjectId, $bodyId, $phishId,
         'runner@example.test', 'link', 'normal', 'distribute', 1]
    );
    for ($contentNo = 1; $contentNo <= $contentCount; $contentNo++) {
        Db::run(
            'INSERT INTO campaign_contents
             (campaign_id, content_no, subject_template_id, body_template_id, phish_template_id, link_mode)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$id, $contentNo, $subjectId, $bodyId, $phishId, 'link']
        );
    }
    return $id;
}

function runnerRule(int $sourceId, int $groupId, array $overrides = []): int
{
    $data = array_merge([
        'name' => 'Runner Rule',
        'status' => 'active',
        'next_due_at' => '2026-08-09 11:00:00',
        'assignment_mode' => 'static',
        'max_occurrences' => null,
    ], $overrides);
    $id = Db::insert(
        'INSERT INTO campaign_automations
         (tenant_id, name, source_campaign_id, frequency, day_of_month, generation_lead_days,
          time_mode, send_window_start, next_due_at, assignment_mode, max_occurrences, status, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [1, $data['name'], $sourceId, 'monthly', 15, 5, 'fixed', '09:30',
         $data['next_due_at'], $data['assignment_mode'], $data['max_occurrences'], $data['status'], 1]
    );
    Db::run(
        'INSERT INTO campaign_automation_groups (automation_id, group_id) VALUES (?, ?)',
        [$id, $groupId]
    );
    return $id;
}

$subjectId = runnerTemplate('subject', 'Runner Subject');
$bodyId = runnerTemplate('body', 'Runner Body');
$phishId = runnerTemplate('phish_login', 'Runner Phish', 0);
$sourceId = runnerSource($subjectId, $bodyId, $phishId);
$incompleteSourceId = Db::insert(
    'INSERT INTO campaigns (tenant_id, name, status, created_by) VALUES (?, ?, ?, ?)',
    [1, 'Runner Incomplete Source', 'done', 1]
);
$validGroup = Db::insert('INSERT INTO groups (tenant_id, name, kind) VALUES (?, ?, ?)', [1, 'Runner Valid', 'custom']);
Db::run('INSERT INTO target_group (target_id, group_id) VALUES (?, ?)', [1, $validGroup]);
$emptyGroup = Db::insert('INSERT INTO groups (tenant_id, name, kind) VALUES (?, ?, ?)', [1, 'Runner Empty', 'custom']);

$failedRuleId = runnerRule($sourceId, $emptyGroup, ['name' => 'Runner Failure']);
$incompleteRuleId = runnerRule($incompleteSourceId, $validGroup, ['name' => 'Runner Incomplete']);
$validRuleId = runnerRule($sourceId, $validGroup, ['name' => 'Runner Success']);
$futureRuleId = runnerRule($sourceId, $validGroup, ['next_due_at' => '2026-08-10 11:00:00']);
$pausedRuleId = runnerRule($sourceId, $validGroup, ['status' => 'paused']);

$timezone = new DateTimeZone('Asia/Tokyo');
$now = new DateTimeImmutable('2026-08-09 12:00:00', $timezone);
$runner = new CampaignAutomationRunner(new CampaignAutomationSchedule($timezone));

echo "=== CampaignAutomationRunner ===\n";
check($runner->dueCount($now) === 3, 'activeかつdueのruleだけ数える');
$beforeSchedules = (int) Db::one('SELECT COUNT(*) AS n FROM send_schedule')['n'];
$beforeCampaigns = (int) Db::one('SELECT COUNT(*) AS n FROM campaigns')['n'];
$result = $runner->runDue($now);
check($result === ['examined' => 3, 'generated' => 1, 'failed' => 2, 'duplicate' => 0], '失敗後も次ruleを処理する');
check((int) Db::one('SELECT COUNT(*) AS n FROM campaigns')['n'] === $beforeCampaigns + 1, 'Factory失敗時に部分campaignを残さない');

$validRun = Db::one('SELECT * FROM campaign_automation_runs WHERE automation_id = ?', [$validRuleId]);
check($validRun !== null && $validRun['status'] === 'generated', '成功runをgeneratedで保存する');
$draftId = (int) ($validRun['generated_campaign_id'] ?? 0);
$draft = Db::one('SELECT status FROM campaigns WHERE id = ?', [$draftId]);
check($draft !== null && $draft['status'] === 'draft', 'runnerはdraftだけ生成する');

$failedRun = Db::one('SELECT * FROM campaign_automation_runs WHERE automation_id = ?', [$failedRuleId]);
check($failedRun !== null && $failedRun['status'] === 'failed', 'zero targetをfailed runとして保存する');
check($failedRun['error_code'] === 'zero_targets', '失敗理由はPIIなしcodeだけ保存する');
$incompleteRun = Db::one('SELECT * FROM campaign_automation_runs WHERE automation_id = ?', [$incompleteRuleId]);
check($incompleteRun !== null && $incompleteRun['error_code'] === 'draft_validation', '不完全sourceをfailed runで記録する');

$validRule = Db::one('SELECT next_due_at FROM campaign_automations WHERE id = ?', [$validRuleId]);
$failedRule = Db::one('SELECT next_due_at FROM campaign_automations WHERE id = ?', [$failedRuleId]);
check($validRule['next_due_at'] > '2026-08-09 12:00:00', '成功ruleを次回へ進める');
check($failedRule['next_due_at'] > '2026-08-09 12:00:00', '失敗ruleも次回へ進める');
check(Db::one('SELECT 1 FROM campaign_automation_runs WHERE automation_id = ?', [$futureRuleId]) === null, 'future ruleを処理しない');
check(Db::one('SELECT 1 FROM campaign_automation_runs WHERE automation_id = ?', [$pausedRuleId]) === null, 'paused ruleを処理しない');
check((int) Db::one('SELECT COUNT(*) AS n FROM send_schedule')['n'] === $beforeSchedules, 'send_scheduleを作らない');

$beforeDrafts = (int) Db::one('SELECT COUNT(*) AS n FROM campaigns')['n'];
$duplicate = $runner->generateOne($validRuleId, ['tenant_id' => 1, 'created_by' => 1, 'now' => $now]);
check($duplicate['status'] === 'duplicate', '同一occurrenceの再実行をduplicate扱いにする');
check((int) Db::one('SELECT COUNT(*) AS n FROM campaigns')['n'] === $beforeDrafts, 'duplicate draftを作らない');

$again = $runner->runDue($now);
check($again['examined'] === 0, '次回更新後の同時刻再実行はno-op');
$audit = Db::all("SELECT detail FROM audit_log WHERE action LIKE 'campaign_automation.runner.%'");
check(!str_contains(json_encode($audit), '@example.test'), 'runner auditへPIIを記録しない');

$rotationSourceId = runnerSource($subjectId, $bodyId, $phishId, 3);
$rotationGroup = Db::insert(
    'INSERT INTO groups (tenant_id, name, kind) VALUES (?, ?, ?)',
    [1, 'Runner Rotation', 'custom']
);
Db::run('INSERT INTO target_group (target_id, group_id) VALUES (?, ?)', [1, $rotationGroup]);
Db::run('INSERT INTO target_group (target_id, group_id) VALUES (?, ?)', [2, $rotationGroup]);
$rotationRuleId = runnerRule($rotationSourceId, $rotationGroup, [
    'name' => 'Runner Rotation',
    'assignment_mode' => 'rotate',
    'max_occurrences' => 2,
]);
$firstRotation = $runner->generateOne($rotationRuleId, ['now' => $now]);
check($firstRotation['status'] === 'generated', 'rotation初回draftを生成する');
$firstAssignments = Db::all(
    'SELECT target_id, content_no FROM campaign_targets WHERE campaign_id=? ORDER BY target_id',
    [(int) $firstRotation['campaign_id']]
);
$secondNow = new DateTimeImmutable('2026-09-10 12:00:00', $timezone);
$secondRotation = $runner->generateOne($rotationRuleId, ['now' => $secondNow]);
check($secondRotation['status'] === 'generated', 'rotation2回目draftを生成する');
$secondAssignments = Db::all(
    'SELECT target_id, content_no FROM campaign_targets WHERE campaign_id=? ORDER BY target_id',
    [(int) $secondRotation['campaign_id']]
);
foreach ($firstAssignments as $index => $firstAssignment) {
    check(
        $firstAssignment['target_id'] === $secondAssignments[$index]['target_id']
        && $firstAssignment['content_no'] !== $secondAssignments[$index]['content_no'],
        '同じ従業員へ前回と異なるcontentを割り当てる'
    );
}
$rotationRule = Db::one('SELECT status FROM campaign_automations WHERE id=?', [$rotationRuleId]);
check(($rotationRule['status'] ?? null) === 'paused', '上限回数でrotation ruleを自動pauseする');
$thirdRotation = $runner->generateOne($rotationRuleId, [
    'now' => new DateTimeImmutable('2026-10-10 12:00:00', $timezone),
]);
check($thirdRotation['status'] === 'skipped', '完了済みrotation ruleは追加生成しない');

$invalidRotationRuleId = runnerRule($sourceId, $validGroup, [
    'name' => 'Runner Invalid Rotation',
    'assignment_mode' => 'rotate',
    'max_occurrences' => 1,
]);
$invalidRotation = $runner->generateOne($invalidRotationRuleId, ['now' => $now]);
check($invalidRotation['status'] === 'failed', '実行時に壊れたrotation sourceをfailed扱いにする');
$invalidRotationRun = Db::one(
    'SELECT status, error_code FROM campaign_automation_runs WHERE automation_id=?',
    [$invalidRotationRuleId]
);
check(($invalidRotationRun['error_code'] ?? null) === 'draft_validation', 'rotation source不備を記録する');
$invalidRotationRule = Db::one('SELECT next_due_at FROM campaign_automations WHERE id=?', [$invalidRotationRuleId]);
check($invalidRotationRule['next_due_at'] > '2026-08-09 12:00:00', '壊れたrotation ruleも次回へ進める');

echo "ALL TESTS PASSED\n";
