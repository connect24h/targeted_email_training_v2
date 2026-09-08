<?php declare(strict_types=1); require __DIR__."/../lib/bootstrap.php";

/**
 * 教育配信(edu_deliveries)管理 + launch(受講割当の採番)API。
 * templates.php / campaigns.php と同じ流儀。
 * - delivery_type: elearning(合格制約あり) / awareness_quiz(継続型・合格制約なし)
 * - target_type  : all(実対象者) / group(target_group) / risk(訓練失敗者) / individual(個別指定)
 * - launch       : 対象者を確定し edu_assignments を採番(access_token 発行) + edu_delivery_questions を確定。
 *   設問は明示指定(question_ids) か 条件抽出(category_ids/difficulty_range/question_count/randomize)。
 */

const EDU_DELIVERY_TYPES = ['elearning', 'awareness_quiz'];
const EDU_TARGET_TYPES   = ['all', 'group', 'risk', 'individual'];
const EDU_DELIVERY_STATUSES = ['draft', 'scheduled', 'running', 'done', 'cancelled'];
/**
 * 配信の起動契機。phishing_failure は EduAutoEnroll(tet2-edu-enroll.timer)が
 * 訓練失敗者を継続的に自動投入する。manual は launch 操作でのみ対象を確定する。
 */
const EDU_TRIGGERED_BY = ['manual', 'phishing_failure'];

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
    $deadline = $delivery['deadline'] ?? null;
    if (!is_string($deadline) || trim($deadline) === '') {
        return null;
    }
    $deadline = trim($deadline);
    // 日付のみ(YYYY-MM-DD)なら、その日いっぱいを有効にする。
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $deadline) === 1) {
        return $deadline . ' 23:59:59';
    }
    return $deadline;
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

/** access_token: 32桁hex。受講者URLに露出するため campaign の10桁より長く、推測困難に。 */
function edu_d_generate_token(): string
{
    for ($i = 0; $i < 10; $i++) {
        $token = bin2hex(random_bytes(16));
        if (Db::one('SELECT 1 FROM edu_assignments WHERE access_token = ?', [$token]) === null) {
            return $token;
        }
    }
    throw new RuntimeException('access_token を生成できません');
}

/** target_type に応じて対象 target_id を確定する(全てテナント内)。 */
function edu_d_resolve_targets(array $delivery, int $tenantId): array
{
    $type = (string) $delivery['target_type'];
    if ($type === 'all') {
        $rows = Db::all(
            "SELECT id FROM targets
             WHERE tenant_id = ? AND status = 'active' AND is_test = 0 ORDER BY id",
            [$tenantId]
        );
    } elseif ($type === 'group') {
        $groupId = $delivery['target_group_id'] !== null ? (int) $delivery['target_group_id'] : 0;
        if ($groupId < 1) {
            json_error('group 配信には target_group_id が必要です', 400);
        }
        edu_d_assert_group_owned($groupId, $tenantId);
        $rows = Db::all(
            'SELECT t.id
             FROM targets t
             INNER JOIN target_group tg ON tg.target_id = t.id
             WHERE t.tenant_id = ? AND tg.group_id = ? AND t.status = \'active\'
             ORDER BY t.id',
            [$tenantId, $groupId]
        );
    } elseif ($type === 'individual') {
        $rows = Db::all(
            "SELECT t.id FROM edu_delivery_targets dt
             INNER JOIN targets t ON t.id = dt.target_id
             WHERE dt.delivery_id = ? AND t.tenant_id = ? AND t.status = 'active'
             ORDER BY t.id",
            [(int) $delivery['id'], $tenantId]
        );
    } else { // risk: 訓練で失敗(auth or click)した実対象者
        $campaignId = $delivery['phish_campaign_id'] !== null ? (int) $delivery['phish_campaign_id'] : 0;
        // phish_campaign_id 指定時はそのキャンペーン、未指定時はテナント全体の失敗者
        if ($campaignId > 0) {
            assert_campaign_owned($campaignId, $tenantId);
            $rows = Db::all(
                "SELECT DISTINCT ct.target_id AS id
                 FROM events e
                 INNER JOIN campaigns c ON c.id = e.campaign_id AND c.tenant_id = e.tenant_id AND c.deleted_at IS NULL
                 INNER JOIN campaign_targets ct ON ct.tracking_id = e.tracking_id
                 INNER JOIN targets t ON t.id = ct.target_id
                 WHERE e.tenant_id = ? AND e.campaign_id = ? AND e.event_type IN ('auth','click')
                   AND t.tenant_id = ? AND t.status = 'active' AND t.is_test = 0
                 ORDER BY ct.target_id",
                [$tenantId, $campaignId, $tenantId]
            );
        } else {
            $rows = Db::all(
                "SELECT DISTINCT ct.target_id AS id
                 FROM events e
                 INNER JOIN campaigns c ON c.id = e.campaign_id AND c.tenant_id = e.tenant_id AND c.deleted_at IS NULL
                 INNER JOIN campaign_targets ct ON ct.tracking_id = e.tracking_id
                 INNER JOIN targets t ON t.id = ct.target_id
                 WHERE e.tenant_id = ? AND e.event_type IN ('auth','click')
                   AND t.tenant_id = ? AND t.status = 'active' AND t.is_test = 0
                 ORDER BY ct.target_id",
                [$tenantId, $tenantId]
            );
        }
    }
    $ids = [];
    foreach ($rows as $r) {
        $ids[] = (int) $r['id'];
    }
    return $ids;
}

