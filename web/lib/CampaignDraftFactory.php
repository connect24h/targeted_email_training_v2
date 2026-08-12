<?php
declare(strict_types=1);

require_once __DIR__ . '/Db.php';

class CampaignDraftException extends RuntimeException
{
}

final class CampaignDraftNotFoundException extends CampaignDraftException
{
}

final class CampaignDraftValidationException extends CampaignDraftException
{
}

/** 元キャンペーンの設定を再利用し、安全なdraftを一括生成する。 */
final class CampaignDraftFactory
{
    private Closure $trackingIdGenerator;

    public function __construct(?callable $trackingIdGenerator = null)
    {
        $this->trackingIdGenerator = $trackingIdGenerator === null
            ? static fn(): string => str_pad((string) random_int(0, 9999999999), 10, '0', STR_PAD_LEFT)
            : Closure::fromCallable($trackingIdGenerator);
    }

    public function duplicate(int $sourceCampaignId, int $tenantId, int $createdBy): int
    {
        $source = $this->findSource($sourceCampaignId, $tenantId);
        $rows = Db::all(
            'SELECT target_id, MIN(koban) AS first_koban
             FROM campaign_targets WHERE campaign_id = ?
             GROUP BY target_id ORDER BY first_koban, target_id',
            [$sourceCampaignId]
        );
        return $this->createFromSource($sourceCampaignId, $tenantId, [
            'created_by' => $createdBy,
            'target_ids' => array_map(static fn(array $row): int => (int) $row['target_id'], $rows),
            'name' => mb_substr((string) $source['name'] . ' のコピー', 0, 200),
        ]);
    }

    /**
     * @param array{created_by:int|null,target_ids:array<int,int>,name?:string,
     *     content_no_by_target?:array<int,int>} $options
     */
    public function createFromSource(int $sourceCampaignId, int $tenantId, array $options): int
    {
        $operation = function () use ($sourceCampaignId, $tenantId, $options): int {
            $source = $this->findSource($sourceCampaignId, $tenantId);
            $createdBy = $this->validateCreatedBy($options['created_by'] ?? null, $tenantId);
            $targetIds = $this->normalizeTargetIds($options['target_ids'] ?? null);
            $this->validateTargets($targetIds, $tenantId);
            $contents = $this->loadContents(
                $sourceCampaignId,
                $tenantId,
                $source['from_address'] !== null ? (string) $source['from_address'] : null
            );
            $assignments = $this->validateContentAssignments(
                $options['content_no_by_target'] ?? null,
                $targetIds,
                $contents,
                (string) $source['content_delivery']
            );
            $name = $this->draftName($options['name'] ?? null, (string) $source['name']);

            $draftId = $this->insertCampaign($source, $createdBy, $name);
            $this->setDataDir($draftId, $tenantId);
            $this->copyContents($draftId, $contents);
            if ((string) $source['content_delivery'] === 'all') {
                $this->assignAllTargets($draftId, $targetIds, $contents);
            } else {
                $this->assignDistributedTargets($draftId, $targetIds, $contents, $assignments);
            }
            return $draftId;
        };
        if (Db::pdo()->inTransaction()) {
            return $operation();
        }
        return Db::tx($operation);
    }

    private function findSource(int $sourceCampaignId, int $tenantId): array
    {
        $source = Db::one(
            'SELECT * FROM campaigns WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL',
            [$sourceCampaignId, $tenantId]
        );
        if ($source === null) {
            throw new CampaignDraftNotFoundException('元キャンペーンが見つかりません');
        }
        return $source;
    }

    private function validateCreatedBy(mixed $createdBy, int $tenantId): ?int
    {
        if ($createdBy === null) {
            return null;
        }
        if (!is_int($createdBy) || $createdBy < 1) {
            throw new CampaignDraftValidationException('作成者が不正です');
        }
        $user = Db::one(
            'SELECT id FROM users WHERE id = ? AND (tenant_id = ? OR tenant_id IS NULL)',
            [$createdBy, $tenantId]
        );
        if ($user === null) {
            throw new CampaignDraftValidationException('作成者がテナントに所属していません');
        }
        return $createdBy;
    }

