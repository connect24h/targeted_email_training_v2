<?php declare(strict_types=1); require __DIR__."/../lib/bootstrap.php";
require_once __DIR__ . '/../lib/CampaignDraftFactory.php';

const CAMPAIGN_LINK_MODES = ['link', 'attachment', 'form', 'qr'];
const CAMPAIGN_SEND_MODES = ['normal', 'split', 'slow'];
const CAMPAIGN_SPLIT_INTERVALS = [5, 15, 30, 60];
const CAMPAIGN_MAX_CONTENTS = 100;

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
 * 空文字を許容する任意文字列。未指定/null/空文字は NULL 扱い。
 * campaigns_optional_string は空文字を「不正」とするため、任意項目
 * (test_redirect_emails 等。is_test OFF 時は空で送られる)には本関数を使う。
 */
function campaigns_optional_string_or_null(array $body, string $key): ?string
{
    if (!array_key_exists($key, $body) || $body[$key] === null) {
        return null;
    }
    if (!is_string($body[$key])) {
        json_error($key . ' が不正です', 400);
    }
    $v = trim($body[$key]);
    return $v === '' ? null : $v;
}

/**
 * 添付ファイル名の接頭辞（任意）。未指定/null/空は NULL（PipelineRunner 側で 'kunren' に
 * フォールバックする）。指定時は明示バリデーションで危険な値を拒否する（第一関門）。
 *
 * この値は最終的に create_beacon_files.py が `{prefix}{tracking_id}.{ext}` のファイル名にし、
 * send_email.py がその basename を Content-Disposition ヘッダに載せる。そのため:
 *  - パス区切り・親ディレクトリ・制御文字(CR/LF/NUL含む)・FS予約記号を含む値は 400 で拒否
 *  - list.csv が cp932 経路を通るため、cp932 で往復できない文字（絵文字・機種依存文字）も拒否
 *    （通すと本番送信ワーカーで文字化け・例外になる。本機能の最弱点なのでここで確実に止める）
 */
function campaigns_optional_attachment_filename(array $body, string $key = 'attachment_filename'): ?string
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
    if (mb_strlen($v, 'UTF-8') > 40) {
        json_error('添付ファイル名の接頭辞は40文字以内にしてください', 400);
    }
    if (preg_match('#[/\\\\]#', $v)
        || strpos($v, '..') !== false
        || preg_match('/[\x00-\x1f\x7f]/', $v)
        || preg_match('/[:*?"<>|]/', $v)
        || $v[0] === '.') {
        json_error('添付ファイル名の接頭辞に使用できない文字が含まれています', 400);
    }
    // cp932 往復一致チェック（list.csv が cp932 を通るため）。
    $roundtrip = mb_convert_encoding(mb_convert_encoding($v, 'CP932', 'UTF-8'), 'UTF-8', 'CP932');
    if ($roundtrip !== $v) {
        json_error('添付ファイル名の接頭辞に使用できない文字（絵文字・機種依存文字など）が含まれています', 400);
    }
    return $v;
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

/**
 * 種明かしページの選択(G29)。null / 0 は既定(reveal.html)。指定時は自テナントのページだけ許す(他テナントは選べない)。
 */
