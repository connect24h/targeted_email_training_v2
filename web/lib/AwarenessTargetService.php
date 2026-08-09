<?php
declare(strict_types=1);

final class IntegrationValidationException extends InvalidArgumentException {}
final class IntegrationConflictException extends RuntimeException {}
final class IntegrationNotFoundException extends RuntimeException {}

final class AwarenessTargetService
{
    private const STATUSES = ['active', 'suspended', 'archived'];
    private const POSITION_CATEGORIES = ['役員', '管理職', '一般従業員'];

    public function __construct(private readonly int $tenantId)
    {
        if ($tenantId < 1) {
            throw new IntegrationValidationException('tenantが不正です');
        }
    }

    /** @return array{targets:list<array<string,mixed>>,boundary:int,nextCursor:?int} */
    public function snapshot(int $cursor = 0, int $limit = 100, ?int $boundary = null): array
    {
        if ($cursor < 0 || $limit < 1 || $limit > 500 || ($boundary !== null && $boundary < 0)) {
            throw new IntegrationValidationException('cursorまたはlimitが不正です');
        }
        $boundary ??= (int) (Db::one(
            'SELECT COALESCE(MAX(id),0) AS id FROM targets WHERE tenant_id=? AND is_test=0',
            [$this->tenantId]
        )['id'] ?? 0);
        $rows = Db::all(
            'SELECT id,email,name,company,department,title,position_category,status,archived_at
             FROM targets WHERE tenant_id=? AND is_test=0 AND id>? AND id<=? ORDER BY id LIMIT ?',
            [$this->tenantId, $cursor, $boundary, $limit + 1]
        );
        $hasMore = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $targets = array_map(fn(array $row): array => $this->mapTarget($row), $rows);
        $next = $hasMore && $rows !== [] ? (int) end($rows)['id'] : null;
        return ['targets' => $targets, 'boundary' => $boundary, 'nextCursor' => $next];
    }

    /** @return array{groups:list<array<string,mixed>>} */
    public function groupSnapshot(): array
    {
        $rows = Db::all(
            'SELECT id,name,kind,status,archived_at FROM groups WHERE tenant_id=? ORDER BY id',
            [$this->tenantId]
        );
        return ['groups' => array_map(fn(array $row): array => $this->mapGroup($row), $rows)];
    }

    /** @param array<string,mixed> $input @return array{created:bool,group:array<string,mixed>} */
    public function upsertGroup(array $input, string $idempotencyKey): array
    {
        $request = $this->validateGroup($input);
        return $this->idempotent('group.upsert', $idempotencyKey, $request, function () use ($request): array {
            $group = isset($request['groupId'])
                ? $this->ownedGroup((int) $request['groupId'])
                : Db::one('SELECT * FROM groups WHERE tenant_id=? AND name=?', [$this->tenantId, $request['name']]);
            $created = $group === null;
            $groupId = $created
                ? Db::insert('INSERT INTO groups (tenant_id,name,kind) VALUES (?,?,?)',
                    [$this->tenantId, $request['name'], $request['kind']])
                : (int) $group['id'];
            if (!$created) {
                Db::run("UPDATE groups SET name=?,kind=?,status='active',archived_at=NULL WHERE id=? AND tenant_id=?",
                    [$request['name'], $request['kind'], $groupId, $this->tenantId]);
            }
            return ['created' => $created, 'group' => $this->mapGroup($this->ownedGroup($groupId))];
        });
    }

