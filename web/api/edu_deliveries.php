<?php declare(strict_types=1); require __DIR__."/../lib/bootstrap.php";
require_once __DIR__ . '/../lib/EduDeliveryLauncher.php';
require_once __DIR__ . '/../lib/EduDeliverySeries.php';

/**
 * 教育配信(edu_deliveries)管理 + launch(受講割当の採番)API。
 * templates.php / campaigns.php と同じ流儀。
 * - delivery_type: elearning(合格制約あり) / awareness_quiz(継続型・合格制約なし)
 * - target_type  : all(実対象者) / group(target_group) / risk(訓練の結果) / individual(個別指定) / position(役職区分)
 * - launch       : 対象者を確定し edu_assignments を採番(access_token 発行) + edu_delivery_questions を確定。
 *   設問は明示指定(question_ids) か 条件抽出(category_ids/difficulty_range/question_count/randomize)。
 */

const EDU_DELIVERY_TYPES = ['elearning', 'awareness_quiz'];
const EDU_TARGET_TYPES   = ['all', 'group', 'risk', 'individual', 'position'];
const EDU_DELIVERY_STATUSES = ['draft', 'scheduled', 'running', 'done', 'cancelled'];
/**
 * 配信の起動契機。phishing_failure は EduAutoEnroll(tet2-edu-enroll.timer)が
 * 訓練失敗者を継続的に自動投入する。new_target は edu_scheduler.php が、登録から
 * new_target_days 日以内の対象者を継続的に自動投入する。manual は launch 操作でのみ対象を確定する。
 */
const EDU_TRIGGERED_BY = ['manual', 'phishing_failure', 'new_target'];

