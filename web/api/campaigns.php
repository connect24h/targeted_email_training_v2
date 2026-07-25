<?php declare(strict_types=1); require __DIR__."/../lib/bootstrap.php";

const CAMPAIGN_LINK_MODES = ['link', 'attachment', 'form', 'qr'];
const CAMPAIGN_SEND_MODES = ['normal', 'split', 'slow'];
const CAMPAIGN_SPLIT_INTERVALS = [5, 15, 30, 60];

function campaigns_string(array $body, string $key): string
{
    if (!array_key_exists($key, $body) || !is_string($body[$key]) || trim($body[$key]) === '') {
        json_error($key . ' は必須です', 400);
    }
    return trim($body[$key]);
}

function campaigns_optional_string(array $body, string $key): ?string
{
    if (!array_key_exists($key, $body)) {
        return null;
    }
    if ($body[$key] === null) {
        return null;
    }
    if (!is_string($body[$key]) || trim($body[$key]) === '') {
        json_error($key . ' が不正です', 400);
    }
    return trim($body[$key]);
}

function campaigns_int(array $body, string $key): int
{
    if (!array_key_exists($key, $body) || !is_int($body[$key]) || $body[$key] < 1) {
        json_error($key . ' が不正です', 400);
    }
    return $body[$key];
}

/**
 * ビーコンベース URL（P6）。未指定/空は NULL。指定時は http(s):// スキーム必須。
 * 訓練用途で IP 直指定もありうるためホスト名の形は緩く許すが、スキームは限定する。
 */
function campaigns_optional_beacon_base(array $body, string $key = 'beacon_base'): ?string
{
    if (!array_key_exists($key, $body) || $body[$key] === null) {
        return null;
    }
    if (!is_string($body[$key])) {
        json_error($key . ' が不正です', 400);
    }
    $v = trim($body[$key]);
    if ($v === '') {
        return null;
    }
    if (!preg_match('#^https?://[^\s/][^\s]*$#', $v)) {
        json_error($key . ' は http:// または https:// で始まる URL を指定してください', 400);
    }
    return $v;
}

/**
 * コンテンツ別の送信元アドレス(任意)。未指定/空は NULL(キャンペーン単位にフォールバック)。
 * 指定時はメール形式を検証する。
 */
function campaigns_optional_content_email(array $content, string $key, int $idx): ?string
{
    if (!array_key_exists($key, $content) || $content[$key] === null) {
        return null;
    }
    if (!is_string($content[$key])) {
        json_error('contents[' . $idx . '].' . $key . ' が不正です', 400);
    }
    $v = trim($content[$key]);
    if ($v === '') {
        return null;
    }
    if (filter_var($v, FILTER_VALIDATE_EMAIL) === false) {
        json_error('contents[' . $idx . '].' . $key . ' はメールアドレス形式で指定してください', 400);
    }
    return $v;
}

function campaigns_optional_int(array $body, string $key): ?int
{
    if (!array_key_exists($key, $body) || $body[$key] === null) {
        return null;
    }
    if (!is_int($body[$key]) || $body[$key] < 1) {
        json_error($key . ' が不正です', 400);
    }
    return $body[$key];
}

function campaigns_optional_bool_int(array $body, string $key): ?int
{
    if (!array_key_exists($key, $body) || $body[$key] === null) {
        return null;
    }
    if (is_bool($body[$key])) {
        return $body[$key] ? 1 : 0;
    }
    if ($body[$key] === 0 || $body[$key] === 1) {
        return (int) $body[$key];
    }
    json_error($key . ' が不正です', 400);
}

