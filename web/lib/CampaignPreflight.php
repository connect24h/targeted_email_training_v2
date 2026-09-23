<?php
declare(strict_types=1);

require_once __DIR__ . '/Db.php';

final class CampaignPreflightNotFound extends RuntimeException {}

/** 送信前に、保存済みsnapshotと設定から通数・拒否理由・変更検知値を組み立てる。 */
final class CampaignPreflight
{
    public static function inspect(int $campaignId, int $tenantId): array
    {
        // 画面に示す内容と確認値は同じ読み取りsnapshotから作る。
        // launchの最終判定では既にBEGIN IMMEDIATE内なので入れ子transactionは作らない。
        if (Db::pdo()->inTransaction()) return self::inspectSnapshot($campaignId, $tenantId);
        return Db::tx(static fn(): array => self::inspectSnapshot($campaignId, $tenantId));
    }

    private static function inspectSnapshot(int $campaignId, int $tenantId): array
    {
        $campaign = Db::one(
            'SELECT * FROM campaigns WHERE id=? AND tenant_id=? AND deleted_at IS NULL',
            [$campaignId, $tenantId]
        );
        if ($campaign === null) {
            throw new CampaignPreflightNotFound('キャンペーンが見つかりません');
        }
        $contents = Db::all('SELECT * FROM campaign_contents WHERE campaign_id=? ORDER BY content_no', [$campaignId]);
        if ($contents === []) {
            $contents = [[
                'content_no' => 1,
                'subject_template_id' => $campaign['subject_template_id'],
                'body_template_id' => $campaign['body_template_id'],
                'phish_template_id' => $campaign['phish_template_id'],
                'link_mode' => $campaign['link_mode'],
            ]];
        }
        $targets = Db::all(
            'SELECT ct.*, t.tenant_id AS target_tenant_id, t.email, t.status AS target_status, t.is_test AS target_is_test
             FROM campaign_targets ct LEFT JOIN targets t ON t.id=ct.target_id
             WHERE ct.campaign_id=? ORDER BY ct.id',
            [$campaignId]
        );
        $templates = self::loadTemplates($contents);
        $blockers = [];
        $warnings = [];
        if ($campaign['status'] !== 'draft') $blockers[] = '下書きのみ配信開始できます';
        if (trim((string) ($campaign['data_dir'] ?? '')) === '') $blockers[] = '送信データの保存先が未設定です';
        if (!filter_var((string) ($campaign['from_address'] ?? ''), FILTER_VALIDATE_EMAIL)) {
            $blockers[] = '送信元アドレスが不正です';
        }
        self::checkPeriod($campaign, $blockers, $warnings);
        self::checkContents($contents, $templates, $tenantId, $blockers, $warnings);
        self::checkTargets($campaign, $contents, $targets, $tenantId, $blockers);
        $redirect = self::checkTestRecipients($campaign, $blockers, $warnings);
        $sendCount = count($targets);
        $distribution = [];
        foreach ($redirect as $index => $email) {
            $distribution[] = ['email' => $email, 'count' => intdiv($sendCount, count($redirect)) + ($index < $sendCount % count($redirect) ? 1 : 0)];
        }
        $uniqueTargets = array_unique(array_map(static fn(array $row): int => (int) $row['target_id'], $targets));
        $revision = hash('sha256', json_encode([$campaign, $contents, $targets, $templates], JSON_THROW_ON_ERROR));
        $visibleContents = [];
        foreach ($contents as $content) {
            $visibleContents[] = [
                'content_no' => (int) $content['content_no'],
                'link_mode' => (string) ($content['link_mode'] ?? ''),
                'subject_name' => self::visibleTemplateName($templates, (int) ($content['subject_template_id'] ?? 0), $tenantId, 'subject'),
                'body_name' => self::visibleTemplateName($templates, (int) ($content['body_template_id'] ?? 0), $tenantId, 'body'),
            ];
        }
        return [
            'can_launch' => $blockers === [],
            'blockers' => $blockers,
            'warnings' => $warnings,
            'revision' => $revision,
            'contents' => $visibleContents,
            'summary' => [
                'campaign_name' => (string) $campaign['name'],
                'is_test' => (int) $campaign['is_test'] === 1,
                'target_count' => count($uniqueTargets),
                'send_count' => $sendCount,
                'content_count' => count($contents),
                'start_at' => (string) ($campaign['start_at'] ?? ''),
                'end_at' => (string) ($campaign['end_at'] ?? ''),
                'test_distribution' => $distribution,
            ],
        ];
    }

    private static function loadTemplates(array $contents): array
    {
        $ids = [];
        foreach ($contents as $content) {
            foreach (['subject_template_id', 'body_template_id', 'phish_template_id'] as $key) {
                if ((int) ($content[$key] ?? 0) > 0) $ids[(int) $content[$key]] = true;
            }
        }
        if ($ids === []) return [];
        $values = array_keys($ids);
        $placeholders = implode(',', array_fill(0, count($values), '?'));
        $rows = Db::all("SELECT id, tenant_id, kind, name, content, auth_flag FROM templates WHERE id IN ({$placeholders}) ORDER BY id", $values);
        $result = [];
        foreach ($rows as $row) $result[(int) $row['id']] = $row;
        return $result;
    }