function edu_d_query_int(string $key): ?int
{
    if (!array_key_exists($key, $_GET) || $_GET[$key] === '') {
        return null;
    }
    $v = filter_var($_GET[$key], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($v === false) {
        json_error($key . ' が不正です', 400);
    }
    return (int) $v;
}

function edu_d_body_optional_int(array $body, string $key): ?int
{
    if (!array_key_exists($key, $body) || $body[$key] === null) {
        return null;
    }
    if (!is_int($body[$key]) || $body[$key] < 1) {
        json_error($key . ' が不正です', 400);
    }
    return $body[$key];
}

function edu_d_string(array $body, string $key): string
{
    if (!array_key_exists($key, $body) || !is_string($body[$key]) || trim($body[$key]) === '') {
        json_error($key . ' は必須です', 400);
    }
    return trim($body[$key]);
}

function edu_d_id_body(array $body): int
{
    if (!array_key_exists('id', $body) || !is_int($body['id']) || $body['id'] < 1) {
        json_error('id が不正です', 400);
    }
    return $body['id'];
}

/** int配列を取り出す(なければnull)。要素は正の整数。 */
function edu_d_int_array(array $body, string $key): ?array
{
    if (!array_key_exists($key, $body) || $body[$key] === null) {
        return null;
    }
    if (!is_array($body[$key])) {
        json_error($key . ' は配列で指定してください', 400);
    }
    $out = [];
    foreach ($body[$key] as $v) {
        if (!is_int($v) || $v < 1) {
            json_error($key . ' の要素が不正です', 400);
        }
        $out[] = $v;
    }
    return $out;
}

function edu_d_type(array $body): string
{
    $t = edu_d_string($body, 'delivery_type');
    if (!in_array($t, EDU_DELIVERY_TYPES, true)) {
        json_error('delivery_type が不正です', 400);
    }
    return $t;
}

function edu_d_target_type(array $body): string
{
    $t = edu_d_string($body, 'target_type');
    if (!in_array($t, EDU_TARGET_TYPES, true)) {
        json_error('target_type が不正です', 400);
    }
    return $t;
}

/**
 * 受講トークンの有効期限。配信に deadline があればそれを使う。
 *
 * edu_take.php は token_expiry を検証していたのに、セットするコードがどこにも無く
 * 実測では全件 NULL だった(受講リンクが事実上無期限)。deadline を入れることで
 * 「締切後も受講・採点が通る」問題も同時に閉じる。
 */
function edu_d_token_expiry(array $delivery): ?string
{
    return EduDeliveryLauncher::tokenExpiry($delivery);
}

/** triggered_by。未指定は manual。 */
function edu_d_triggered_by(array $body): string
{
    if (!array_key_exists('triggered_by', $body) || $body['triggered_by'] === null) {
        return 'manual';
    }
    if (!is_string($body['triggered_by']) || !in_array($body['triggered_by'], EDU_TRIGGERED_BY, true)) {
        json_error('triggered_by が不正です', 400);
    }
    return $body['triggered_by'];
}

/**
 * phishing_failure は EduAutoEnroll が対象者を自動決定するため target_type=risk 限定。
 * all/group/individual と組み合わせると、手動で確定した対象と自動投入が二重に走る。
 */
function edu_d_assert_trigger_consistency(string $triggeredBy, string $targetType): void
{
    if ($triggeredBy === 'phishing_failure' && $targetType !== 'risk') {
        json_error('phishing_failure トリガーは target_type=risk と組み合わせてください', 400);
    }
    // 新入社員の配信は、全員・グループ・役職のうち登録から N 日以内の人を入れる。
    if ($triggeredBy === 'new_target' && !in_array($targetType, ['all', 'group', 'position'], true)) {
        json_error('new_target トリガーは target_type=all / group / position と組み合わせてください', 400);
    }
}

/** 新入社員の配信の「登録から N 日以内」(1〜365)。new_target 以外では保存しない。 */
function edu_d_new_target_days(array $body, string $triggeredBy): ?int
{
    if ($triggeredBy !== 'new_target') {
        return null;
    }
    $days = $body['new_target_days'] ?? null;
    if (!is_int($days) || $days < 1 || $days > 365) {
        json_error('新入社員の配信には new_target_days(1〜365)が必要です', 400);
    }
    return $days;
}

function edu_d_assert_owned(int $id, int $tenantId): array
{
    $row = Db::one('SELECT * FROM edu_deliveries WHERE id = ? AND tenant_id = ?', [$id, $tenantId]);
    if ($row === null) {
        json_error('配信が見つかりません', 404);
    }
    return $row;
}

function edu_d_assert_group_owned(int $groupId, int $tenantId): void
{
    $g = Db::one('SELECT id FROM groups WHERE id = ? AND tenant_id = ?', [$groupId, $tenantId]);
    if ($g === null) {
        json_error('グループが見つかりません', 404);
    }
}

function edu_d_assert_material_owned(int $materialId, int $tenantId): void
{
    $material = Db::one(
        'SELECT id FROM edu_materials
         WHERE id = ? AND (tenant_id = ? OR tenant_id IS NULL) AND is_active = 1',
        [$materialId, $tenantId]
    );
    if ($material === null) {
        json_error('教材が見つかりません', 404);
    }
}

function edu_d_assert_targets_owned(array $targetIds, int $tenantId): void
{
    foreach ($targetIds as $targetId) {
        $target = Db::one(
            "SELECT id FROM targets WHERE id = ? AND tenant_id = ? AND status = 'active'",
            [$targetId, $tenantId]
        );
        if ($target === null) {
            json_error('対象者が見つかりません', 404);
        }
    }
}

/** target_type に応じて対象 target_id を確定する(全てテナント内)。規則は EduDeliveryLauncher にある。 */
function edu_d_resolve_targets(array $delivery, int $tenantId): array
{
    try {
        return EduDeliveryLauncher::resolveTargets($delivery, $tenantId);
    } catch (EduDeliveryError $e) {
        json_error($e->getMessage(), $e->getCode());
    }
}

function edu_d_handle_list(array $actor): never
{
    $tenantId = effective_tenant_id($actor, edu_d_query_int('tenant_id'));
    $rows = Db::all(
        'SELECT d.*,
                (SELECT COUNT(*) FROM edu_assignments a WHERE a.delivery_id = d.id) AS assignment_count,
                (SELECT COUNT(*) FROM edu_assignments a WHERE a.delivery_id = d.id AND a.status = \'completed\') AS completed_count
         FROM edu_deliveries d
         WHERE d.tenant_id = ?
         ORDER BY d.id DESC',
        [$tenantId]
    );
    json_out(['success' => true, 'deliveries' => $rows]);
}

function edu_d_handle_get(array $actor): never
{
    $tenantId = effective_tenant_id($actor, edu_d_query_int('tenant_id'));
    $id = edu_d_query_int('id');
    if ($id === null) {
        json_error('id が不正です', 400);
    }
    $delivery = edu_d_assert_owned($id, $tenantId);
    $questions = Db::all(
        'SELECT dq.question_id, dq.sort_order, q.title, q.difficulty, q.question_type
         FROM edu_delivery_questions dq
         INNER JOIN edu_questions q ON q.id = dq.question_id
         WHERE dq.delivery_id = ?
         ORDER BY dq.sort_order, dq.id',
        [$id]
    );
    json_out(['success' => true, 'delivery' => $delivery, 'questions' => $questions]);
}

/**
 * 答え合わせの時機(08 の G18)。指定がなければ、小問は1問ごと、eラーニングは提出後にまとめて。
 */
function edu_d_feedback_mode(array $body, ?string $deliveryType): ?string
{
    if (!array_key_exists('feedback_mode', $body) || $body['feedback_mode'] === null) {
        if ($deliveryType === null) {
            return null;
        }
        return $deliveryType === 'awareness_quiz' ? 'immediate' : 'after_submit';
    }
    if (!in_array($body['feedback_mode'], ['after_submit', 'immediate'], true)) {
        json_error('feedback_mode は after_submit か immediate です', 400);
    }
    return $body['feedback_mode'];
}

/** 真偽値の項目(true/false か 0/1)。未指定なら既定値。 */
function edu_d_flag(array $body, string $key, int $default): int
{
    if (!array_key_exists($key, $body) || $body[$key] === null) {
        return $default;
    }
    $v = $body[$key];
    if ($v === true || $v === 1) {
        return 1;
    }
    if ($v === false || $v === 0) {
        return 0;
    }
    json_error($key . ' は true か false で指定してください', 400);
}

/** 日時の項目。画面の 'YYYY-MM-DDTHH:MM' も受け付け、'YYYY-MM-DD HH:MM:SS' に揃える。 */
function edu_d_datetime(array $body, string $key): ?string
{
    if (!array_key_exists($key, $body) || $body[$key] === null || $body[$key] === '') {
        return null;
    }
    $value = is_string($body[$key]) ? EduDeliveryLauncher::normalizeDateTime($body[$key]) : null;
    if ($value === null) {
        json_error($key . ' は日時(YYYY-MM-DD HH:MM)で指定してください', 400);
    }
    return $value;
}

/** 締切。日付だけ(その日いっぱい)か日時。 */
function edu_d_deadline(array $body): ?string
{
    if (!array_key_exists('deadline', $body) || $body['deadline'] === null || $body['deadline'] === '') {
        return null;
    }
    $raw = is_string($body['deadline']) ? trim($body['deadline']) : '';
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) === 1 && DateTimeImmutable::createFromFormat('!Y-m-d', $raw) !== false) {
        return $raw;
    }
    return edu_d_datetime($body, 'deadline');
}