function campaigns_query_int(string $key): ?int
{
    if (!array_key_exists($key, $_GET) || $_GET[$key] === '') {
        return null;
    }
    $value = filter_var($_GET[$key], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($value === false) {
        json_error($key . ' が不正です', 400);
    }
    return (int) $value;
}

function campaigns_int_array(array $body, string $key): ?array
{
    if (!array_key_exists($key, $body)) {
        return null;
    }
    if (!is_array($body[$key])) {
        json_error($key . ' が不正です', 400);
    }
    $ids = [];
    foreach ($body[$key] as $id) {
        if (!is_int($id) || $id < 1) {
            json_error($key . ' が不正です', 400);
        }
        $ids[] = $id;
    }
    return array_values(array_unique($ids));
}

function campaigns_is_unique_error(Throwable $e): bool
{
    return $e instanceof PDOException && str_contains($e->getMessage(), 'UNIQUE');
}

function campaigns_validate_in(string $value, array $allowed, string $key): void
{
    if (!in_array($value, $allowed, true)) {
        json_error($key . ' が不正です', 400);
    }
}

function campaigns_assert_template_visible(int $id, int $tenantId, string $kind): array
{
    $template = Db::one(
        'SELECT * FROM templates WHERE id = ? AND kind = ? AND (tenant_id = ? OR tenant_id IS NULL)',
        [$id, $kind, $tenantId]
    );
    if ($template === null) {
        json_error('テンプレートが見つかりません', 404);
    }
    return $template;
}

function campaigns_assert_group_owned(int $groupId, int $tenantId): void
{
    if (Db::one('SELECT id FROM groups WHERE id = ? AND tenant_id = ?', [$groupId, $tenantId]) === null) {
        json_error('グループが見つかりません', 404);
    }
}

function campaigns_assert_targets_owned(array $targetIds, int $tenantId): void
{
    foreach ($targetIds as $targetId) {
        assert_target_owned($targetId, $tenantId);
    }
}

function campaigns_target_ids_from_groups(array $groupIds, int $tenantId): array
{
    $ids = [];
    foreach ($groupIds as $groupId) {
        campaigns_assert_group_owned($groupId, $tenantId);
        $rows = Db::all(
            'SELECT t.id
             FROM targets t
             INNER JOIN target_group tg ON tg.target_id = t.id
             WHERE t.tenant_id = ? AND tg.group_id = ?
             ORDER BY t.id',
            [$tenantId, $groupId]
        );
        foreach ($rows as $row) {
            $ids[] = (int) $row['id'];
        }
    }
    return $ids;
}

function campaigns_collect_target_ids(array $body, int $tenantId): array
{
    $targetIds = campaigns_int_array($body, 'target_ids') ?? [];
    $groupIds = campaigns_int_array($body, 'group_ids') ?? [];
    campaigns_assert_targets_owned($targetIds, $tenantId);
    $ids = array_merge($targetIds, campaigns_target_ids_from_groups($groupIds, $tenantId));
    sort($ids);
    return array_values(array_unique($ids));
}

function campaigns_generate_tracking_id(): string
{
    for ($i = 0; $i < 10; $i++) {
        $trackingId = str_pad((string) random_int(0, 9999999999), 10, '0', STR_PAD_LEFT);
        if (Db::one('SELECT 1 FROM campaign_targets WHERE tracking_id = ?', [$trackingId]) === null) {
            return $trackingId;
        }
    }
    throw new RuntimeException('tracking_id を生成できません');
}

function campaigns_insert_targets(int $campaignId, array $targetIds, ?int $authFlag, ?string $fromAddress): void
{
    $koban = 1;
    foreach ($targetIds as $targetId) {
        Db::run(
            'INSERT INTO campaign_targets
             (campaign_id, target_id, tracking_id, koban, auth_flag, from_address, send_status)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$campaignId, $targetId, campaigns_generate_tracking_id(), $koban, $authFlag, $fromAddress, 'pending']
        );
        $koban++;
    }
}

/**
 * campaign_contents をラウンドロビンで campaign_targets に割り当てる。
 * 
 * 手順:
 * 1. campaign_contents をcontent_no順で取得。存在しなければ例外。
 * 2. campaign_targets を koban 順に反復、各 target に content[i % K] を割り当て。
 * 3. 各 campaign_targets.content_no と auth_flag を UPDATE。
 * 
 * 均等性: N人×K コンテンツなら各コンテンツ floor(N/K)〜ceil(N/K) 人（差最大1）。
 */