function campaigns_optional_reveal_page_id(array $body, int $tenantId, string $key = 'reveal_page_id'): ?int
{
    if (!array_key_exists($key, $body) || $body[$key] === null || $body[$key] === 0 || $body[$key] === '') {
        return null;
    }
    if (!is_int($body[$key]) || $body[$key] < 1) {
        json_error($key . ' が不正です', 400);
    }
    require_once __DIR__ . '/../lib/RevealPages.php';
    if (RevealPages::find($body[$key], $tenantId) === null) {
        json_error('選択した種明かしページが見つかりません', 400);
    }
    return $body[$key];
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

function campaigns_assert_group_owned(int $groupId, int $tenantId): array
{
    $group = Db::one(
        "SELECT id, name, kind FROM groups WHERE id = ? AND tenant_id = ? AND status = 'active'",
        [$groupId, $tenantId]
    );
    if ($group === null) {
        json_error('グループが見つかりません', 404);
    }
    return $group;
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
        $group = campaigns_assert_group_owned($groupId, $tenantId);
        $isAllMembers = ($group['kind'] ?? '') === 'all' || ($group['name'] ?? '') === '全職員';
        $rows = $isAllMembers
            ? Db::all(
                "SELECT id FROM targets
                 WHERE tenant_id = ? AND status = 'active' AND is_test = 0 ORDER BY id",
                [$tenantId]
            )
            : Db::all(
                "SELECT t.id
                 FROM targets t
                 INNER JOIN target_group tg ON tg.target_id = t.id
                 WHERE t.tenant_id = ? AND tg.group_id = ? AND t.status = 'active'
                 ORDER BY t.id",
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

    // 配信方式: 'all' は各対象者に全コンテンツを送る(テスト用)。'distribute'(既定)は従来のラウンドロビン均等割り。
    $mode = Db::one('SELECT content_delivery FROM campaigns WHERE id = ?', [$campaignId]);
    $deliveryMode = $mode !== null ? (string) ($mode['content_delivery'] ?? 'distribute') : 'distribute';

    if ($deliveryMode === 'all') {
        // 各対象者に全コンテンツ分のレコードを作る。既存レコード(insert_targets が作った1件)を
        // 先頭コンテンツに割り当て、2件目以降のコンテンツは新規レコード(固有 tracking_id)を追加する。
        // これにより 2-a(コンテンツ別の開封追跡)が tracking_id 単位で自動的に成立する。
        foreach ($targetIds as $targetId) {
            $existingKoban = Db::one(
                'SELECT koban FROM campaign_targets WHERE campaign_id = ? AND target_id = ? AND content_no IS NULL LIMIT 1',
                [$campaignId, $targetId]
            );
            $baseKoban = $existingKoban !== null ? (int) ($existingKoban['koban'] ?? 1) : 1;
            foreach ($contents as $ci => $content) {
                $contentNo = (int) $content['content_no'];
                $authFlag = $content['auth_flag'] !== null ? (int) $content['auth_flag'] : null;
                if ($ci === 0) {
                    // 先頭コンテンツ: insert_targets が作った既存の空レコードを更新。
                    Db::run(
                        'UPDATE campaign_targets SET content_no = ?, auth_flag = ? WHERE campaign_id = ? AND target_id = ? AND content_no IS NULL',
                        [$contentNo, $authFlag, $campaignId, $targetId]
                    );
                } else {
                    // 2件目以降: 新規レコード。固有 tracking_id を発行(コンテンツ別追跡の基軸)。
                    Db::run(
                        'INSERT INTO campaign_targets
                         (campaign_id, target_id, tracking_id, koban, auth_flag, from_address, send_status, content_no)
                         SELECT ?, ?, ?, ?, ?, from_address, ?, ?
                         FROM campaign_targets WHERE campaign_id = ? AND target_id = ? AND content_no = ? LIMIT 1',
                        [$campaignId, $targetId, campaigns_generate_tracking_id(), $baseKoban,
                         $authFlag, 'pending', $contentNo,
                         $campaignId, $targetId, (int) $contents[0]['content_no']]
                    );
                }
            }
        }
        return;
    }

    // 従来: ラウンドロビンで content を割り当て(均等割り)。
    foreach ($targetIds as $index => $targetId) {
        $content = $contents[$index % $contentCount];
        $contentNo = (int) $content['content_no'];
        $authFlag = $content['auth_flag'] !== null ? (int) $content['auth_flag'] : null;

        Db::run(
            'UPDATE campaign_targets SET content_no = ?, auth_flag = ? WHERE campaign_id = ? AND target_id = ?',
            [$contentNo, $authFlag, $campaignId, $targetId]
        );
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
        'SELECT c.id, c.tenant_id, c.name, c.status, c.closed_at, c.start_at, c.end_at, c.is_test, c.created_at,
                c.content_delivery, COUNT(DISTINCT ct.target_id) AS target_count,
                (SELECT COUNT(*) FROM campaign_contents cc WHERE cc.campaign_id=c.id) AS content_count
         FROM campaigns c
         LEFT JOIN campaign_targets ct ON ct.campaign_id = c.id
         WHERE c.tenant_id = ? AND c.deleted_at IS NULL
         GROUP BY c.id, c.tenant_id, c.name, c.status, c.start_at, c.end_at, c.is_test,
                  c.created_at, c.content_delivery
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
    $campaign = campaigns_row($id, $tenantId);
    // 編集フォーム復元用に、コンテンツ構成と対象者ID一覧も返す。
    $contents = Db::all(
        'SELECT content_no, subject_template_id, body_template_id, phish_template_id,
                link_mode, attachment_ext, attachment_filename, attachment_zip, from_address, beacon_base,
                suppress_body_url, suppress_prefill_email
         FROM campaign_contents WHERE campaign_id = ? ORDER BY content_no',
        [$id]
    );
    // 対象者ID(重複除去。all配信は同一 target が複数行あるため DISTINCT)。
    $targetRows = Db::all(
        'SELECT DISTINCT target_id FROM campaign_targets WHERE campaign_id = ? ORDER BY koban',
        [$id]
    );
    $targetIds = array_map(static fn ($r) => (int) $r['target_id'], $targetRows);
    json_out([
        'success'    => true,
        'campaign'   => $campaign,
        'contents'   => $contents,
        'target_ids' => $targetIds,
    ]);
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
        'attachment_filename' => campaigns_optional_attachment_filename($body),
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
        // 配信方式: 'all'=全員に全コンテンツ(テスト用) / 'distribute'=従来の均等割り(既定)。
        'content_delivery' => (campaigns_optional_string($body, 'content_delivery') === 'all') ? 'all' : 'distribute',
        // テスト宛先(カンマ区切り)。is_test時、実Toをここに均等分配する(PipelineRunnerがemail検証)。
        // is_test OFF 時は空欄で送られるため、空文字は NULL 扱い(不正としない)。
        'test_redirect_emails' => campaigns_optional_string_or_null($body, 'test_redirect_emails'),
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
    if (count($rawContents) > CAMPAIGN_MAX_CONTENTS) {
        json_error('contents は100件以内で指定してください', 400);
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
        $attachmentFilename = campaigns_optional_attachment_filename($content);
        $attachmentZip = campaigns_optional_bool_int($content, 'attachment_zip') ?? 0;
        $suppressBodyUrl = campaigns_optional_bool_int($content, 'suppress_body_url') ?? 0;
        $suppressPrefillEmail = campaigns_optional_bool_int($content, 'suppress_prefill_email') ?? 0;
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
            'attachment_filename' => $attachmentFilename,
            'attachment_zip' => $attachmentZip,
            'suppress_body_url' => $suppressBodyUrl,
            'suppress_prefill_email' => $suppressPrefillEmail,
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
            'attachment_filename' => $firstContent['attachment_filename'] ?? null,
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
            // 配信方式: 'all'=全員に全コンテンツ / 'distribute'=均等割り(既定)。
            'content_delivery' => (campaigns_optional_string($body, 'content_delivery') === 'all') ? 'all' : 'distribute',
            // テスト宛先(カンマ区切り)。is_test時、実Toをここに均等分配する(PipelineRunnerがemail検証)。
            // is_test OFF 時は空欄で送られるため、空文字は NULL 扱い(不正としない)。
            'test_redirect_emails' => campaigns_optional_string_or_null($body, 'test_redirect_emails'),
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
                'attachment_filename' => $data['attachment_filename'] ?? null,
                'attachment_zip' => $data['attachment_zip'],
            ]
        ];
    }

    // 種明かしページの選択(G29)。他テナントのページは選べない。
    $data['reveal_page_id'] = campaigns_optional_reveal_page_id($body, $tenantId);

    $targetIds = campaigns_collect_target_ids($body, $tenantId);

    $id = Db::tx(function () use ($tenantId, $actor, $data, $targetIds, $phishTemplate, $parsedContents): int {
        $campaignId = Db::insert(
            'INSERT INTO campaigns
             (tenant_id, name, status, subject_template_id, body_template_id, phish_template_id,
              from_address, from_domain, beacon_base, link_mode, attachment_ext, attachment_filename, attachment_zip, send_mode,
              split_count, split_interval_min, weekdays_only, business_start, business_end,
              start_at, end_at, is_test, content_delivery, test_redirect_emails, reveal_page_id, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
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
                $data['attachment_filename'] ?? null,
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
                $data['content_delivery'],
                $data['test_redirect_emails'] ?? null,
                $data['reveal_page_id'] ?? null,
                $actor['id'],
            ]
        );
        Db::run('UPDATE campaigns SET data_dir = ? WHERE id = ? AND tenant_id = ?', [campaigns_data_dir($tenantId, $campaignId), $campaignId, $tenantId]);
        
        // campaign_contents にコンテンツを登録
        foreach ($parsedContents as $contentNo => $content) {
            Db::run(
                'INSERT INTO campaign_contents
                 (campaign_id, content_no, subject_template_id, body_template_id, phish_template_id,
                  link_mode, attachment_ext, attachment_filename, attachment_zip, suppress_body_url, suppress_prefill_email,
                  from_address, beacon_base)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $campaignId,
                    $contentNo,
                    $content['subject_template_id'],
                    $content['body_template_id'],
                    $content['phish_template_id'],
                    $content['link_mode'],
                    $content['attachment_ext'],
                    $content['attachment_filename'] ?? null,
                    $content['attachment_zip'],
                    $content['suppress_body_url'] ?? 0,
                    $content['suppress_prefill_email'] ?? 0,
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
    foreach (['name', 'from_address', 'from_domain', 'link_mode', 'attachment_ext', 'send_mode', 'business_start', 'business_end', 'start_at', 'end_at', 'content_delivery'] as $key) {
        if (array_key_exists($key, $body)) {
            $fields[$key] = campaigns_optional_string($body, $key);
            if (in_array($key, ['name', 'from_address', 'link_mode', 'send_mode', 'start_at', 'end_at'], true) && $fields[$key] === null) {
                json_error($key . ' が不正です', 400);
            }
        }
    }
    // test_redirect_emails は任意項目。is_test OFF 時は空欄で送られるため空文字を NULL 扱いにする。
    if (array_key_exists('test_redirect_emails', $body)) {
        $fields['test_redirect_emails'] = campaigns_optional_string_or_null($body, 'test_redirect_emails');
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
    // attachment_filename はファイル名サニタイズ・cp932検証を伴うため個別に処理する。
    if (array_key_exists('attachment_filename', $body)) {
        $fields['attachment_filename'] = campaigns_optional_attachment_filename($body);
    }
    return $fields;
}

function campaigns_apply_update(int $campaignId, int $tenantId, array $fields): void
{
    $allowed = [
        'name', 'subject_template_id', 'body_template_id', 'phish_template_id', 'from_address',
        'from_domain', 'beacon_base', 'link_mode', 'attachment_ext', 'attachment_filename', 'attachment_zip', 'send_mode', 'split_count',
        'split_interval_min', 'weekdays_only', 'business_start', 'business_end', 'start_at',
        'end_at', 'is_test', 'content_delivery', 'test_redirect_emails', 'reveal_page_id',
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
    // 種明かしページの選択(G29)。他テナントのページは選べない。
    if (array_key_exists('reveal_page_id', $body)) {
        $fields['reveal_page_id'] = campaigns_optional_reveal_page_id($body, $tenantId);
    }
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
                      link_mode, attachment_ext, attachment_filename, attachment_zip, suppress_body_url, suppress_prefill_email,
                      from_address, beacon_base)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        $id,
                        $contentNo,
                        $content['subject_template_id'],
                        $content['body_template_id'],
                        $content['phish_template_id'],
                        $content['link_mode'],
                        $content['attachment_ext'],
                        $content['attachment_filename'] ?? null,
                        $content['attachment_zip'],
                        $content['suppress_body_url'] ?? 0,
                        $content['suppress_prefill_email'] ?? 0,
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
            campaigns_distribute_contents($id, $targetIds);
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

/**
 * キャンペーン名だけを変更する。
 *
 * 通常の update は draft 限定(送信データの整合を守るため)だが、name は宛先・
 * テンプレ・添付などの送信データに一切影響しない表示ラベルなので、status に
 * 関わらず変更できる専用経路にする。name 以外のカラムは触らない。
 */
function campaigns_handle_rename(array $actor): never
{
    tet2_require_csrf();
    $body = json_body();
    $tenantId = effective_tenant_id($actor, campaigns_optional_int($body, 'tenant_id'));
    $id = campaigns_int($body, 'id');
    // 削除済み/他テナントはここで404
    assert_campaign_owned($id, $tenantId);
    // 必須・空文字拒否・trim
    $name = campaigns_string($body, 'name');

    Db::run('UPDATE campaigns SET name = ? WHERE id = ? AND tenant_id = ?', [$name, $id, $tenantId]);
    audit('campaign.rename', 'campaign_id=' . $id);
    $updated = campaigns_row($id, $tenantId);
    json_out(['success' => true, 'campaign' => $updated, 'target_count' => (int) $updated['target_count']]);
}

/**
 * is_test(本番系/テスト系)を後から切り替える専用 API。
 * 既存 update は draft 限定だが、is_test は送信内容ではなく分類ラベルで、後から
 * 変えても送信済みメールに影響しない。よって status に関わらず(running/done でも)
 * 切り替えられる。rename と同型: 分類だけを status 非依存で UPDATE する。
 */
function campaigns_handle_set_test(array $actor): never
{
    tet2_require_csrf();
    $body = json_body();
    $tenantId = effective_tenant_id($actor, campaigns_optional_int($body, 'tenant_id'));
    $id = campaigns_int($body, 'id');
    // 削除済み/他テナントはここで404
    $campaign = assert_campaign_owned($id, $tenantId);
    if ($campaign['closed_at'] !== null) {
        json_error('クローズ済みのキャンペーンは分類を変更できません', 409);
    }
    // 必須(欠落は400)。0/1 に正規化(create 経路と同じ campaigns_optional_bool_int を流用。
    // campaigns_int は <1 を拒否するため is_test=0 が通らず使えない)。
    $isTest = campaigns_optional_bool_int($body, 'is_test');
    if ($isTest === null) {
        json_error('is_test が不正です', 400);
    }

    if (Db::run('UPDATE campaigns SET is_test = ? WHERE id = ? AND tenant_id = ? AND closed_at IS NULL', [$isTest, $id, $tenantId]) !== 1) {
        json_error('クローズ済みのキャンペーンは分類を変更できません', 409);
    }
    audit('campaign.set_test', 'campaign_id=' . $id . ',is_test=' . $isTest);
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

    // 論理削除(soft delete): 実データは残し deleted_at をセットする。全状態から削除可能。
    // 実データ(ビーコン/link-html/DB)は 90日後に cron(tet2-purge-campaigns.py)が物理削除する。
    // 実行中(running/scheduled)なら多重送信を防ぐため、未実行バッチを止めて停止フラグを立てる。
    if (in_array((string) $campaign['status'], ['running', 'scheduled', 'paused'], true)) {
        Db::run("UPDATE send_schedule SET status='cancelled' WHERE campaign_id=? AND status IN ('queued')", [$id]);
        $dir = (string) ($campaign['data_dir'] ?? '');
        if ($dir !== '' && is_dir($dir)) {
            @file_put_contents(rtrim($dir, '/') . '/stop_sending.flag', "deleted\n");
        }
    }

    $pausedRules = Db::tx(function () use ($id, $tenantId): int {
        // 論理削除でも秘密値だけは即時に物理削除する。失敗時は削除全体をrollbackする。
        Db::run('DELETE FROM credential_captures WHERE campaign_id=? AND tenant_id=?', [$id, $tenantId]);
        Db::run("UPDATE campaigns SET deleted_at = datetime('now','localtime'), status='cancelled' WHERE id = ? AND tenant_id = ?", [$id, $tenantId]);
        return Db::run(
            "UPDATE campaign_automations SET status='paused', updated_at=datetime('now','localtime')
             WHERE source_campaign_id=? AND tenant_id=? AND status='active'",
            [$id, $tenantId]
        );
    });
    if ($pausedRules > 0) {
        audit('campaign_automation.source_deleted', 'source_campaign_id=' . $id . ',paused_rules=' . $pausedRules);
    }
    audit('campaign.delete', 'campaign_id=' . $id . ',soft=1');
    json_out(['success' => true]);
}

function campaigns_handle_cancel(array $actor): never
{
    tet2_require_csrf();
    $body = json_body();
    $tenantId = effective_tenant_id($actor, campaigns_optional_int($body, 'tenant_id'));
    $id = campaigns_int($body, 'id');
    $campaign = assert_campaign_owned($id, $tenantId);
    if ($campaign['closed_at'] !== null) {
        json_error('クローズ済みのキャンペーンは変更できません', 409);
    }
    if (Db::run('UPDATE campaigns SET status = ? WHERE id = ? AND tenant_id = ? AND closed_at IS NULL', ['cancelled', $id, $tenantId]) !== 1) {
        json_error('クローズ済みのキャンペーンは変更できません', 409);
    }
    audit('campaign.cancel', 'campaign_id=' . $id);
    json_out(['success' => true, 'campaign' => campaigns_row($id, $tenantId)]);
}

/**
 * キャンペーンを複製する(再利用/再実行の土台)。
 * 設定・コンテンツ構成・対象者をコピーして新規 draft を作る。
 * コピーしないもの: 送信履歴(events/delivery_log)・送信状態・tracking_id(新規採番)・
 * 送信スケジュール・レポートスナップショット・deleted_at。日時は再設定前提で引き継がない。
 */
function campaigns_handle_duplicate(array $actor): never
{
    tet2_require_csrf();
    $body = json_body();
    $tenantId = effective_tenant_id($actor, campaigns_optional_int($body, 'tenant_id'));
    $id = campaigns_int($body, 'id');
    try {
        $newId = (new CampaignDraftFactory())->duplicate($id, $tenantId, (int) ($actor['id'] ?? 0));
    } catch (CampaignDraftNotFoundException $e) {
        json_error($e->getMessage(), 404);
    } catch (CampaignDraftValidationException $e) {
        json_error($e->getMessage(), 409);
    }

    audit('campaign.duplicate', 'src=' . $id . ',new=' . $newId);
    json_out(['success' => true, 'campaign' => campaigns_row($newId, $tenantId)]);
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
    if ($action === 'rename' && $method === 'POST') {
        campaigns_handle_rename($actor);
    }
    if ($action === 'set_test' && $method === 'POST') {
        campaigns_handle_set_test($actor);
    }
    if ($action === 'delete' && $method === 'POST') {
        campaigns_handle_delete($actor);
    }
    if ($action === 'cancel' && $method === 'POST') {
        campaigns_handle_cancel($actor);
    }
    if ($action === 'duplicate' && $method === 'POST') {
        campaigns_handle_duplicate($actor);
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