/** 締切が予約の日時より前になっていないか。 */
function edu_d_assert_schedule_order(?string $scheduledAt, ?string $deadline): void
{
    if ($scheduledAt === null || $deadline === null) {
        return;
    }
    $expiry = EduDeliveryLauncher::tokenExpiry(['deadline' => $deadline]);
    if ($expiry !== null && $expiry <= $scheduledAt) {
        json_error('締切は予約の日時より後にしてください', 400);
    }
}

/** 役職区分の一覧(target_type=position だけ)。targets.position_category の値から選ぶ。 */
function edu_d_target_positions(array $body, string $targetType): ?string
{
    if ($targetType !== 'position') {
        return null;
    }
    $values = $body['target_positions'] ?? null;
    if (!is_array($values) || $values === [] || count(array_unique($values, SORT_REGULAR)) !== count($values)) {
        json_error('役職の配信には target_positions(役職区分の一覧)が必要です', 400);
    }
    foreach ($values as $value) {
        if (!is_string($value) || !in_array($value, TET2_POSITION_CATEGORIES, true)) {
            json_error('target_positions は ' . implode('・', TET2_POSITION_CATEGORIES) . ' から選んでください', 400);
        }
    }
    return json_encode(array_values($values), JSON_UNESCAPED_UNICODE);
}

/**
 * 訓練の結果の区分(target_type=risk だけ)。区分を選ぶときは、終わったキャンペーンの指定を必須にする。
 * 訓練の期間中に同じ手口の教育を出すと、訓練の測定が崩れるため。
 */
