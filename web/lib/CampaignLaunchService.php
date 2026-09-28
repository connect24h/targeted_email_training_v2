<?php
declare(strict_types=1);

require_once __DIR__ . '/CampaignPreflight.php';
require_once __DIR__ . '/CampaignLauncher.php';
require_once __DIR__ . '/TenantStatus.php';

/** 配信前確認と生成の間で内容が変わった場合、scheduleを公開しない。 */
final class CampaignLaunchService
{
    public static function launch(int $campaignId, int $tenantId, string $expectedRevision, ?callable $generator = null): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $expectedRevision)) {
            return self::failure(400, '配信前確認が必要です');
        }
        // 停止中・削除済みのテナントでは、superadmin でも開始しない
        $blocked = TenantStatus::sendBlockReason($tenantId);
        if ($blocked !== null) {
            return self::failure(409, $blocked);
        }
        try {
            $before = CampaignPreflight::inspect($campaignId, $tenantId);
        } catch (CampaignPreflightNotFound $error) {
            return self::failure(404, $error->getMessage());
        }
        if (!$before['can_launch']) return self::failure(409, $before['blockers'][0]);
        if (!hash_equals($before['revision'], $expectedRevision)) {
            return self::failure(409, '確認後に設定・対象者・テンプレートが変更されました。再確認してください');
        }

        $campaign = Db::one('SELECT data_dir FROM campaigns WHERE id=? AND tenant_id=?', [$campaignId, $tenantId]);
        $dataDir = (string) ($campaign['data_dir'] ?? '');
        if ($dataDir === '') return self::failure(409, '送信データの保存先が未設定です');
        if (!is_dir($dataDir) && !@mkdir($dataDir, 02775, true) && !is_dir($dataDir)) {
            return self::failure(500, '送信データの保存先を準備できません');
        }
        $handle = @fopen(rtrim($dataDir, '/') . '/.launch.lock', 'c');
        if ($handle === false) return self::failure(500, '配信準備の排他ロックを開けません');
        try {
            if (!flock($handle, LOCK_EX | LOCK_NB)) return self::failure(409, 'このキャンペーンは配信準備中です');
            return self::launchLocked($campaignId, $tenantId, $expectedRevision, $dataDir, $generator);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private static function launchLocked(int $campaignId, int $tenantId, string $revision, string $dataDir, ?callable $generator): array
    {
        $before = CampaignPreflight::inspect($campaignId, $tenantId);
        if (!$before['can_launch'] || !hash_equals($before['revision'], $revision)) {
            return self::failure(409, '配信前確認の内容が変わりました。再確認してください');
        }
        try {
            [$generated, $error, $batches] = CampaignLauncher::prepare($campaignId, $dataDir, $generator);
        } catch (Throwable $error) {
            self::discardQueued($campaignId);
            return self::failure(500, '送信データを生成できません');
        }
        if (!$generated) {
            self::discardQueued($campaignId);
            return self::failure($error === '対象者がいません' ? 409 : 500, '送信データを生成できません: ' . $error);
        }

        try {
            $after = CampaignPreflight::inspect($campaignId, $tenantId);
        } catch (CampaignPreflightNotFound $error) {
            self::discardQueued($campaignId);
            return self::failure(409, '生成中にキャンペーンが削除されました');
        }
        if (!$after['can_launch'] || !hash_equals($after['revision'], $revision)) {
            self::discardQueued($campaignId);
            return self::failure(409, '生成中に設定・対象者・テンプレートが変更されました。再確認してください');
        }
        $flag = rtrim($dataDir, '/') . '/stop_sending.flag';
        if (is_file($flag) && !unlink($flag)) {
            self::discardQueued($campaignId);
            return self::failure(500, '停止フラグを解除できないため開始を中止しました');
        }

        try {
            $published = Db::txImmediate(static function () use ($campaignId, $tenantId, $revision): bool {
                $fresh = CampaignPreflight::inspect($campaignId, $tenantId);
                if (!$fresh['can_launch'] || !hash_equals($fresh['revision'], $revision)) return false;
                if (Db::run("UPDATE campaigns SET status='scheduled' WHERE id=? AND tenant_id=? AND status='draft'", [$campaignId, $tenantId]) !== 1) return false;
                self::captureTemplateSnapshots($campaignId, $tenantId);
                return true;
            });
        } catch (Throwable $error) {
            self::discardQueued($campaignId);
            return self::failure(500, '配信予約を確定できません');
        }
        if (!$published) {
            self::discardQueued($campaignId);
            return self::failure(409, '配信前確認の内容が変わりました。再確認してください');
        }
        return ['ok' => true, 'status' => 200, 'batches' => $batches];
    }

    /** 予約と同じtransaction内で、後のテンプレート編集に影響されない配信版を残す。 */
    private static function captureTemplateSnapshots(int $campaignId, int $tenantId): void
    {
        $next = Db::one(
            'SELECT COALESCE(MAX(launch_sequence), 0) + 1 AS sequence FROM campaign_template_snapshots WHERE campaign_id=?',
            [$campaignId]
        );
        $sequence = (int) ($next['sequence'] ?? 1);
        $contents = Db::all(
            'SELECT content_no, subject_template_id, body_template_id, phish_template_id
             FROM campaign_contents WHERE campaign_id=? ORDER BY content_no',
            [$campaignId]
        );
        if ($contents === []) {
            $legacy = Db::one(
                'SELECT subject_template_id, body_template_id, phish_template_id FROM campaigns WHERE id=? AND tenant_id=?',
                [$campaignId, $tenantId]
            );
            if ($legacy === null) throw new RuntimeException('配信版の元キャンペーンが見つかりません');
            $contents = [['content_no' => 1] + $legacy];
        }
        $ids = [];
        foreach ($contents as $content) {
            foreach (['subject_template_id', 'body_template_id', 'phish_template_id'] as $key) {
                $ids[(int) ($content[$key] ?? 0)] = true;
            }
        }
        $templateIds = array_keys($ids);
        $placeholders = implode(',', array_fill(0, count($templateIds), '?'));
        $templates = [];
        foreach (Db::all(
            "SELECT id, kind, name, format, content, auth_flag FROM templates
             WHERE (tenant_id=? OR tenant_id IS NULL) AND id IN ({$placeholders})",
            array_merge([$tenantId], $templateIds)
        ) as $template) {
            $templates[(int) $template['id']] = $template;
        }
        foreach ($contents as $content) {
            foreach (['subject_template_id' => 'subject', 'body_template_id' => 'body', 'phish_template_id' => 'phish_login'] as $key => $role) {
                $template = $templates[(int) ($content[$key] ?? 0)] ?? null;
                if ($template === null || $template['kind'] !== $role) {
                    throw new RuntimeException('配信版のテンプレートが見つかりません');
                }
                Db::run(
                    'INSERT INTO campaign_template_snapshots
                     (campaign_id, tenant_id, launch_sequence, content_no, role, template_id, name, format, content, auth_flag)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$campaignId, $tenantId, $sequence, (int) $content['content_no'], $role, (int) $template['id'],
                        $template['name'], $template['format'], $template['content'], $template['auth_flag']]
                );
            }
        }
    }

    private static function discardQueued(int $campaignId): void
    {
        Db::run("DELETE FROM send_schedule WHERE campaign_id=? AND status IN ('queued','cancelled')", [$campaignId]);
    }

    private static function failure(int $status, string $message): array
    {
        return ['ok' => false, 'status' => $status, 'error' => $message];
    }
}