function campaigns_distribute_contents(int $campaignId, array $targetIds): void
{
    // campaign_contents を content_no 順で取得
    $contents = Db::all(
        'SELECT cc.*, t.auth_flag FROM campaign_contents cc
         LEFT JOIN templates t ON t.id = cc.phish_template_id
         WHERE cc.campaign_id = ?
         ORDER BY cc.content_no',
        [$campaignId]
    );
    
    if (count($contents) === 0) {
        throw new RuntimeException('このキャンペーンにはコンテンツが登録されていません');
    }
    
    $contentCount = count($contents);
    $koban = 1;
    
    foreach ($targetIds as $index => $targetId) {
        // ラウンドロビンで content を割り当て
        $content = $contents[$index % $contentCount];
        $contentNo = (int) $content['content_no'];
        $authFlag = $content['auth_flag'] !== null ? (int) $content['auth_flag'] : null;
        
        // campaign_targets に content_no と auth_flag を設定
        Db::run(
            'UPDATE campaign_targets SET content_no = ?, auth_flag = ? WHERE campaign_id = ? AND target_id = ?',
            [$contentNo, $authFlag, $campaignId, $targetId]
        );
        $koban++;
    }
}

function campaigns_data_dir(int $tenantId, int $campaignId): string
{
    $tenant = Db::one('SELECT slug FROM tenants WHERE id = ?', [$tenantId]);
    if ($tenant === null) {
        json_error('テナントが見つかりません', 404);
    }
    return '/opt/training/tet2-data/' . (string) $tenant['slug'] . '/campaign_' . $campaignId;
}

function campaigns_row(int $id, int $tenantId): array
{
    $campaign = Db::one(
        'SELECT c.*,
                st.name AS subject_template_name,
                bt.name AS body_template_name,
                pt.name AS phish_template_name,
                COUNT(ct.id) AS target_count
         FROM campaigns c
         LEFT JOIN templates st ON st.id = c.subject_template_id
         LEFT JOIN templates bt ON bt.id = c.body_template_id
         LEFT JOIN templates pt ON pt.id = c.phish_template_id
         LEFT JOIN campaign_targets ct ON ct.campaign_id = c.id
         WHERE c.id = ? AND c.tenant_id = ?
         GROUP BY c.id',
        [$id, $tenantId]
    );
    if ($campaign === null) {
        json_error('キャンペーンが見つかりません', 404);
    }
    return $campaign;
}

function campaigns_handle_list(array $actor): never
{
    $tenantId = effective_tenant_id($actor, campaigns_query_int('tenant_id'));
    $campaigns = Db::all(
        'SELECT c.id, c.tenant_id, c.name, c.status, c.start_at, c.end_at, c.is_test, c.created_at,
                COUNT(ct.id) AS target_count
         FROM campaigns c
         LEFT JOIN campaign_targets ct ON ct.campaign_id = c.id
         WHERE c.tenant_id = ?
         GROUP BY c.id, c.tenant_id, c.name, c.status, c.start_at, c.end_at, c.is_test, c.created_at
         ORDER BY c.id DESC',
        [$tenantId]
    );
    json_out(['success' => true, 'campaigns' => $campaigns]);
}

function campaigns_handle_get(array $actor): never
{
    $tenantId = effective_tenant_id($actor, campaigns_query_int('tenant_id'));
    $id = campaigns_query_int('id');
    if ($id === null) {
        json_error('id が不正です', 400);
    }
    assert_campaign_owned($id, $tenantId);
    json_out(['success' => true, 'campaign' => campaigns_row($id, $tenantId)]);
}

function campaigns_validate_schedule(array $data): void
{
    campaigns_validate_in((string) $data['link_mode'], CAMPAIGN_LINK_MODES, 'link_mode');
    campaigns_validate_in((string) $data['send_mode'], CAMPAIGN_SEND_MODES, 'send_mode');
    if ($data['split_interval_min'] !== null && !in_array((int) $data['split_interval_min'], CAMPAIGN_SPLIT_INTERVALS, true)) {
        json_error('split_interval_min が不正です', 400);
    }
}