function edu_d_risk_results(array $body, string $targetType, string $triggeredBy, ?int $campaignId, int $tenantId): ?string
{
    if (!array_key_exists('risk_results', $body) || $body['risk_results'] === null) {
        return null;
    }
    $values = $body['risk_results'];
    if ($targetType !== 'risk') {
        json_error('risk_results は target_type=risk のときだけ指定できます', 400);
    }
    if ($triggeredBy !== 'manual') {
        json_error('訓練の結果の区分は、自動の投入(phishing_failure)と組み合わせられません', 400);
    }
    if (!is_array($values) || $values === [] || count(array_unique($values, SORT_REGULAR)) !== count($values)) {
        json_error('risk_results は区分の一覧で指定してください', 400);
    }
    foreach ($values as $value) {
        if (!is_string($value) || !in_array($value, EduDeliveryLauncher::RISK_RESULTS, true)) {
            json_error('risk_results は ' . implode(' / ', EduDeliveryLauncher::RISK_RESULTS) . ' から選んでください', 400);
        }
    }
    if ($campaignId === null) {
        json_error('訓練の結果で対象を選ぶときは、キャンペーン(phish_campaign_id)の指定が必要です', 400);
    }
    $campaign = assert_campaign_owned($campaignId, $tenantId);
    if (!in_array((string) $campaign['status'], EduDeliveryLauncher::FINISHED_CAMPAIGN_STATUSES, true)) {
        json_error('訓練が終わっていないキャンペーン(status=' . $campaign['status'] . ')の結果では配信を作れません。訓練の測定を崩さないため、終了後に作成してください', 409);
    }
    return json_encode(array_values($values));
}

/**
 * 配信の設定を検証し、edu_deliveries の列の値に揃える。配信の作成と毎月の配信の系列の作成が使う。
 *
 * @return array{columns: array<string,mixed>, target_ids: list<int>}
 */
function edu_d_parse_config(array $body, int $tenantId): array
{
    $deliveryType = edu_d_type($body);
    $targetType = edu_d_target_type($body);
    $questionCount = edu_d_body_optional_int($body, 'question_count');
    $categoryIds = edu_d_int_array($body, 'category_ids');
    $passScore = edu_d_body_optional_int($body, 'pass_score');
    $targetGroupId = edu_d_body_optional_int($body, 'target_group_id');
    $phishCampaignId = edu_d_body_optional_int($body, 'phish_campaign_id');
    $materialId = edu_d_body_optional_int($body, 'material_id');
    $targetIds = $targetType === 'individual' ? (edu_d_int_array($body, 'target_ids') ?? []) : [];
    $triggeredBy = edu_d_triggered_by($body);
    edu_d_assert_trigger_consistency($triggeredBy, $targetType);

    // difficulty_range: [min,max] 各1-3
    $diffRange = null;
    if (array_key_exists('difficulty_range', $body) && $body['difficulty_range'] !== null) {
        $dr = $body['difficulty_range'];
        if (!is_array($dr) || count($dr) !== 2 || !is_int($dr[0]) || !is_int($dr[1])
            || $dr[0] < 1 || $dr[1] > 3 || $dr[0] > $dr[1]) {
            json_error('difficulty_range は [min,max] (1〜3) で指定してください', 400);
        }
        $diffRange = [$dr[0], $dr[1]];
    }

    // 整合性: elearning は pass_score 必須
    if ($deliveryType === 'elearning' && $passScore === null) {
        json_error('elearning には pass_score が必要です', 400);
    }
    // group 配信は target_group_id 必須 + 所有確認
    if ($targetType === 'group') {
        if ($targetGroupId === null) {
            json_error('group 配信には target_group_id が必要です', 400);
        }
        edu_d_assert_group_owned($targetGroupId, $tenantId);
    }
    if ($targetType === 'individual') {
        if ($targetIds === []) {
            json_error('individual 配信には target_ids が必要です', 400);
        }
        edu_d_assert_targets_owned($targetIds, $tenantId);
    }
    if ($materialId !== null) {
        edu_d_assert_material_owned($materialId, $tenantId);
    }
    // category_ids 所有確認(他テナントのカテゴリ混入=IDOR防止)
    foreach ($categoryIds ?? [] as $cid) {
        if (Db::one('SELECT 1 FROM edu_categories WHERE id = ? AND (tenant_id = ? OR tenant_id IS NULL)', [$cid, $tenantId]) === null) {
            json_error('カテゴリが見つかりません', 404);
        }
    }
    if ($phishCampaignId !== null) {
        assert_campaign_owned($phishCampaignId, $tenantId);
    }

    return [
        'columns' => [
            'title' => edu_d_string($body, 'title'),
            'delivery_type' => $deliveryType,
            'question_count' => $questionCount,
            'category_ids' => $categoryIds !== null ? json_encode($categoryIds) : null,
            'difficulty_range' => $diffRange !== null ? json_encode($diffRange) : null,
            'randomize' => array_key_exists('randomize', $body) ? ($body['randomize'] ? 1 : 0) : 1,
            'pass_score' => $passScore,
            'material_id' => $materialId,
            'target_type' => $targetType,
            'target_group_id' => $targetGroupId,
            'triggered_by' => $triggeredBy,
            'phish_campaign_id' => $phishCampaignId,
            'feedback_mode' => edu_d_feedback_mode($body, $deliveryType),
            'send_invites' => edu_d_flag($body, 'send_invites', 0),
            'target_positions' => edu_d_target_positions($body, $targetType),
            'risk_results' => edu_d_risk_results($body, $targetType, $triggeredBy, $phishCampaignId, $tenantId),
            'new_target_days' => edu_d_new_target_days($body, $triggeredBy),
        ],
        'target_ids' => $targetIds,
    ];
}