/** 配信の設問を確定する。question_ids 明示優先、なければ条件抽出。テナント所有を厳格確認。 */
function edu_d_resolve_questions(array $delivery, int $tenantId): array
{
    // 明示指定(edu_delivery_questions に既に積まれている)を優先
    $explicit = Db::all(
        'SELECT question_id FROM edu_delivery_questions WHERE delivery_id = ? ORDER BY sort_order, id',
        [(int) $delivery['id']]
    );
    if ($explicit !== []) {
        $ids = [];
        foreach ($explicit as $r) {
            $ids[] = (int) $r['question_id'];
        }
        return $ids;
    }

    // 条件抽出。規則は EduQuestionPicker に一本化してある(EduAutoEnroll と共通)。
    require_once __DIR__ . '/../lib/EduQuestionPicker.php';
    return EduQuestionPicker::pick($delivery, $tenantId);
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

function edu_d_handle_create(array $actor): never
{
    tet2_require_csrf();
    $body = json_body();
    $tenantId = effective_tenant_id($actor, edu_d_body_optional_int($body, 'tenant_id'));
    $title = edu_d_string($body, 'title');
    $deliveryType = edu_d_type($body);
    $targetType = edu_d_target_type($body);

    // 任意項目
    $questionCount = edu_d_body_optional_int($body, 'question_count');
    $categoryIds = edu_d_int_array($body, 'category_ids');
    $randomize = array_key_exists('randomize', $body) ? ($body['randomize'] ? 1 : 0) : 1;
    $passScore = edu_d_body_optional_int($body, 'pass_score');
    $targetGroupId = edu_d_body_optional_int($body, 'target_group_id');
    $phishCampaignId = edu_d_body_optional_int($body, 'phish_campaign_id');
    $materialId = edu_d_body_optional_int($body, 'material_id');
    $targetIds = edu_d_int_array($body, 'target_ids') ?? [];
    if ($targetType !== 'individual') {
        $targetIds = [];
    }
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
    if ($categoryIds !== null && $categoryIds !== []) {
        foreach ($categoryIds as $cid) {
            if (Db::one('SELECT 1 FROM edu_categories WHERE id = ? AND (tenant_id = ? OR tenant_id IS NULL)', [$cid, $tenantId]) === null) {
                json_error('カテゴリが見つかりません', 404);
            }
        }
    }
    if ($phishCampaignId !== null) {
        assert_campaign_owned($phishCampaignId, $tenantId);
    }

    $id = Db::insert(
        'INSERT INTO edu_deliveries
         (tenant_id, title, status, delivery_type, question_count, category_ids, difficulty_range,
          randomize, pass_score, material_id, target_type, target_group_id, triggered_by, phish_campaign_id, created_by)
         VALUES (?, ?, \'draft\', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [
            $tenantId, $title, $deliveryType, $questionCount,
            $categoryIds !== null ? json_encode($categoryIds) : null,
            $diffRange !== null ? json_encode($diffRange) : null,
            $randomize, $passScore, $materialId, $targetType, $targetGroupId, $triggeredBy, $phishCampaignId,
            $actor['id'],
        ]
    );

    foreach ($targetIds as $targetId) {
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

    // 起動済み(draft以外)は編集不可
    if ((string) $delivery['status'] !== 'draft') {
        json_error('draft 以外の配信は編集できません', 409);
    }

    $title = array_key_exists('title', $body) ? edu_d_string($body, 'title') : null;
    $passScore = edu_d_body_optional_int($body, 'pass_score');
    $scheduledAt = null;
    if (array_key_exists('scheduled_at', $body) && is_string($body['scheduled_at']) && trim($body['scheduled_at']) !== '') {
        $scheduledAt = trim($body['scheduled_at']);
    }
    $deadline = null;
    if (array_key_exists('deadline', $body) && is_string($body['deadline']) && trim($body['deadline']) !== '') {
        $deadline = trim($body['deadline']);
    }
    // draft のうちに手動配信⇄自動連携を切り替えられるようにする。
    $triggeredBy = null;
    if (array_key_exists('triggered_by', $body) && $body['triggered_by'] !== null) {
        $triggeredBy = edu_d_triggered_by($body);
        edu_d_assert_trigger_consistency($triggeredBy, (string) $delivery['target_type']);
    }

    if ($title === null && $passScore === null && $scheduledAt === null && $deadline === null
        && $triggeredBy === null) {
        json_error('更新項目がありません', 400);
    }

    Db::run(
        'UPDATE edu_deliveries
         SET title = COALESCE(?, title),
             pass_score = COALESCE(?, pass_score),
             scheduled_at = COALESCE(?, scheduled_at),
             deadline = COALESCE(?, deadline),
             triggered_by = COALESCE(?, triggered_by)
         WHERE id = ? AND tenant_id = ?',
        [$title, $passScore, $scheduledAt, $deadline, $triggeredBy, $id, $tenantId]
    );
    audit('edu_delivery.update', 'delivery_id=' . $id);
    json_out(['success' => true, 'delivery' => edu_d_assert_owned($id, $tenantId)]);
}

function edu_d_handle_launch(array $actor): never
{
    tet2_require_csrf();
    $body = json_body();
    $tenantId = effective_tenant_id($actor, edu_d_body_optional_int($body, 'tenant_id'));
    $id = edu_d_id_body($body);
    $delivery = edu_d_assert_owned($id, $tenantId);

    if (!in_array((string) $delivery['status'], ['draft', 'scheduled'], true)) {
        json_error('この配信は開始できません(status=' . $delivery['status'] . ')', 409);
    }

    // 対象者と設問を確定
    $targetIds = edu_d_resolve_targets($delivery, $tenantId);
    if ($targetIds === []) {
        json_error('対象者がいません', 400);
    }
    $questionIds = edu_d_resolve_questions($delivery, $tenantId);
    if ($questionIds === []) {
        json_error('出題する設問がありません', 400);
    }

    // トランザクションで割当採番 + 設問確定。新規割当者の (token) を集め、commit後にメール送信する
    // (SMTP送信をtx内でやると送信遅延/失敗がcommitをブロック・巻き戻すため、必ずtx外で送る)。
    $newTokens = [];
    $result = Db::tx(function () use ($id, $tenantId, $targetIds, $questionIds, $delivery, &$newTokens) {
        // 設問が未確定(条件抽出)なら edu_delivery_questions に積む
        $existing = Db::one('SELECT 1 FROM edu_delivery_questions WHERE delivery_id = ?', [$id]);
        if ($existing === null) {
            $sort = 0;
            foreach ($questionIds as $qid) {
                Db::run(
                    'INSERT OR IGNORE INTO edu_delivery_questions (delivery_id, question_id, sort_order) VALUES (?, ?, ?)',
                    [$id, $qid, $sort]
                );
                $sort++;
            }
        }
        // 受講割当を採番(既存 target は UNIQUE(delivery_id,target_id) でスキップ=再launch安全)
        $assigned = 0;
        foreach ($targetIds as $targetId) {
            $dup = Db::one('SELECT 1 FROM edu_assignments WHERE delivery_id = ? AND target_id = ?', [$id, $targetId]);
            if ($dup !== null) {
                continue;
            }
            $token = edu_d_generate_token();
            Db::run(
                'INSERT INTO edu_assignments (tenant_id, delivery_id, target_id, access_token, status, token_expiry)
                 VALUES (?, ?, ?, ?, \'assigned\', ?)',
                [$tenantId, $id, $targetId, $token, edu_d_token_expiry($delivery)]
            );
            $newTokens[] = $token;
            $assigned++;
        }
        Db::run("UPDATE edu_deliveries SET status = 'running' WHERE id = ?", [$id]);
        return $assigned;
    });

    // commit 済み。新規割当者へ受講依頼メールを送る(tx外)。送信失敗しても launch 自体は成功扱い
    // (未達分は後で remind で再送できる)。
    $mailSent = 0;
    if ($newTokens !== []) {
        $mailSent = edu_d_send_invites($id, $tenantId, (string) $delivery['title'], $newTokens);
    }

    audit('edu_delivery.launch', 'delivery_id=' . $id . ',assigned=' . $result . ',mail=' . $mailSent);
    json_out([
        'success' => true,
        'status' => 'running',
        'assigned' => $result,
        'mail_sent' => $mailSent,
        'question_count' => count($questionIds),
    ]);
}

/**
 * 新規割当者へ受講依頼メールを送る。access_token 群から宛先を引き当てて送信。送信成功数を返す。
 * tx 外で呼ぶこと(SMTP はブロッキング)。
 */
function edu_d_send_invites(int $deliveryId, int $tenantId, string $title, array $tokens): int
{
    require_once __DIR__ . '/../lib/EduMailer.php';
    $sent = 0;
    foreach ($tokens as $token) {
        $row = Db::one(
            'SELECT t.email, t.name
             FROM edu_assignments a
             INNER JOIN targets t ON t.id = a.target_id
             WHERE a.access_token = ? AND a.tenant_id = ?',
            [$token, $tenantId]
        );
        if ($row === null) {
            continue;
        }
        $name = trim((string) ($row['name'] ?? ''));
        $greeting = $name !== '' ? ($name . ' 様') : 'ご担当者 様';
        $url = EduMailer::takeUrl($token);
        $subject = '【受講のご案内】' . $title;
        $body = $greeting . "\n\n"
            . 'セキュリティ教育「' . $title . "」が配信されました。\n"
            . "下記URLよりご受講ください（所要5〜10分・ログイン不要）。\n\n"
            . $url . "\n\n"
            . "※本メールは自動送信です。ご不明点は管理者へお問い合わせください。\n";
        if (EduMailer::send((string) $row['email'], $subject, $body)) {
            $sent++;
        }
    }
    return $sent;
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
    json_error('不正なアクションです', 400);
} catch (Throwable $e) {
    error_log($e->getMessage());
    json_error('サーバエラー', 500);
}