    /** @return array<int, int> */
    private function normalizeTargetIds(mixed $targetIds): array
    {
        if (!is_array($targetIds) || $targetIds === []) {
            throw new CampaignDraftValidationException('対象者を1名以上指定してください');
        }
        $normalized = [];
        foreach ($targetIds as $targetId) {
            if (!is_int($targetId) || $targetId < 1) {
                throw new CampaignDraftValidationException('対象者IDが不正です');
            }
            $normalized[$targetId] = $targetId;
        }
        return array_values($normalized);
    }

    /** @param array<int, int> $targetIds */
    private function validateTargets(array $targetIds, int $tenantId): void
    {
        foreach ($targetIds as $targetId) {
            $target = Db::one(
                "SELECT id FROM targets WHERE id = ? AND tenant_id = ? AND status = 'active'",
                [$targetId, $tenantId]
            );
            if ($target === null) {
                throw new CampaignDraftValidationException('対象者が存在しないか利用できません');
            }
        }
    }

    private function loadContents(int $sourceCampaignId, int $tenantId, ?string $defaultFromAddress): array
    {
        $contents = Db::all(
            'SELECT cc.*, pt.auth_flag
             FROM campaign_contents cc
             LEFT JOIN templates pt ON pt.id = cc.phish_template_id
                 AND (pt.tenant_id = ? OR pt.tenant_id IS NULL)
             WHERE cc.campaign_id = ? ORDER BY cc.content_no',
            [$tenantId, $sourceCampaignId]
        );
        if ($contents === []) {
            throw new CampaignDraftValidationException('元キャンペーンにコンテンツがありません');
        }
        foreach ($contents as $index => $content) {
            $this->validateContent($content, $tenantId);
            $contents[$index]['effective_from_address'] = $content['from_address'] ?? $defaultFromAddress;
        }
        return $contents;
    }

    private function validateContent(array $content, int $tenantId): void
    {
        $kinds = [
            'subject_template_id' => 'subject',
            'body_template_id' => 'body',
            'phish_template_id' => 'phish_login',
        ];
        foreach ($kinds as $column => $kind) {
            $templateId = (int) ($content[$column] ?? 0);
            $template = Db::one(
                'SELECT id FROM templates WHERE id = ? AND kind = ? AND (tenant_id = ? OR tenant_id IS NULL)',
                [$templateId, $kind, $tenantId]
            );
            if ($template === null) {
                throw new CampaignDraftValidationException('元キャンペーンのコンテンツ設定が不完全です');
            }
        }
    }

    /** @return array<int, int>|null */
    private function validateContentAssignments(
        mixed $requested,
        array $targetIds,
        array $contents,
        string $deliveryMode
    ): ?array
    {
        if ($requested === null) {
            return null;
        }
        if (!is_array($requested) || $deliveryMode !== 'distribute') {
            throw new CampaignDraftValidationException('コンテンツ割当が不正です');
        }
        $validContentNos = array_map(static fn(array $row): int => (int) $row['content_no'], $contents);
        $assignments = [];
        foreach ($targetIds as $targetId) {
            $contentNo = $requested[$targetId] ?? null;
            if (!is_int($contentNo) || !in_array($contentNo, $validContentNos, true)) {
                throw new CampaignDraftValidationException('対象者のコンテンツ割当が不正です');
            }
            $assignments[$targetId] = $contentNo;
        }
        if (count($assignments) !== count($requested)) {
            throw new CampaignDraftValidationException('割当対象外の対象者が含まれています');
        }
        return $assignments;
    }

    private function draftName(mixed $requestedName, string $sourceName): string
    {
        if ($requestedName === null) {
            return mb_substr($sourceName . ' のコピー', 0, 200);
        }
        if (!is_string($requestedName) || trim($requestedName) === '') {
            throw new CampaignDraftValidationException('キャンペーン名が不正です');
        }
        return mb_substr(trim($requestedName), 0, 200);
    }