function edu_d_handle_create(array $actor): never
{
    tet2_require_csrf();
    $body = json_body();
    $tenantId = effective_tenant_id($actor, edu_d_body_optional_int($body, 'tenant_id'));
    $config = edu_d_parse_config($body, $tenantId);
    // 予約の日時があれば予約(scheduled)で作る。その日時に edu_scheduler.php が開始する。
    $scheduledAt = edu_d_datetime($body, 'scheduled_at');
    $deadline = edu_d_deadline($body);
    edu_d_assert_schedule_order($scheduledAt, $deadline);

    $columns = $config['columns'] + [
        'tenant_id' => $tenantId,
        'status' => $scheduledAt !== null ? 'scheduled' : 'draft',
        'scheduled_at' => $scheduledAt,
        'deadline' => $deadline,
        'created_by' => $actor['id'],
    ];
    $names = array_keys($columns);
    $id = Db::insert(
        'INSERT INTO edu_deliveries (' . implode(', ', $names) . ')
         VALUES (' . implode(', ', array_fill(0, count($names), '?')) . ')',
        array_values($columns)
    );

    foreach ($config['target_ids'] as $targetId) {
        Db::run(
            'INSERT OR IGNORE INTO edu_delivery_targets (delivery_id, target_id) VALUES (?, ?)',
            [$id, $targetId]
        );
    }

    // question_ids 明示指定があれば edu_delivery_questions に積む(テナント所有確認)
    $questionIds = edu_d_int_array($body, 'question_ids');
    if ($questionIds !== null && $questionIds !== []) {
        $sort = 0;
        foreach ($questionIds as $qid) {
            if (Db::one('SELECT 1 FROM edu_questions WHERE id = ? AND (tenant_id = ? OR tenant_id IS NULL)', [$qid, $tenantId]) === null) {
                json_error('設問が見つかりません', 404);
            }
            Db::run(
                'INSERT OR IGNORE INTO edu_delivery_questions (delivery_id, question_id, sort_order) VALUES (?, ?, ?)',
                [$id, $qid, $sort]
            );
            $sort++;
        }
    }

    audit('edu_delivery.create', 'delivery_id=' . $id);
    json_out(['success' => true, 'delivery' => edu_d_assert_owned($id, $tenantId)], 201);
}