function campaigns_create_data(array $body): array
{
    $data = [
        'name' => campaigns_string($body, 'name'),
        'subject_template_id' => campaigns_int($body, 'subject_template_id'),
        'body_template_id' => campaigns_int($body, 'body_template_id'),
        'phish_template_id' => campaigns_int($body, 'phish_template_id'),
        'from_address' => campaigns_string($body, 'from_address'),
        'from_domain' => campaigns_optional_string($body, 'from_domain'),
        'beacon_base' => campaigns_optional_beacon_base($body),
        'link_mode' => campaigns_string($body, 'link_mode'),
        'attachment_ext' => campaigns_optional_string($body, 'attachment_ext'),
        'attachment_zip' => campaigns_optional_bool_int($body, 'attachment_zip') ?? 0,
        'send_mode' => campaigns_string($body, 'send_mode'),
        'split_count' => campaigns_optional_int($body, 'split_count'),
        'split_interval_min' => campaigns_optional_int($body, 'split_interval_min'),
        'weekdays_only' => campaigns_optional_bool_int($body, 'weekdays_only') ?? 1,
        'business_start' => campaigns_optional_string($body, 'business_start'),
        'business_end' => campaigns_optional_string($body, 'business_end'),
        'start_at' => campaigns_string($body, 'start_at'),
        'end_at' => campaigns_string($body, 'end_at'),
        'is_test' => campaigns_optional_bool_int($body, 'is_test') ?? 0,
    ];
    campaigns_validate_schedule($data);
    return $data;
}

/**
 * contents 配列をパースし、IDOR検証・link_mode検証を行う。
 * 
 * 形式:
 * [
 *   { subject_template_id, body_template_id, phish_template_id, link_mode, attachment_ext, attachment_zip },
 *   ...
 * ]
 * 
 * 返却: [content_no => [subject_template_id, body_template_id, ...], ...]（content_no は 1 から）
 */
function campaigns_parse_contents(array $body, int $tenantId): array
{
    $rawContents = $body['contents'] ?? null;
    if ($rawContents === null) {
        return [];
    }
    if (!is_array($rawContents)) {
        json_error('contents が不正です', 400);
    }
    if (count($rawContents) === 0) {
        json_error('contents は1件以上必須です', 400);
    }

    $contents = [];
    foreach ($rawContents as $idx => $content) {
        if (!is_array($content)) {
            json_error('contents[' . $idx . '] が不正です', 400);
        }

        $contentNo = $idx + 1;
        $subjectId = campaigns_int($content, 'subject_template_id');
        $bodyId = campaigns_int($content, 'body_template_id');
        $phishId = campaigns_int($content, 'phish_template_id');
        $linkMode = campaigns_string($content, 'link_mode');
        $attachmentExt = campaigns_optional_string($content, 'attachment_ext');
        $attachmentZip = campaigns_optional_bool_int($content, 'attachment_zip') ?? 0;
        $suppressBodyUrl = campaigns_optional_bool_int($content, 'suppress_body_url') ?? 0;
        // コンテンツ別の送信元アドレス/ビーコンURL(任意。未指定ならキャンペーン単位にフォールバック)。
        $contentFromAddress = campaigns_optional_content_email($content, 'from_address', $idx);
        $contentBeaconBase = campaigns_optional_beacon_base($content, 'beacon_base');

        // IDOR検証: テンプレートはテナント内のものか、共有テンプレートのみ
        campaigns_assert_template_visible($subjectId, $tenantId, 'subject');
        campaigns_assert_template_visible($bodyId, $tenantId, 'body');
        campaigns_assert_template_visible($phishId, $tenantId, 'phish_login');

        // link_mode 検証
        campaigns_validate_in($linkMode, CAMPAIGN_LINK_MODES, 'contents[' . $idx . '].link_mode');

        $contents[$contentNo] = [
            'subject_template_id' => $subjectId,
            'body_template_id' => $bodyId,
            'phish_template_id' => $phishId,
            'link_mode' => $linkMode,
            'attachment_ext' => $attachmentExt,
            'attachment_zip' => $attachmentZip,
            'suppress_body_url' => $suppressBodyUrl,
            'from_address' => $contentFromAddress,
            'beacon_base' => $contentBeaconBase,
        ];
    }

    return $contents;
}

