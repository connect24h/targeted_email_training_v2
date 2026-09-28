<?php
declare(strict_types=1);

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/CampaignAutomationSchedule.php';
require_once __DIR__ . '/CampaignDraftFactory.php';
require_once __DIR__ . '/TenantStatus.php';

/** due automationを冪等にclaimし、draftだけを生成する。 */
final class CampaignAutomationRunner
{
    public function __construct(
        private CampaignAutomationSchedule $schedule = new CampaignAutomationSchedule(),
        private CampaignDraftFactory $draftFactory = new CampaignDraftFactory()
    ) {
    }

    public function dueCount(?DateTimeImmutable $now = null): int
    {
        $now = $now ?? new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo'));
        $row = Db::one(
            "SELECT COUNT(*) AS n FROM campaign_automations WHERE status='active' AND next_due_at <= ? AND "
            . TenantStatus::operationalSql('tenant_id'),
            [$now->format('Y-m-d H:i:s')]
        );
        return (int) ($row['n'] ?? 0);
    }

    /** @return array{examined:int,generated:int,failed:int,duplicate:int} */
    public function runDue(?DateTimeImmutable $now = null): array
    {
        $now = $now ?? new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo'));
        $rows = Db::all(
            // 停止中・削除済みのテナントの定期キャンペーンは作らない(予定の日時も進めない。有効に戻すと次の実行で作る)。
            "SELECT id FROM campaign_automations
             WHERE status='active' AND next_due_at <= ? AND " . TenantStatus::operationalSql('tenant_id') . "
             ORDER BY next_due_at, id",
            [$now->format('Y-m-d H:i:s')]
        );
        $result = ['examined' => count($rows), 'generated' => 0, 'failed' => 0, 'duplicate' => 0];
        foreach ($rows as $row) {
            try {
                $run = $this->generateOne((int) $row['id'], ['now' => $now]);
                $status = (string) ($run['status'] ?? 'failed');
                $key = in_array($status, ['generated', 'failed', 'duplicate'], true) ? $status : 'failed';
                $result[$key]++;
            } catch (Throwable) {
                $result['failed']++;
            }
        }
        return $result;
    }

    /**
     * @param array{tenant_id?:int,created_by?:int|null,now?:DateTimeImmutable} $options
     * @return array{status:string,run_id?:int,campaign_id?:int,error_code?:string}
     */
    public function generateOne(int $automationId, array $options = []): array
    {
        $rule = $this->findRule($automationId, $options['tenant_id'] ?? null);
        if ($rule === null) {
            return ['status' => 'not_found'];
        }
        if ($rule['status'] !== 'active') {
            return ['status' => 'skipped'];
        }
        if (!TenantStatus::isOperational((int) $rule['tenant_id'])) {
            return ['status' => 'skipped', 'error_code' => 'tenant_inactive'];
        }
        $now = $options['now'] ?? new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo'));
        if (!$now instanceof DateTimeImmutable) {
            throw new InvalidArgumentException('nowが不正です');
        }
        $occurrence = $this->schedule->next($rule, $now);
        $occurrence['selected_send_at'] = $this->schedule->selectSendAt($occurrence);
        $targets = $this->targetIds((int) $rule['id'], (int) $rule['tenant_id']);
        if ($targets === []) {
            return $this->recordFailure($rule, $occurrence, 'zero_targets');
        }
        try {
            $assignments = $this->rotationAssignments($rule, $targets);
        } catch (Throwable $error) {
            return $this->recordFailure($rule, $occurrence, $this->errorCode($error));
        }
        $createdBy = array_key_exists('created_by', $options)
            ? $options['created_by']
            : ($rule['created_by'] !== null ? (int) $rule['created_by'] : null);
        return $this->generateAtomic($rule, [
            'occurrence' => $occurrence,
            'target_ids' => $targets,
            'content_no_by_target' => $assignments,
            'created_by' => $createdBy,
        ]);
    }

    private function findRule(int $automationId, mixed $tenantId): ?array
    {
        if ($tenantId === null) {
            return Db::one('SELECT * FROM campaign_automations WHERE id = ?', [$automationId]);
        }
        if (!is_int($tenantId) || $tenantId < 1) {
            throw new InvalidArgumentException('tenant_idが不正です');
        }
        return Db::one(
            'SELECT * FROM campaign_automations WHERE id = ? AND tenant_id = ?',
            [$automationId, $tenantId]
        );
    }

    /** @return array<int, int> */
    private function targetIds(int $automationId, int $tenantId): array
    {
        $rows = Db::all(
            "SELECT DISTINCT t.id FROM campaign_automation_groups cag
             JOIN target_group tg ON tg.group_id = cag.group_id
             JOIN targets t ON t.id = tg.target_id
             WHERE cag.automation_id=? AND t.tenant_id=? AND t.status='active' ORDER BY t.id",
            [$automationId, $tenantId]
        );
        return array_map(static fn(array $row): int => (int) $row['id'], $rows);
    }