function edu_d_handle_update(array $actor): never
{
    tet2_require_csrf();
    $body = json_body();
    $tenantId = effective_tenant_id($actor, edu_d_body_optional_int($body, 'tenant_id'));
    $id = edu_d_id_body($body);
    $delivery = edu_d_assert_owned($id, $tenantId);

    // 開始前(下書きと予約)だけ編集できる
    if (!in_array((string) $delivery['status'], ['draft', 'scheduled'], true)) {
        json_error('開始済みの配信は編集できません', 409);
    }

    $title = array_key_exists('title', $body) ? edu_d_string($body, 'title') : null;
    $passScore = edu_d_body_optional_int($body, 'pass_score');
    $scheduledAt = edu_d_datetime($body, 'scheduled_at');
    // 予約の日時を空(null か空の文字)で送ったら予約を解除する。項目を送らなければ予約は変えない
    $clearSchedule = array_key_exists('scheduled_at', $body) && ($body['scheduled_at'] === null || $body['scheduled_at'] === '');
    $deadline = edu_d_deadline($body);
    edu_d_assert_schedule_order($clearSchedule ? null : ($scheduledAt ?? $delivery['scheduled_at']), $deadline ?? $delivery['deadline']);
    // draft のうちに手動配信⇄自動連携を切り替えられるようにする。
    $triggeredBy = null;
    if (array_key_exists('triggered_by', $body) && $body['triggered_by'] !== null) {
        $triggeredBy = edu_d_triggered_by($body);
        edu_d_assert_trigger_consistency($triggeredBy, (string) $delivery['target_type']);
        if ($triggeredBy === 'new_target' && $delivery['new_target_days'] === null) {
            json_error('新入社員の配信は、作成時に new_target_days を指定してください', 400);
        }
        if ($triggeredBy !== 'manual' && $delivery['risk_results'] !== null) {
            json_error('訓練の結果の区分を持つ配信は、自動の投入に切り替えられません', 400);
        }
    }

    $feedbackMode = edu_d_feedback_mode($body, null);
    $sendInvites = array_key_exists('send_invites', $body) ? edu_d_flag($body, 'send_invites', 0) : null;

    if ($title === null && $passScore === null && $scheduledAt === null && !$clearSchedule && $deadline === null
        && $triggeredBy === null && $feedbackMode === null && $sendInvites === null) {
        json_error('更新項目がありません', 400);
    }
    // 下書きに予約の日時を入れたら予約にする(その日時に edu_scheduler.php が開始する)。予約を解除したら下書きに戻す
    $status = $scheduledAt !== null ? 'scheduled' : ($clearSchedule ? 'draft' : null);

    Db::run(
        'UPDATE edu_deliveries
         SET title = COALESCE(?, title),
             pass_score = COALESCE(?, pass_score),
             scheduled_at = CASE WHEN ? THEN NULL ELSE COALESCE(?, scheduled_at) END,
             deadline = COALESCE(?, deadline),
             triggered_by = COALESCE(?, triggered_by),
             feedback_mode = COALESCE(?, feedback_mode),
             send_invites = COALESCE(?, send_invites),
             status = COALESCE(?, status)
         WHERE id = ? AND tenant_id = ? AND status IN (\'draft\',\'scheduled\')',
        [$title, $passScore, $clearSchedule ? 1 : 0, $scheduledAt, $deadline, $triggeredBy, $feedbackMode, $sendInvites, $status, $id, $tenantId]
    );
    audit('edu_delivery.update', 'delivery_id=' . $id);
    json_out(['success' => true, 'delivery' => edu_d_assert_owned($id, $tenantId)]);
}

/** 毎月の配信の規則(毎月の日、時刻、締切までの日数、終了日)を検証する。 */
function edu_d_series_rule(array $body): array
{
    $day = $body['day_of_month'] ?? null;
    if (!is_int($day) || $day < 1 || $day > 28) {
        json_error('day_of_month は 1〜28 で指定してください', 400);
    }
    $time = $body['time_of_day'] ?? null;
    if (!is_string($time) || preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) !== 1) {
        json_error('time_of_day は HH:MM で指定してください', 400);
    }
    $deadlineDays = $body['deadline_days'] ?? 14;
    if (!is_int($deadlineDays) || $deadlineDays < 1 || $deadlineDays > 90) {
        json_error('deadline_days は 1〜90 で指定してください', 400);
    }
    $endDate = $body['end_date'] ?? null;
    if ($endDate === '') {
        $endDate = null;
    }
    if ($endDate !== null && (!is_string($endDate) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate) !== 1
        || DateTimeImmutable::createFromFormat('!Y-m-d', $endDate) === false)) {
        json_error('end_date は YYYY-MM-DD で指定してください', 400);
    }
    return ['day_of_month' => $day, 'time_of_day' => $time, 'deadline_days' => $deadlineDays, 'end_date' => $endDate];
}

function edu_d_assert_series_owned(int $id, int $tenantId): array
{
    $row = Db::one('SELECT * FROM edu_delivery_series WHERE id = ? AND tenant_id = ?', [$id, $tenantId]);
    if ($row === null) {
        json_error('毎月の配信が見つかりません', 404);
    }
    return $row;
}

/**
 * 毎月の配信(系列)を作る。配信の設定は作成と同じ検証を通し、settings に保存する。
 * 回ごとの配信は edu_scheduler.php が予約の状態で作り、予約の日時に開始する。
 */