    private function insertCampaign(array $source, ?int $createdBy, string $name): int
    {
        return Db::insert(
            'INSERT INTO campaigns
             (tenant_id, name, status, subject_template_id, body_template_id, phish_template_id,
              from_address, from_domain, beacon_base, link_mode, attachment_ext, attachment_zip, send_mode,
              split_count, split_interval_min, weekdays_only, business_start, business_end,
              start_at, end_at, is_test, content_delivery, test_redirect_emails, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $source['tenant_id'], $name, 'draft', $source['subject_template_id'], $source['body_template_id'],
                $source['phish_template_id'], $source['from_address'], $source['from_domain'], $source['beacon_base'],
                $source['link_mode'], $source['attachment_ext'], $source['attachment_zip'], $source['send_mode'],
                $source['split_count'], $source['split_interval_min'], $source['weekdays_only'],
                $source['business_start'], $source['business_end'], null, null, $source['is_test'],
                $source['content_delivery'], $source['test_redirect_emails'] ?? null, $createdBy,
            ]
        );
    }

    private function setDataDir(int $draftId, int $tenantId): void
    {
        $tenant = Db::one('SELECT slug FROM tenants WHERE id = ?', [$tenantId]);
        if ($tenant === null || trim((string) $tenant['slug']) === '') {
            throw new CampaignDraftValidationException('テナントのdata_dirを生成できません');
        }
        $dataDir = '/opt/training/tet2-data/' . (string) $tenant['slug'] . '/campaign_' . $draftId;
        Db::run('UPDATE campaigns SET data_dir = ? WHERE id = ?', [$dataDir, $draftId]);
    }

    private function copyContents(int $draftId, array $contents): void
    {
        foreach ($contents as $content) {
            Db::run(
                'INSERT INTO campaign_contents
                 (campaign_id, content_no, subject_template_id, body_template_id, phish_template_id,
                  link_mode, attachment_ext, attachment_zip, from_address, beacon_base, suppress_body_url,
                  suppress_prefill_email)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $draftId, $content['content_no'], $content['subject_template_id'], $content['body_template_id'],
                    $content['phish_template_id'], $content['link_mode'], $content['attachment_ext'],
                    $content['attachment_zip'], $content['from_address'], $content['beacon_base'],
                    $content['suppress_body_url'] ?? 0,
                    $content['suppress_prefill_email'] ?? 0,
                ]
            );
        }
    }

    /** @param array<int, int> $targetIds */
    private function assignAllTargets(int $draftId, array $targetIds, array $contents): void
    {
        $koban = 1;
        foreach ($targetIds as $targetId) {
            foreach ($contents as $content) {
                $this->insertTarget($draftId, ['id' => $targetId, 'koban' => $koban], $content);
            }
            $koban++;
        }
    }

    /** @param array<int, int> $targetIds */
    private function assignDistributedTargets(
        int $draftId,
        array $targetIds,
        array $contents,
        ?array $assignments
    ): void
    {
        $contentsByNo = [];
        foreach ($contents as $content) {
            $contentsByNo[(int) $content['content_no']] = $content;
        }
        foreach ($targetIds as $index => $targetId) {
            $content = $assignments === null
                ? $contents[$index % count($contents)]
                : $contentsByNo[$assignments[$targetId]];
            $this->insertTarget($draftId, ['id' => $targetId, 'koban' => $index + 1], $content);
        }
    }

    /** @param array{id:int,koban:int} $target */
    private function insertTarget(int $draftId, array $target, array $content): void
    {
        Db::run(
            'INSERT INTO campaign_targets
             (campaign_id, target_id, tracking_id, koban, auth_flag, from_address, send_status, content_no)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $draftId, $target['id'], $this->nextTrackingId(), $target['koban'], $content['auth_flag'],
                $content['effective_from_address'], 'pending', $content['content_no'],
            ]
        );
    }

    private function nextTrackingId(): string
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $trackingId = ($this->trackingIdGenerator)();
            if (!preg_match('/^[0-9]{10}$/', $trackingId)) {
                throw new CampaignDraftValidationException('tracking_id生成結果が不正です');
            }
            if (Db::one('SELECT 1 FROM campaign_targets WHERE tracking_id = ?', [$trackingId]) === null) {
                return $trackingId;
            }
        }
        throw new CampaignDraftException('tracking_idを生成できません');
    }
}