function campaigns_handle_create(array $actor): never
{
    tet2_require_csrf();
    $body = json_body();
    $tenantId = effective_tenant_id($actor, campaigns_optional_int($body, 'tenant_id'));
    
    // contents が来たかどうかで分岐
    $hasContents = array_key_exists('contents', $body);
    if ($hasContents) {
        // 新方式: contents 配列から campaign データを構築
        $parsedContents = campaigns_parse_contents($body, $tenantId);
        $firstContent = $parsedContents[1] ?? null;
        if ($firstContent === null) {
            json_error('contents は1件以上必須です', 400);
        }

        // campaigns 本体には contents[0] の値を使用（後方互換性）
        $data = [
            'name' => campaigns_string($body, 'name'),
            'subject_template_id' => $firstContent['subject_template_id'],
            'body_template_id' => $firstContent['body_template_id'],
            'phish_template_id' => $firstContent['phish_template_id'],
            'from_address' => campaigns_string($body, 'from_address'),
            'from_domain' => campaigns_optional_string($body, 'from_domain'),
            'beacon_base' => campaigns_optional_beacon_base($body),
            'link_mode' => $firstContent['link_mode'],
            'attachment_ext' => $firstContent['attachment_ext'],
            'attachment_zip' => $firstContent['attachment_zip'],
            'send_mode' => campaigns_string($body, 'send_mode'),
            'split_count' => campaigns_optional_int($body, 'split_count'),
            'split_interval_min' => campaigns_optional_int($body, 'split_interval_min'),
            'weekdays_only' => campaigns_optional_bool_int($body, 'weekdays_only') ?? 1,
            'business_start' => campaigns_optional_string($body, 'business_start'),
            'business_end' => campaigns_optional_string($body, 'business_end'),
            'start_at' => campaigns_string($body, 'start_at'),
            'end_at' => campaigns_string($body, 'end_at'),
            'is_test' => campaigns_optional_bool_int($body, 'is_test') ?? 0,
        ];
        campaigns_validate_schedule($data);
        $phishTemplate = campaigns_assert_template_visible((int) $data['phish_template_id'], $tenantId, 'phish_login');
    } else {
        // 後方互換: 従来の単一フィールド指定
        $data = campaigns_create_data($body);
        campaigns_assert_template_visible((int) $data['subject_template_id'], $tenantId, 'subject');
        campaigns_assert_template_visible((int) $data['body_template_id'], $tenantId, 'body');
        $phishTemplate = campaigns_assert_template_visible((int) $data['phish_template_id'], $tenantId, 'phish_login');
        // 単一コンテンツを内部形式に変換
        $parsedContents = [
            1 => [
                'subject_template_id' => $data['subject_template_id'],
                'body_template_id' => $data['body_template_id'],
                'phish_template_id' => $data['phish_template_id'],
                'link_mode' => $data['link_mode'],
                'attachment_ext' => $data['attachment_ext'],
                'attachment_zip' => $data['attachment_zip'],
            ]
        ];
    }

    $targetIds = campaigns_collect_target_ids($body, $tenantId);

    $id = Db::tx(function () use ($tenantId, $actor, $data, $targetIds, $phishTemplate, $parsedContents): int {
        $campaignId = Db::insert(
            'INSERT INTO campaigns
             (tenant_id, name, status, subject_template_id, body_template_id, phish_template_id,
              from_address, from_domain, beacon_base, link_mode, attachment_ext, attachment_zip, send_mode,
              split_count, split_interval_min, weekdays_only, business_start, business_end,
              start_at, end_at, is_test, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $tenantId,
                $data['name'],
                'draft',
                $data['subject_template_id'],
                $data['body_template_id'],
                $data['phish_template_id'],
                $data['from_address'],
                $data['from_domain'],
                $data['beacon_base'],
                $data['link_mode'],
                $data['attachment_ext'],
                $data['attachment_zip'],
                $data['send_mode'],
                $data['split_count'],
                $data['split_interval_min'],
                $data['weekdays_only'],
                $data['business_start'],
                $data['business_end'],
                $data['start_at'],
                $data['end_at'],
                $data['is_test'],
                $actor['id'],
            ]
        );
        Db::run('UPDATE campaigns SET data_dir = ? WHERE id = ? AND tenant_id = ?', [campaigns_data_dir($tenantId, $campaignId), $campaignId, $tenantId]);
        
        // campaign_contents にコンテンツを登録
        foreach ($parsedContents as $contentNo => $content) {
            Db::run(
                'INSERT INTO campaign_contents
                 (campaign_id, content_no, subject_template_id, body_template_id, phish_template_id,
                  link_mode, attachment_ext, attachment_zip, suppress_body_url, from_address, beacon_base)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $campaignId,
                    $contentNo,
                    $content['subject_template_id'],
                    $content['body_template_id'],
                    $content['phish_template_id'],
                    $content['link_mode'],
                    $content['attachment_ext'],
                    $content['attachment_zip'],
                    $content['suppress_body_url'] ?? 0,
                    $content['from_address'] ?? null,
                    $content['beacon_base'] ?? null,
                ]
            );
        }

        campaigns_insert_targets($campaignId, $targetIds, $phishTemplate['auth_flag'] !== null ? (int) $phishTemplate['auth_flag'] : null, $data['from_address']);
        campaigns_distribute_contents($campaignId, $targetIds);
        
        return $campaignId;
    });
    audit('campaign.create', 'campaign_id=' . $id);
    $campaign = campaigns_row($id, $tenantId);
    json_out(['success' => true, 'campaign' => $campaign, 'target_count' => (int) $campaign['target_count']], 201);
}