    /** @param array<int, int> $targetIds @return array<int, int>|null */
    private function rotationAssignments(array $rule, array $targetIds): ?array
    {
        if (($rule['assignment_mode'] ?? 'static') !== 'rotate') {
            return null;
        }
        $contents = Db::all(
            "SELECT cc.content_no FROM campaign_contents cc
             JOIN campaigns c ON c.id=cc.campaign_id
             WHERE cc.campaign_id=? AND c.tenant_id=? AND c.deleted_at IS NULL
               AND c.content_delivery='distribute'
             ORDER BY cc.content_no",
            [(int) $rule['source_campaign_id'], (int) $rule['tenant_id']]
        );
        if (count($contents) < 2) {
            throw new CampaignDraftValidationException('rotationには2件以上のコンテンツが必要です');
        }
        $generated = Db::one(
            "SELECT COUNT(*) AS n FROM campaign_automation_runs
             WHERE automation_id=? AND status='generated'",
            [(int) $rule['id']]
        );
        $step = (int) ($generated['n'] ?? 0);
        $contentNos = array_map(static fn(array $row): int => (int) $row['content_no'], $contents);
        $assignments = [];
        foreach ($targetIds as $targetId) {
            $index = ((int) $rule['id'] + $targetId + $step) % count($contentNos);
            $assignments[$targetId] = $contentNos[$index];
        }
        return $assignments;
    }

    private function generateAtomic(array $rule, array $context): array
    {
        try {
            return Db::tx(function () use ($rule, $context): array {
                $occurrence = $context['occurrence'];
                $runId = $this->insertRun((int) $rule['id'], $occurrence, ['status' => 'claimed']);
                $factoryOptions = [
                    'created_by' => $context['created_by'],
                    'target_ids' => $context['target_ids'],
                    'name' => mb_substr($rule['name'] . ' ' . $occurrence['occurrence_key'], 0, 200),
                ];
                if ($context['content_no_by_target'] !== null) {
                    $factoryOptions['content_no_by_target'] = $context['content_no_by_target'];
                }
                $draftId = $this->draftFactory->createFromSource(
                    (int) $rule['source_campaign_id'],
                    (int) $rule['tenant_id'],
                    $factoryOptions
                );
                Db::run(
                    "UPDATE campaign_automation_runs SET status='generated', generated_campaign_id=?,
                     finished_at=datetime('now','localtime') WHERE id=?",
                    [$draftId, $runId]
                );
                $this->advanceRule($rule, $occurrence);
                $this->audit($rule, 'generated', "run_id={$runId},campaign_id={$draftId}");
                return ['status' => 'generated', 'run_id' => $runId, 'campaign_id' => $draftId];
            });
        } catch (Throwable $error) {
            if ($this->isRunDuplicate($error)) {
                $this->advanceRule($rule, $context['occurrence']);
                return ['status' => 'duplicate'];
            }
            return $this->recordFailure($rule, $context['occurrence'], $this->errorCode($error));
        }
    }

    private function recordFailure(array $rule, array $occurrence, string $errorCode): array
    {
        try {
            return Db::tx(function () use ($rule, $occurrence, $errorCode): array {
                $runId = $this->insertRun(
                    (int) $rule['id'],
                    $occurrence,
                    ['status' => 'failed', 'error_code' => $errorCode]
                );
                $this->advanceRule($rule, $occurrence);
                $this->audit($rule, 'failed', "run_id={$runId},error_code={$errorCode}");
                return ['status' => 'failed', 'run_id' => $runId, 'error_code' => $errorCode];
            });
        } catch (Throwable $error) {
            if ($this->isRunDuplicate($error)) {
                $this->advanceRule($rule, $occurrence);
                return ['status' => 'duplicate'];
            }
            throw $error;
        }
    }

    /** @param array{status:string,error_code?:string} $state */
    private function insertRun(int $automationId, array $occurrence, array $state): int
    {
        $status = $state['status'];
        $finishedAt = $status === 'claimed'
            ? null
            : (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d H:i:s');
        return Db::insert(
            'INSERT INTO campaign_automation_runs
             (automation_id, occurrence_key, selected_send_at, status, error_code, finished_at)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$automationId, $occurrence['occurrence_key'], $occurrence['selected_send_at'], $status,
             $state['error_code'] ?? null, $finishedAt]
        );
    }

    private function advanceRule(array $rule, array $occurrence): void
    {
        $after = new DateTimeImmutable($occurrence['send_window_end_at'], new DateTimeZone('Asia/Tokyo'));
        $next = $this->schedule->next($rule, $after->modify('+1 second'));
        $status = $this->hasReachedMaxOccurrences($rule) ? 'paused' : 'active';
        Db::run(
            "UPDATE campaign_automations SET next_due_at=?, status=?,
             updated_at=datetime('now','localtime') WHERE id=?",
            [$next['next_due_at'], $status, $rule['id']]
        );
    }

    private function hasReachedMaxOccurrences(array $rule): bool
    {
        if ($rule['max_occurrences'] === null) {
            return false;
        }
        $row = Db::one(
            "SELECT COUNT(*) AS n FROM campaign_automation_runs
             WHERE automation_id=? AND status='generated'",
            [(int) $rule['id']]
        );
        return (int) ($row['n'] ?? 0) >= (int) $rule['max_occurrences'];
    }

    private function errorCode(Throwable $error): string
    {
        return match (true) {
            $error instanceof CampaignDraftNotFoundException => 'source_not_found',
            $error instanceof CampaignDraftValidationException => 'draft_validation',
            default => 'generation_failed',
        };
    }

    private function isRunDuplicate(Throwable $error): bool
    {
        return $error instanceof PDOException
            && str_contains($error->getMessage(), 'campaign_automation_runs.automation_id');
    }

    private function audit(array $rule, string $result, string $detail): void
    {
        Db::run(
            'INSERT INTO audit_log (tenant_id, user_id, action, detail, ip) VALUES (?, ?, ?, ?, ?)',
            [(int) $rule['tenant_id'], $rule['created_by'], 'campaign_automation.runner.' . $result,
             'automation_id=' . $rule['id'] . ',' . $detail, '']
        );
    }
}