function edu_d_handle_series_create(array $actor): never
{
    tet2_require_csrf();
    $body = json_body();
    $tenantId = effective_tenant_id($actor, edu_d_body_optional_int($body, 'tenant_id'));
    $config = edu_d_parse_config($body, $tenantId);
    $columns = $config['columns'];
    // 訓練の結果は特定のキャンペーンの後に1回だけ意味を持つ。自動の投入の配信は開始後に投入し続けるので、系列にしない。
    if ($columns['target_type'] === 'risk' || $columns['triggered_by'] !== 'manual') {
        json_error('毎月の配信は、全員・グループ・役職・個別の対象の手動の配信だけにできます', 400);
    }
    // 設問は回ごとに条件から選び直す(過去の回で出した設問を外すため)。
    if (!empty($body['question_ids'])) {
        json_error('毎月の配信では設問を明示で指定できません。カテゴリと問題数で指定してください', 400);
    }
    $rule = edu_d_series_rule($body);
    $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo'));
    $next = EduDeliverySeries::nextOccurrence($rule['day_of_month'], $rule['time_of_day'], $now);
    if ($rule['end_date'] !== null && $rule['end_date'] < $next->format('Y-m-d')) {
        json_error('終了日が最初の回(' . $next->format('Y-m-d') . ')より前です', 400);
    }
    $settings = $columns;
    unset($settings['title']);
    $settings['target_ids'] = $config['target_ids'];

    $id = Db::insert(
        'INSERT INTO edu_delivery_series
         (tenant_id, title, settings, day_of_month, time_of_day, deadline_days, next_run_at, end_date, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$tenantId, $columns['title'], json_encode($settings, JSON_UNESCAPED_UNICODE), $rule['day_of_month'],
         $rule['time_of_day'], $rule['deadline_days'], $next->format('Y-m-d H:i:s'), $rule['end_date'], $actor['id']]
    );
    audit('edu_delivery_series.create', 'series_id=' . $id);
    json_out(['success' => true, 'series' => edu_d_assert_series_owned($id, $tenantId)], 201);
}

function edu_d_handle_series_list(array $actor): never
{
    $tenantId = effective_tenant_id($actor, edu_d_query_int('tenant_id'));
    $rows = Db::all(
        'SELECT s.id, s.title, s.day_of_month, s.time_of_day, s.deadline_days, s.next_run_at, s.end_date,
                s.is_active, s.created_at, s.settings,
                (SELECT COUNT(*) FROM edu_deliveries d WHERE d.series_id = s.id) AS delivery_count
         FROM edu_delivery_series s
         WHERE s.tenant_id = ?
         ORDER BY s.id DESC',
        [$tenantId]
    );
    json_out(['success' => true, 'series' => $rows]);
}

/** 系列を止める。作成済みの予約の配信は残る(不要なら配信の一覧から削除する)。 */
function edu_d_handle_series_stop(array $actor): never
{
    tet2_require_csrf();
    $body = json_body();
    $tenantId = effective_tenant_id($actor, edu_d_body_optional_int($body, 'tenant_id'));
    $id = edu_d_id_body($body);
    edu_d_assert_series_owned($id, $tenantId);
    Db::run('UPDATE edu_delivery_series SET is_active = 0 WHERE id = ? AND tenant_id = ?', [$id, $tenantId]);
    audit('edu_delivery_series.stop', 'series_id=' . $id);
    json_out(['success' => true, 'series' => edu_d_assert_series_owned($id, $tenantId)]);
}

/**
 * 配信を開始する。処理は EduDeliveryLauncher に切り出してあり、予約の配信を開始する
 * CLI(db/edu_scheduler.php)も同じ処理を呼ぶ。案内メールは配信の send_invites=1 のときだけ送る。
 */
function edu_d_handle_launch(array $actor): never
{
    tet2_require_csrf();
    $body = json_body();
    $tenantId = effective_tenant_id($actor, edu_d_body_optional_int($body, 'tenant_id'));
    $id = edu_d_id_body($body);
    $delivery = edu_d_assert_owned($id, $tenantId);

    try {
        $result = EduDeliveryLauncher::launch($delivery, $tenantId);
    } catch (EduDeliveryError $e) {
        json_error($e->getMessage(), $e->getCode());
    }

    audit('edu_delivery.launch', 'delivery_id=' . $id . ',assigned=' . $result['assigned'] . ',mail=' . $result['mail_sent']);
    json_out([
        'success' => true,
        'status' => 'running',
        'assigned' => $result['assigned'],
        'mail_sent' => $result['mail_sent'],
        'question_count' => $result['question_count'],
    ]);
}

/**
 * remind: 指定配信の未完了受講者(status assigned/started)へ受講URL付き催促メールを送る。
 * launch 済み(running)の配信が対象。トークンは既存 assignment のものを再利用(新規発行しない)。
 */