function campaigns_update_fields(array $body): array
{
    $fields = [];
    foreach (['name', 'from_address', 'from_domain', 'link_mode', 'attachment_ext', 'send_mode', 'business_start', 'business_end', 'start_at', 'end_at'] as $key) {
        if (array_key_exists($key, $body)) {
            $fields[$key] = campaigns_optional_string($body, $key);
            if (in_array($key, ['name', 'from_address', 'link_mode', 'send_mode', 'start_at', 'end_at'], true) && $fields[$key] === null) {
                json_error($key . ' が不正です', 400);
            }
        }
    }
    foreach (['subject_template_id', 'body_template_id', 'phish_template_id', 'split_count', 'split_interval_min'] as $key) {
        if (array_key_exists($key, $body)) {
            $fields[$key] = campaigns_optional_int($body, $key);
        }
    }
    foreach (['attachment_zip', 'weekdays_only', 'is_test'] as $key) {
        if (array_key_exists($key, $body)) {
            $fields[$key] = campaigns_optional_bool_int($body, $key);
        }
    }
    // beacon_base は URL スキーム検証を伴うため個別に処理する（P6）。
    if (array_key_exists('beacon_base', $body)) {
        $fields['beacon_base'] = campaigns_optional_beacon_base($body);
    }
    return $fields;
}

function campaigns_apply_update(int $campaignId, int $tenantId, array $fields): void
{
    $allowed = [
        'name', 'subject_template_id', 'body_template_id', 'phish_template_id', 'from_address',
        'from_domain', 'beacon_base', 'link_mode', 'attachment_ext', 'attachment_zip', 'send_mode', 'split_count',
        'split_interval_min', 'weekdays_only', 'business_start', 'business_end', 'start_at',
        'end_at', 'is_test',
    ];
    foreach ($allowed as $key) {
        if (!array_key_exists($key, $fields)) {
            continue;
        }
        Db::run('UPDATE campaigns SET ' . $key . ' = ? WHERE id = ? AND tenant_id = ?', [$fields[$key], $campaignId, $tenantId]);
    }
}