    /** @return array{group:array<string,mixed>} */
    public function archiveGroup(int $groupId, string $idempotencyKey): array
    {
        if ($groupId < 1) throw new IntegrationValidationException('groupIdが不正です');
        return $this->idempotent('group.archive', $idempotencyKey, ['groupId' => $groupId], function () use ($groupId): array {
            $this->ownedGroup($groupId);
            Db::run("UPDATE groups SET status='archived',archived_at=datetime('now','localtime')
                WHERE id=? AND tenant_id=?", [$groupId, $this->tenantId]);
            return ['group' => $this->mapGroup($this->ownedGroup($groupId))];
        });
    }

    /** @param array<string,mixed> $input @return array{created:bool,target:array<string,mixed>} */
    public function upsert(array $input, string $idempotencyKey): array
    {
        $request = $this->validateUpsert($input);
        return $this->idempotent('target.upsert', $idempotencyKey, $request, function () use ($request): array {
            $target = $this->findTarget($request);
            $created = $target === null;
            $targetId = $created ? $this->createTarget($request) : (int) $target['id'];
            if (!$created) {
                $this->updateTarget($targetId, $request);
            }
            if (array_key_exists('groupIds', $request)) {
                $this->replaceGroups($targetId, $request['groupIds']);
            }
            return ['created' => $created, 'target' => $this->mapTarget($this->ownedTarget($targetId))];
        });
    }

    /** @return array{target:array<string,mixed>} */
    public function archive(int $targetId, string $idempotencyKey): array
    {
        if ($targetId < 1) {
            throw new IntegrationValidationException('targetIdが不正です');
        }
        return $this->idempotent('target.archive', $idempotencyKey, ['targetId' => $targetId], function () use ($targetId): array {
            $this->ownedTarget($targetId);
            Db::run("UPDATE targets
                SET status='archived',archived_at=COALESCE(archived_at,datetime('now','localtime'))
                WHERE id=? AND tenant_id=?", [$targetId, $this->tenantId]);
            return ['target' => $this->mapTarget($this->ownedTarget($targetId))];
        });
    }

    /** @return array{results:list<array<string,mixed>>,nextCursor:?int} */
    public function phishingResults(int $cursor = 0, int $limit = 100): array
    {
        if ($cursor < 0 || $limit < 1 || $limit > 500) {
            throw new IntegrationValidationException('cursorまたはlimitが不正です');
        }
        $rows = Db::all($this->resultSql(), [$this->tenantId, $cursor, $limit + 1]);
        $hasMore = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $results = array_map(fn(array $row): array => $this->mapResult($row), $rows);
        $next = $hasMore && $rows !== [] ? (int) end($rows)['campaign_target_id'] : null;
        return ['results' => $results, 'nextCursor' => $next];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function validateUpsert(array $input): array
    {
        $email = strtolower(trim((string) ($input['email'] ?? '')));
        $name = trim((string) ($input['name'] ?? ''));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || $name === '') {
            throw new IntegrationValidationException('emailまたはnameが不正です');
        }
        $result = ['email' => $email, 'name' => $this->safeText($name, 'name')];
        foreach (['company', 'department', 'title'] as $field) {
            if (array_key_exists($field, $input)) {
                $value = $input[$field] === null ? null : trim((string) $input[$field]);
                $result[$field] = $value === '' ? null : $this->safeText($value, $field);
            }
        }
        $this->appendValidatedFields($result, $input);
        return $result;
    }

    /** @param array<string,mixed> $result @param array<string,mixed> $input */
    private function appendValidatedFields(array &$result, array $input): void
    {
        if (isset($input['targetId'])) {
            if (!is_int($input['targetId']) || $input['targetId'] < 1) {
                throw new IntegrationValidationException('targetIdが不正です');
            }
            $result['targetId'] = $input['targetId'];
        }
        if (array_key_exists('positionCategory', $input)) {
            $position = $input['positionCategory'];
            if ($position !== null) {
                if (!is_string($position)) {
                    throw new IntegrationValidationException('positionCategoryが不正です');
                }
                $position = tet2_normalize_position_category($position);
                if ($position === null || !in_array($position, self::POSITION_CATEGORIES, true)) {
                    throw new IntegrationValidationException('positionCategoryが不正です');
                }
            }
            $result['positionCategory'] = $position;
        }
        if (array_key_exists('status', $input)) {
            if (!is_string($input['status']) || !in_array($input['status'], self::STATUSES, true)) {
                throw new IntegrationValidationException('statusが不正です');
            }
            $result['status'] = $input['status'];
        }
        if (array_key_exists('groupIds', $input)) {
            $result['groupIds'] = $this->validateGroups($input['groupIds']);
        }
    }

    /** @return list<int> */
    private function validateGroups(mixed $groupIds): array
    {
        if (!is_array($groupIds)) {
            throw new IntegrationValidationException('groupIdsが不正です');
        }
        $ids = [];
        foreach ($groupIds as $id) {
            $owned = is_int($id) && $id > 0 && Db::one(
                "SELECT id FROM groups WHERE id=? AND tenant_id=? AND status='active'",
                [$id, $this->tenantId]
            ) !== null;
            if (!$owned) {
                throw new IntegrationValidationException('groupIdsが不正です');
            }
            $ids[] = $id;
        }
        return array_values(array_unique($ids));
    }

    private function safeText(string $value, string $field): string
    {
        if (mb_strlen($value) > 255 || strpbrk($value, '<>') !== false
            || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new IntegrationValidationException("{$field}が不正です");
        }
        return $value;
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function validateGroup(array $input): array
    {
        $name = trim((string) ($input['name'] ?? ''));
        $kind = $input['kind'] ?? 'custom';
        if ($name === '' || !is_string($kind) || !in_array($kind, ['department', 'custom'], true)) {
            throw new IntegrationValidationException('groupが不正です');
        }
        $result = ['name' => $this->safeText($name, 'name'), 'kind' => $kind];
        if (isset($input['groupId'])) {
            if (!is_int($input['groupId']) || $input['groupId'] < 1) {
                throw new IntegrationValidationException('groupIdが不正です');
            }
            $result['groupId'] = $input['groupId'];
        }
        return $result;
    }

    /** @param array<string,mixed> $request */
    private function findTarget(array $request): ?array
    {
        if (isset($request['targetId'])) {
            return $this->ownedTarget((int) $request['targetId']);
        }
        return Db::one(
            'SELECT * FROM targets WHERE tenant_id=? AND lower(email)=lower(?)',
            [$this->tenantId, $request['email']]
        );
    }

    /** @param array<string,mixed> $request */
    private function createTarget(array $request): int
    {
        return Db::insert(
            "INSERT INTO targets
                (tenant_id,email,name,company,department,title,position_category,status,archived_at,tenant_no)
             VALUES (?,?,?,?,?,?,?,?,CASE WHEN ?='archived' THEN datetime('now','localtime') ELSE NULL END,
                (SELECT COALESCE(MAX(tenant_no),0)+1 FROM targets WHERE tenant_id=?))",
            [$this->tenantId, $request['email'], $request['name'], $request['company'] ?? null,
             $request['department'] ?? null, $request['title'] ?? null, $request['positionCategory'] ?? null,
             $request['status'] ?? 'active', $request['status'] ?? 'active', $this->tenantId]
        );
    }

    /** @param array<string,mixed> $request */
    private function updateTarget(int $targetId, array $request): void
    {
        $columns = ['email' => 'email', 'name' => 'name', 'company' => 'company',
            'department' => 'department', 'title' => 'title', 'positionCategory' => 'position_category',
            'status' => 'status'];
        $sets = [];
        $params = [];
        foreach ($columns as $key => $column) {
            if (array_key_exists($key, $request)) {
                $sets[] = "{$column}=?";
                $params[] = $request[$key];
            }
        }
        if (array_key_exists('status', $request)) {
            $sets[] = "archived_at=CASE
                WHEN ?='archived' THEN COALESCE(archived_at,datetime('now','localtime'))
                ELSE NULL END";
            $params[] = $request['status'];
        }
        $params[] = $targetId;
        $params[] = $this->tenantId;
        Db::run('UPDATE targets SET ' . implode(',', $sets) . ' WHERE id=? AND tenant_id=?', $params);
    }

    /** @param list<int> $groupIds */
    private function replaceGroups(int $targetId, array $groupIds): void
    {
        Db::run('DELETE FROM target_group WHERE target_id=?', [$targetId]);
        foreach ($groupIds as $groupId) {
            Db::run('INSERT INTO target_group (target_id,group_id) VALUES (?,?)', [$targetId, $groupId]);
        }
    }

    /** @return array<string,mixed> */
    private function ownedTarget(int $targetId): array
    {
        $target = Db::one('SELECT * FROM targets WHERE id=? AND tenant_id=?', [$targetId, $this->tenantId]);
        if ($target === null) {
            throw new IntegrationNotFoundException('targetが見つかりません');
        }
        return $target;
    }

    /** @return array<string,mixed> */
    private function ownedGroup(int $groupId): array
    {
        $group = Db::one('SELECT * FROM groups WHERE id=? AND tenant_id=?', [$groupId, $this->tenantId]);
        if ($group === null) throw new IntegrationNotFoundException('groupが見つかりません');
        return $group;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function mapGroup(array $row): array
    {
        $mapped = ['id' => (int) $row['id'], 'tenantId' => $this->tenantId,
            'name' => (string) $row['name'], 'kind' => (string) $row['kind'],
            'status' => (string) $row['status'], 'archivedAt' => $row['archived_at']];
        $mapped['sourceVersion'] = hash('sha256', json_encode(
            $mapped,
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ));
        return $mapped;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function mapTarget(array $row): array
    {
        $groups = Db::all(
            'SELECT g.id,g.name,g.kind,g.status FROM groups g JOIN target_group tg ON tg.group_id=g.id
             WHERE tg.target_id=? AND g.tenant_id=? ORDER BY g.id',
            [(int) $row['id'], $this->tenantId]
        );
        $mapped = ['id' => (int) $row['id'], 'tenantId' => $this->tenantId, 'email' => (string) $row['email'],
            'name' => $row['name'], 'company' => $row['company'], 'department' => $row['department'],
            'title' => $row['title'], 'positionCategory' => $row['position_category'],
            'status' => (string) $row['status'], 'archivedAt' => $row['archived_at'] ?? null,
            'groups' => array_map(static fn(array $g): array => [
                'id' => (int) $g['id'], 'name' => (string) $g['name'], 'kind' => (string) $g['kind'],
                'status' => (string) $g['status']], $groups)];
        $mapped['sourceVersion'] = hash('sha256', json_encode(
            $mapped,
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ));
        return $mapped;
    }

    /** @param array<string,mixed> $payload */
    private function idempotent(string $action, string $key, array $payload, callable $operation): array
    {
        if (!preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $key)) {
            throw new IntegrationValidationException('Idempotency-Keyが不正です');
        }
        $hash = hash('sha256', json_encode(
            ['action' => $action, 'payload' => $this->canonical($payload)],
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ));
        Db::run("DELETE FROM integration_idempotency_keys WHERE expires_at<=datetime('now')");
        return Db::tx(function () use ($action, $key, $hash, $operation): array {
            $existing = Db::one('SELECT request_hash,status,response_body FROM integration_idempotency_keys
                WHERE tenant_id=? AND idempotency_key=?', [$this->tenantId, $key]);
            if ($existing !== null) {
                if (!hash_equals((string) $existing['request_hash'], $hash) || $existing['status'] !== 'completed') {
                    throw new IntegrationConflictException('Idempotency-Keyが競合しています');
                }
                return json_decode((string) $existing['response_body'], true, 512, JSON_THROW_ON_ERROR);
            }
            Db::run('INSERT INTO integration_idempotency_keys
                (tenant_id,idempotency_key,action,request_hash,expires_at) VALUES (?,?,?,?,datetime(\'now\',\'+7 days\'))',
                [$this->tenantId, $key, $action, $hash]);
            $response = $operation();
            Db::run("UPDATE integration_idempotency_keys SET status='completed',response_body=?
                WHERE tenant_id=? AND idempotency_key=?",
                [json_encode($response, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $this->tenantId, $key]);
            return $response;
        });
    }

    private function canonical(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value);
        }
        return array_map(fn(mixed $item): mixed => $this->canonical($item), $value);
    }

    private function resultSql(): string
    {
        return "SELECT ct.id campaign_target_id,c.id campaign_id,c.name campaign_name,c.status campaign_status,
            ct.target_id,ct.tracking_id,ct.content_no,ct.send_status,ct.sent_at,
            MAX(CASE WHEN e.event_type='open' THEN e.occurred_at END) opened_at,
            MAX(CASE WHEN e.event_type='click' THEN e.occurred_at END) clicked_at,
            MAX(CASE WHEN e.event_type='auth' THEN e.occurred_at END) auth_at
          FROM campaign_targets ct JOIN campaigns c ON c.id=ct.campaign_id
          JOIN targets t ON t.id=ct.target_id AND t.tenant_id=c.tenant_id
          LEFT JOIN events e ON e.campaign_id=c.id AND e.tracking_id=ct.tracking_id AND e.tenant_id=c.tenant_id
          WHERE c.tenant_id=? AND t.is_test=0 AND ct.id>? GROUP BY ct.id ORDER BY ct.id LIMIT ?";
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function mapResult(array $row): array
    {
        return ['campaignTargetId' => (int) $row['campaign_target_id'], 'campaignId' => (int) $row['campaign_id'],
            'campaignName' => (string) $row['campaign_name'], 'campaignStatus' => (string) $row['campaign_status'],
            'tenantId' => $this->tenantId, 'targetId' => (int) $row['target_id'],
            'trackingId' => (string) $row['tracking_id'], 'contentNo' => $row['content_no'] === null ? null : (int) $row['content_no'],
            'sent' => $row['send_status'] === 'sent', 'sentAt' => $row['sent_at'],
            'beaconOpened' => $row['opened_at'] !== null, 'beaconOpenedAt' => $row['opened_at'],
            'linkClicked' => $row['clicked_at'] !== null, 'linkClickedAt' => $row['clicked_at'],
            'authAttempted' => $row['auth_at'] !== null, 'authAttemptedAt' => $row['auth_at']];
    }
}