function edu_d_handle_remind(array $actor): never
{
    tet2_require_csrf();
    require_once __DIR__ . '/../lib/EduMailer.php';
    $body = json_body();
    $tenantId = effective_tenant_id($actor, edu_d_body_optional_int($body, 'tenant_id'));
    $id = edu_d_id_body($body);
    $delivery = edu_d_assert_owned($id, $tenantId);

    if ((string) $delivery['status'] !== 'running') {
        json_error('開始済み(running)の配信のみ催促できます(status=' . $delivery['status'] . ')', 409);
    }
    $blocked = TenantStatus::sendBlockReason($tenantId);
    if ($blocked !== null) {
        json_error($blocked, 409);
    }

    // 未完了者 + メールアドレス(自テナントのみ。IDOR は tenant_id 一致で担保)
    $rows = Db::all(
        "SELECT a.access_token, t.email, t.name
         FROM edu_assignments a
         INNER JOIN targets t ON t.id = a.target_id
         WHERE a.delivery_id = ? AND a.tenant_id = ? AND a.status IN ('assigned','started')",
        [$id, $tenantId]
    );
    if ($rows === []) {
        json_out(['success' => true, 'sent' => 0, 'failed' => 0, 'targets' => 0, 'message' => '未完了の受講者はいません']);
    }

    $title = (string) $delivery['title'];
    $sent = 0;
    $failed = 0;
    foreach ($rows as $r) {
        $url = EduMailer::takeUrl((string) $r['access_token']);
        $name = trim((string) ($r['name'] ?? ''));
        $greeting = $name !== '' ? ($name . ' 様') : 'ご担当者 様';
        $subject = '【受講のお願い】' . $title;
        $mailBody = $greeting . "\n\n"
            . 'セキュリティ教育「' . $title . "」が未受講です。\n"
            . "下記URLよりご受講ください（所要5〜10分・ログイン不要）。\n\n"
            . $url . "\n\n"
            . "※本メールは自動送信です。ご不明点は管理者へお問い合わせください。\n";
        if (EduMailer::send((string) $r['email'], $subject, $mailBody)) {
            $sent++;
        } else {
            $failed++;
        }
    }

    audit('edu_delivery.remind', 'delivery_id=' . $id . ',sent=' . $sent . ',failed=' . $failed);
    json_out([
        'success' => true,
        'targets' => count($rows),
        'sent' => $sent,
        'failed' => $failed,
    ]);
}

function edu_d_handle_delete(array $actor): never
{
    tet2_require_csrf();
    $body = json_body();
    $tenantId = effective_tenant_id($actor, edu_d_body_optional_int($body, 'tenant_id'));
    $id = edu_d_id_body($body);
    edu_d_assert_owned($id, $tenantId);

    // 受講開始のUPDATEと競合しても履歴を消さないよう、判定と削除を1文で行う。
    $deleted = Db::run(
        "DELETE FROM edu_deliveries WHERE id = ? AND tenant_id = ?
         AND NOT EXISTS (
             SELECT 1 FROM edu_assignments
             WHERE delivery_id = edu_deliveries.id AND status IN ('started','completed')
         )",
        [$id, $tenantId]
    );
    if ($deleted === 0) {
        edu_d_assert_owned($id, $tenantId);
        json_error('受講が開始された配信は削除できません', 409);
    }
    audit('edu_delivery.delete', 'delivery_id=' . $id);
    json_out(['success' => true]);
}

try {
    $action = $_GET['action'] ?? '';
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $actor = require_role($method === 'GET' ? 'viewer' : 'operator');

    if ($action === 'list' && $method === 'GET') {
        edu_d_handle_list($actor);
    }
    if ($action === 'get' && $method === 'GET') {
        edu_d_handle_get($actor);
    }
    if ($action === 'create' && $method === 'POST') {
        edu_d_handle_create($actor);
    }
    if ($action === 'update' && $method === 'POST') {
        edu_d_handle_update($actor);
    }
    if ($action === 'launch' && $method === 'POST') {
        edu_d_handle_launch($actor);
    }
    if ($action === 'remind' && $method === 'POST') {
        edu_d_handle_remind($actor);
    }
    if ($action === 'delete' && $method === 'POST') {
        edu_d_handle_delete($actor);
    }
    if ($action === 'series_list' && $method === 'GET') {
        edu_d_handle_series_list($actor);
    }
    if ($action === 'series_create' && $method === 'POST') {
        edu_d_handle_series_create($actor);
    }
    if ($action === 'series_stop' && $method === 'POST') {
        edu_d_handle_series_stop($actor);
    }
    json_error('不正なアクションです', 400);
} catch (Throwable $e) {
    error_log($e->getMessage());
    json_error('サーバエラー', 500);
}