function campaigns_handle_update(array $actor): never
{
    tet2_require_csrf();
    $body = json_body();
    $tenantId = effective_tenant_id($actor, campaigns_optional_int($body, 'tenant_id'));
    $id = campaigns_int($body, 'id');
    $campaign = assert_campaign_owned($id, $tenantId);
    if ((string) $campaign['status'] !== 'draft') {
        json_error('draft のキャンペーンのみ編集できます', 409);
    }

    $hasContents = array_key_exists('contents', $body);
    $replaceTargets = array_key_exists('target_ids', $body) || array_key_exists('group_ids', $body);

    $fields = campaigns_update_fields($body);
    if (array_key_exists('link_mode', $fields) && $fields['link_mode'] !== null) {
        campaigns_validate_in((string) $fields['link_mode'], CAMPAIGN_LINK_MODES, 'link_mode');
    }
    if (array_key_exists('send_mode', $fields) && $fields['send_mode'] !== null) {
        campaigns_validate_in((string) $fields['send_mode'], CAMPAIGN_SEND_MODES, 'send_mode');
    }
    if (array_key_exists('split_interval_min', $fields) && $fields['split_interval_min'] !== null && !in_array((int) $fields['split_interval_min'], CAMPAIGN_SPLIT_INTERVALS, true)) {
        json_error('split_interval_min が不正です', 400);
    }
    if (array_key_exists('subject_template_id', $fields) && $fields['subject_template_id'] !== null) {
        campaigns_assert_template_visible((int) $fields['subject_template_id'], $tenantId, 'subject');
    }
    if (array_key_exists('body_template_id', $fields) && $fields['body_template_id'] !== null) {
        campaigns_assert_template_visible((int) $fields['body_template_id'], $tenantId, 'body');
    }
    $phishTemplate = null;
    if (array_key_exists('phish_template_id', $fields) && $fields['phish_template_id'] !== null) {
        $phishTemplate = campaigns_assert_template_visible((int) $fields['phish_template_id'], $tenantId, 'phish_login');
    }

    $parsedContents = [];
    if ($hasContents) {
        $parsedContents = campaigns_parse_contents($body, $tenantId);
        if (count($parsedContents) === 0) {
            json_error('contents は1件以上必須です', 400);
        }
    }

    $targetIds = $replaceTargets ? campaigns_collect_target_ids($body, $tenantId) : [];

    if ($fields === [] && !$replaceTargets && !$hasContents) {
        json_error('更新項目がありません', 400);
    }

    Db::tx(function () use ($id, $tenantId, $fields, $replaceTargets, $targetIds, $campaign, $phishTemplate, $hasContents, $parsedContents): void {
        campaigns_apply_update($id, $tenantId, $fields);
        
        if ($hasContents) {
            // contents[] が来た場合: DELETE campaign_contents → INSERT → DELETE campaign_targets → insert_targets 再採番 → distribute_contents 再割当
            Db::run('DELETE FROM campaign_contents WHERE campaign_id = ?', [$id]);
            foreach ($parsedContents as $contentNo => $content) {
                Db::run(
                    'INSERT INTO campaign_contents
                     (campaign_id, content_no, subject_template_id, body_template_id, phish_template_id,
                      link_mode, attachment_ext, attachment_zip, suppress_body_url, from_address, beacon_base)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        $id,
                        $contentNo,
                        $content['subject_template_id'],
                        $content['body_template_id'],
                        $content['phish_template_id'],
                        $content['link_mode'],
                        $content['attachment_ext'],
                        $content['attachment_zip'],
                        $content['suppress_body_url'] ?? 0,
                        $content['from_address'] ?? null,
                        $content['beacon_base'] ?? null,
                    ]
                );
            }
            
            // target_ids が無くても contents[] があれば targets を再設定
            if (!$replaceTargets) {
                // 既存の targets を削除して再採番
                $existingTargetIds = Db::all(
                    'SELECT DISTINCT target_id FROM campaign_targets WHERE campaign_id = ?',
                    [$id]
                );
                $targetIds = array_map(fn($row) => (int) $row['target_id'], $existingTargetIds);
                sort($targetIds);
            }
            
            Db::run('DELETE FROM campaign_targets WHERE campaign_id = ?', [$id]);
            $authFlag = $phishTemplate !== null ? ($phishTemplate['auth_flag'] !== null ? (int) $phishTemplate['auth_flag'] : null) : ($campaign['phish_template_id'] !== null ? (int) campaigns_assert_template_visible((int) $campaign['phish_template_id'], $tenantId, 'phish_login')['auth_flag'] : null);
            $fromAddress = array_key_exists('from_address', $fields) ? $fields['from_address'] : ($campaign['from_address'] !== null ? (string) $campaign['from_address'] : null);
            campaigns_insert_targets($id, $targetIds, $authFlag, $fromAddress);
            campaigns_distribute_contents($id, $targetIds);
        } elseif ($replaceTargets) {
            Db::run('DELETE FROM campaign_targets WHERE campaign_id = ?', [$id]);
            $authFlag = $phishTemplate !== null ? ($phishTemplate['auth_flag'] !== null ? (int) $phishTemplate['auth_flag'] : null) : ($campaign['phish_template_id'] !== null ? (int) campaigns_assert_template_visible((int) $campaign['phish_template_id'], $tenantId, 'phish_login')['auth_flag'] : null);
            $fromAddress = array_key_exists('from_address', $fields) ? $fields['from_address'] : ($campaign['from_address'] !== null ? (string) $campaign['from_address'] : null);
            campaigns_insert_targets($id, $targetIds, $authFlag, $fromAddress);
        } else {
            if ($phishTemplate !== null) {
                Db::run(
                    'UPDATE campaign_targets SET auth_flag = ? WHERE campaign_id = ?',
                    [$phishTemplate['auth_flag'] !== null ? (int) $phishTemplate['auth_flag'] : null, $id]
                );
            }
            if (array_key_exists('from_address', $fields)) {
                Db::run('UPDATE campaign_targets SET from_address = ? WHERE campaign_id = ?', [$fields['from_address'], $id]);
            }
        }
    });
    audit('campaign.update', 'campaign_id=' . $id);
    $updated = campaigns_row($id, $tenantId);
    json_out(['success' => true, 'campaign' => $updated, 'target_count' => (int) $updated['target_count']]);
}