    private static function visibleTemplateName(array $templates, int $id, int $tenantId, string $kind): string
    {
        $template = $templates[$id] ?? null;
        if ($template === null || $template['kind'] !== $kind
            || ($template['tenant_id'] !== null && (int) $template['tenant_id'] !== $tenantId)) {
            return '利用できないテンプレート';
        }
        return (string) $template['name'];
    }

    private static function parseDate(?string $value): ?DateTimeImmutable
    {
        $text = trim((string) $value);
        foreach (['!Y-m-d H:i:s', '!Y-m-d H:i'] as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $text);
            if ($date !== false && $date->format(substr($format, 1)) === $text) return $date;
        }
        return null;
    }

    private static function checkPeriod(array $campaign, array &$blockers, array &$warnings): void
    {
        $start = self::parseDate($campaign['start_at'] ?? null);
        $end = self::parseDate($campaign['end_at'] ?? null);
        if ($start === null || $end === null || $end <= $start) {
            $blockers[] = '送信期間が未設定または不正です';
            return;
        }
        $now = new DateTimeImmutable();
        if ($end <= $now) $blockers[] = '送信終了日時が過去です';
        elseif ($start <= $now) $warnings[] = '開始日時は過去のため、営業時間内ならすぐに配信されます';
    }

    private static function checkContents(array $contents, array $templates, int $tenantId, array &$blockers, array &$warnings): void
    {
        if (count($contents) > 100) $blockers[] = 'コンテンツは100件以内にしてください';
        $generatedAttachmentCount = 0;
        foreach ($contents as $content) {
            $no = (int) $content['content_no'];
            foreach (['subject_template_id' => 'subject', 'body_template_id' => 'body', 'phish_template_id' => 'phish_login'] as $key => $kind) {
                $template = $templates[(int) ($content[$key] ?? 0)] ?? null;
                if ($template === null || $template['kind'] !== $kind
                    || ($template['tenant_id'] !== null && (int) $template['tenant_id'] !== $tenantId)) {
                    $blockers[] = "コンテンツ{$no}の{$kind}テンプレートが利用できません";
                }
            }
            if (!in_array((string) ($content['link_mode'] ?? ''), ['link', 'attachment', 'form', 'qr'], true)) {
                $blockers[] = "コンテンツ{$no}の配信形式が不正です";
            }
            if (in_array((string) ($content['link_mode'] ?? ''), ['attachment', 'qr'], true)) $generatedAttachmentCount++;
        }
        if ($generatedAttachmentCount > 0) {
            $warnings[] = "添付/QR形式のコンテンツが{$generatedAttachmentCount}件あります。生成物は配信予約時に作成・全行検証し、失敗した場合は予約しません";
        }
    }

    private static function checkTargets(array $campaign, array $contents, array $targets, int $tenantId, array &$blockers): void
    {
        if ($targets === []) {
            $blockers[] = '対象者がいません';
            return;
        }
        $knownContent = array_fill_keys(array_map(static fn(array $row): int => (int) $row['content_no'], $contents), true);
        $unique = [];
        foreach ($targets as $row) {
            $targetId = (int) $row['target_id'];
            $unique[$targetId] = true;
            if ((int) ($row['target_tenant_id'] ?? 0) !== $tenantId) $blockers[] = '別組織または削除済みの対象者が含まれます';
            if (($row['target_status'] ?? '') !== 'active') $blockers[] = '停止・退職した対象者が含まれます';
            if (!filter_var((string) ($row['email'] ?? ''), FILTER_VALIDATE_EMAIL)) $blockers[] = '宛先が不正な対象者が含まれます';
            if ((int) $campaign['is_test'] !== 1 && (int) ($row['target_is_test'] ?? 0) === 1) $blockers[] = '本番訓練にTEST対象者が混在しています';
            if (($row['send_status'] ?? '') !== 'pending') $blockers[] = '送信履歴のある対象者が含まれます。再開経路を使ってください';
            if (!isset($knownContent[(int) ($row['content_no'] ?? 0)]) && count($contents) > 1) $blockers[] = '割当のないコンテンツが含まれます';
        }
        $expected = count($unique) * (($campaign['content_delivery'] ?? '') === 'all' ? count($contents) : 1);
        if (count($targets) !== $expected) $blockers[] = '対象者とコンテンツの送信行数が一致しません';
        $blockers = array_values(array_unique($blockers));
    }

    private static function checkTestRecipients(array $campaign, array &$blockers, array &$warnings): array
    {
        $raw = trim((string) ($campaign['test_redirect_emails'] ?? ''));
        if ((int) $campaign['is_test'] !== 1) {
            if ($raw !== '') $warnings[] = 'テスト宛先は本番キャンペーンでは使用されません';
            return [];
        }
        $tokens = preg_split('/[,\s]+/', $raw) ?: [];
        $valid = [];
        foreach ($tokens as $token) {
            if ($token === '') continue;
            if (!filter_var($token, FILTER_VALIDATE_EMAIL)) $blockers[] = 'テスト宛先に不正なメールアドレスがあります';
            else $valid[] = $token;
        }
        $valid = array_values(array_unique($valid));
        if ($valid === []) $blockers[] = 'テスト宛先が未設定です';
        return $valid;
    }
}