function campaigns_handle_delete(array $actor): never
{
    tet2_require_csrf();
    $body = json_body();
    $tenantId = effective_tenant_id($actor, campaigns_optional_int($body, 'tenant_id'));
    $id = campaigns_int($body, 'id');
    $campaign = assert_campaign_owned($id, $tenantId);
    if (!in_array((string) $campaign['status'], ['draft', 'cancelled'], true)) {
        json_error('draft/cancelled のキャンペーンのみ削除できます', 409);
    }

    Db::run('DELETE FROM campaigns WHERE id = ? AND tenant_id = ?', [$id, $tenantId]);
    audit('campaign.delete', 'campaign_id=' . $id);
    json_out(['success' => true]);
}

function campaigns_handle_cancel(array $actor): never
{
    tet2_require_csrf();
    $body = json_body();
    $tenantId = effective_tenant_id($actor, campaigns_optional_int($body, 'tenant_id'));
    $id = campaigns_int($body, 'id');
    assert_campaign_owned($id, $tenantId);

    Db::run('UPDATE campaigns SET status = ? WHERE id = ? AND tenant_id = ?', ['cancelled', $id, $tenantId]);
    audit('campaign.cancel', 'campaign_id=' . $id);
    json_out(['success' => true, 'campaign' => campaigns_row($id, $tenantId)]);
}

/** ビーコンベースURLの候補一覧(既定値 + 既存キャンペーンで使われた beacon_base の重複除き)。 */
function campaigns_handle_beacon_bases(array $actor): never
{
    $tenantId = effective_tenant_id($actor, isset($_GET['tenant_id']) ? (int) $_GET['tenant_id'] : null);
    // 既定値を先頭に。訓練メールの追跡サーバの既定 IP。
    $bases = ['http://85.131.251.224/'];
    $rows = Db::all(
        "SELECT DISTINCT beacon_base FROM campaigns
         WHERE tenant_id = ? AND beacon_base IS NOT NULL AND beacon_base != ''
         ORDER BY beacon_base",
        [$tenantId]
    );
    foreach ($rows as $r) {
        $b = (string) $r['beacon_base'];
        if (!in_array($b, $bases, true)) {
            $bases[] = $b;
        }
    }
    json_out(['success' => true, 'beacon_bases' => $bases]);
}

try {
    $action = $_GET['action'] ?? '';
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $actor = require_role($method === 'GET' ? 'viewer' : 'operator');

    if ($action === 'list' && $method === 'GET') {
        campaigns_handle_list($actor);
    }
    if ($action === 'get' && $method === 'GET') {
        campaigns_handle_get($actor);
    }
    if ($action === 'create' && $method === 'POST') {
        campaigns_handle_create($actor);
    }
    if ($action === 'update' && $method === 'POST') {
        campaigns_handle_update($actor);
    }
    if ($action === 'delete' && $method === 'POST') {
        campaigns_handle_delete($actor);
    }
    if ($action === 'cancel' && $method === 'POST') {
        campaigns_handle_cancel($actor);
    }
    if ($action === 'beacon_bases' && $method === 'GET') {
        campaigns_handle_beacon_bases($actor);
    }
    json_error('不正なアクションです', 400);
} catch (Throwable $e) {
    if (campaigns_is_unique_error($e)) {
        json_error('キャンペーン対象が重複しています', 409);
    }
    error_log($e->getMessage());
    json_error('サーバエラー', 500);
}
