<?php declare(strict_types=1); require __DIR__."/../lib/bootstrap.php";

/**
 * 設問バンク(edu_questions)管理 API。
 * templates.php と同じ流儀。options/correct_answer は JSON 配列で受け、妥当性を検証する。
 * category_id は同一テナント所有を確認(IDOR 防止)。
 */

const EDU_QUESTION_TYPES = ['single_choice', 'true_false', 'multiple_choice'];

function edu_q_query_int(string $key): ?int
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

function edu_q_body_optional_int(array $body, string $key): ?int
{
    if (!array_key_exists($key, $body) || $body[$key] === null) {
        return null;
    }
    if (!is_int($body[$key]) || $body[$key] < 1) {
        json_error($key . ' が不正です', 400);
    }
    return $body[$key];
}

function edu_q_string(array $body, string $key): string
{
    if (!array_key_exists($key, $body) || !is_string($body[$key]) || trim($body[$key]) === '') {
        json_error($key . ' は必須です', 400);
    }
    return trim($body[$key]);
}

function edu_q_optional_string(array $body, string $key): ?string
{
    if (!array_key_exists($key, $body) || $body[$key] === null) {
        return null;
    }
    if (!is_string($body[$key])) {
        json_error($key . ' が不正です', 400);
    }
    return trim($body[$key]);
}

function edu_q_id_body(array $body): int
{
    if (!array_key_exists('id', $body) || !is_int($body['id']) || $body['id'] < 1) {
        json_error('id が不正です', 400);
    }
    return $body['id'];
}

/** options: 非空文字列の配列(2件以上)。 */
function edu_q_options(array $body): array
{
    if (!array_key_exists('options', $body) || !is_array($body['options'])) {
        json_error('options は配列で指定してください', 400);
    }
    $opts = [];
    foreach ($body['options'] as $o) {
        if (!is_string($o) || trim($o) === '') {
            json_error('options に空の選択肢は含められません', 400);
        }
        $opts[] = trim($o);
    }
    if (count($opts) < 2) {
        json_error('options は2件以上必要です', 400);
    }
    return $opts;
}

/**
 * correct_answer: 0始まりindexの配列。options 範囲内・重複なし。
 * question_type との整合(single_choice/true_false は1件、multiple_choiceは1件以上)を確認。
 */
function edu_q_correct(array $body, string $type, int $optionCount): array
{
    if (!array_key_exists('correct_answer', $body) || !is_array($body['correct_answer'])) {
        json_error('correct_answer は配列で指定してください', 400);
    }
    $seen = [];
    foreach ($body['correct_answer'] as $idx) {
        if (!is_int($idx) || $idx < 0 || $idx >= $optionCount) {
            json_error('correct_answer が options の範囲外です', 400);
        }
        if (isset($seen[$idx])) {
            json_error('correct_answer に重複があります', 400);
        }
        $seen[$idx] = true;
    }
    $count = count($body['correct_answer']);
    if ($count < 1) {
        json_error('correct_answer は1件以上必要です', 400);
    }
    if ($type !== 'multiple_choice' && $count !== 1) {
        json_error('この問題タイプの正解は1件です', 400);
    }
    return array_values($body['correct_answer']);
}

function edu_q_type(array $body): string
{
    $type = edu_q_string($body, 'question_type');
    if (!in_array($type, EDU_QUESTION_TYPES, true)) {
        json_error('question_type が不正です', 400);
    }
    return $type;
}

function edu_q_difficulty(array $body): int
{
    if (!array_key_exists('difficulty', $body) || !is_int($body['difficulty'])) {
        json_error('difficulty は整数で指定してください', 400);
    }
    if ($body['difficulty'] < 1 || $body['difficulty'] > 3) {
        json_error('difficulty は 1〜3 です', 400);
    }
    return $body['difficulty'];
}

/** category が閲覧可能か(自テナント or 共有)。出題・一覧用。 */
function edu_q_assert_category_visible(int $categoryId, int $tenantId): array
{
    $c = Db::one('SELECT * FROM edu_categories WHERE id = ? AND (tenant_id = ? OR tenant_id IS NULL)', [$categoryId, $tenantId]);
    if ($c === null) {
        json_error('カテゴリが見つかりません', 404);
    }
    return $c;
}

/**
 * category へ設問を追加できるか。自テナントのカテゴリは可。共有カテゴリは superadmin のみ。
 * (テナントは共有教材を編集せず、fork してコピーを編集する方針)
 */
function edu_q_assert_category_writable(array $actor, int $categoryId, int $tenantId): array
{
    $c = edu_q_assert_category_visible($categoryId, $tenantId);
    if ($c['tenant_id'] === null && $actor['role'] !== 'superadmin') {
        json_error('共有教材には設問を追加できません(コピーして独自登録してください)', 403);
    }
    if ($c['tenant_id'] !== null && (int) $c['tenant_id'] !== $tenantId) {
        json_error('他組織のデータにはアクセスできません', 403);
    }
    return $c;
}

/** 閲覧可否(自テナント or 共有)。 */
function edu_q_assert_visible(int $id, int $tenantId): array
{
    $row = Db::one('SELECT * FROM edu_questions WHERE id = ? AND (tenant_id = ? OR tenant_id IS NULL)', [$id, $tenantId]);
    if ($row === null) {
        json_error('設問が見つかりません', 404);
    }
    return $row;
}

/** 編集可否: 自テナントは所有者、共有は superadmin のみ。 */
function edu_q_assert_editable(array $actor, int $id, int $tenantId): array
{
    $row = edu_q_assert_visible($id, $tenantId);
    if ($row['tenant_id'] === null) {
        if ($actor['role'] !== 'superadmin') {
            json_error('共有教材は編集できません(コピーして独自登録してください)', 403);
        }
        return $row;
    }
    if ((int) $row['tenant_id'] !== $tenantId) {
        json_error('他組織のデータにはアクセスできません', 403);
    }
    return $row;
}

function edu_q_handle_list(array $actor): never
{
    // superadmin が tenant_id 未指定なら共有スコープ(番兵0)。共有設問のみ一覧できる。
    $tenantId = edu_effective_tenant_id($actor, edu_q_query_int('tenant_id'));
    $categoryId = edu_q_query_int('category_id');
    if ($categoryId !== null) {
        edu_q_assert_category_visible($categoryId, $tenantId);
    }
    // 共有カテゴリの設問 + 自テナントの設問(閲覧・出題用)
    $rows = Db::all(
        'SELECT id, tenant_id, category_id, title, question_type, options, correct_answer, explanation, difficulty, is_active, is_shared, created_at
         FROM edu_questions
         WHERE (tenant_id = ? OR tenant_id IS NULL) AND (? IS NULL OR category_id = ?)
         ORDER BY category_id, id',
        [$tenantId, $categoryId, $categoryId]
    );
    json_out(['success' => true, 'questions' => $rows]);
}

function edu_q_handle_get(array $actor): never
{
    // superadmin が tenant_id 未指定なら共有スコープ(番兵0)。共有設問を単体取得できる。
    $tenantId = edu_effective_tenant_id($actor, edu_q_query_int('tenant_id'));
    $id = edu_q_query_int('id');
    if ($id === null) {
        json_error('id が不正です', 400);
    }
    json_out(['success' => true, 'question' => edu_q_assert_visible($id, $tenantId)]);
}

function edu_q_handle_create(array $actor): never
{
    tet2_require_csrf();
    $body = json_body();
    $tenantId = effective_tenant_id($actor, edu_q_body_optional_int($body, 'tenant_id'));
    $categoryId = edu_q_body_optional_int($body, 'category_id');
    if ($categoryId === null) {
        json_error('category_id は必須です', 400);
    }
    $cat = edu_q_assert_category_writable($actor, $categoryId, $tenantId);
    // 設問のスコープはカテゴリに揃える(共有カテゴリ配下=共有設問, テナントカテゴリ配下=自テナント)
    $qTenant = $cat['tenant_id'] !== null ? (int) $cat['tenant_id'] : null;
    $qShared = $cat['tenant_id'] === null ? 1 : 0;

    $title = edu_q_string($body, 'title');
    $type = edu_q_type($body);
    $options = edu_q_options($body);
    $correct = edu_q_correct($body, $type, count($options));
    $explanation = edu_q_optional_string($body, 'explanation');
    $difficulty = edu_q_difficulty($body);

    $id = Db::insert(
        'INSERT INTO edu_questions (tenant_id, category_id, title, question_type, options, correct_answer, explanation, difficulty, is_active, is_shared)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ' . $qShared . ')',
        [
            $qTenant,
            $categoryId,
            $title,
            $type,
            json_encode($options, JSON_UNESCAPED_UNICODE),
            json_encode($correct, JSON_UNESCAPED_UNICODE),
            $explanation,
            $difficulty,
        ]
    );
    audit('edu_question.create', 'question_id=' . $id);
    json_out(['success' => true, 'question' => edu_q_assert_visible($id, $tenantId)], 201);
}

function edu_q_handle_update(array $actor): never
{
    tet2_require_csrf();
    $body = json_body();
    // superadmin が tenant_id 未指定なら共有スコープ(番兵0)で共有設問を編集可能にする。
    $tenantId = edu_effective_tenant_id($actor, edu_q_body_optional_int($body, 'tenant_id'));
    $id = edu_q_id_body($body);
    $existing = edu_q_assert_editable($actor, $id, $tenantId); // 共有はsuperadminのみ

    // 部分更新。options/correct_answer/question_type は相互依存するため、
    // どれかが来たら3者を再検証する(最終状態で整合を担保)。
    $type = array_key_exists('question_type', $body) ? edu_q_type($body) : (string) $existing['question_type'];

    $optionsChanged = array_key_exists('options', $body);
    $correctChanged = array_key_exists('correct_answer', $body);
    $options = $optionsChanged
        ? edu_q_options($body)
        : (json_decode((string) $existing['options'], true) ?: []);

    if ($optionsChanged || $correctChanged || array_key_exists('question_type', $body)) {
        // correct は body 優先、無ければ既存を body に載せ替えて検証
        if (!$correctChanged) {
            $body['correct_answer'] = json_decode((string) $existing['correct_answer'], true) ?: [];
        }
        $correct = edu_q_correct($body, $type, count($options));
    } else {
        $correct = json_decode((string) $existing['correct_answer'], true) ?: [];
    }

    $title = edu_q_optional_string($body, 'title');
    if (array_key_exists('title', $body) && ($title === null || $title === '')) {
        json_error('title が不正です', 400);
    }
    $explanation = array_key_exists('explanation', $body) ? edu_q_optional_string($body, 'explanation') : null;
    $difficulty = array_key_exists('difficulty', $body) ? edu_q_difficulty($body) : null;
    $isActive = null;
    if (array_key_exists('is_active', $body) && $body['is_active'] !== null) {
        $isActive = $body['is_active'] ? 1 : 0;
    }

    $nothingChanged = $title === null && $explanation === null && $difficulty === null && $isActive === null
        && !$optionsChanged && !$correctChanged && !array_key_exists('question_type', $body);
    if ($nothingChanged) {
        json_error('更新項目がありません', 400);
    }

    Db::run(
        'UPDATE edu_questions
         SET title = COALESCE(?, title),
             question_type = ?,
             options = ?,
             correct_answer = ?,
             explanation = COALESCE(?, explanation),
             difficulty = COALESCE(?, difficulty),
             is_active = COALESCE(?, is_active)
         WHERE id = ?',
        [
            $title,
            $type,
            json_encode($options, JSON_UNESCAPED_UNICODE),
            json_encode($correct, JSON_UNESCAPED_UNICODE),
            $explanation,
            $difficulty,
            $isActive,
            $id,
        ]
    );
    audit('edu_question.update', 'question_id=' . $id);
    json_out(['success' => true, 'question' => edu_q_assert_visible($id, $tenantId)]);
}

function edu_q_handle_delete(array $actor): never
{
    tet2_require_csrf();
    $body = json_body();
    // superadmin が tenant_id 未指定なら共有スコープ(番兵0)で共有設問を削除可能にする。
    $tenantId = edu_effective_tenant_id($actor, edu_q_body_optional_int($body, 'tenant_id'));
    $id = edu_q_id_body($body);
    edu_q_assert_editable($actor, $id, $tenantId); // 共有はsuperadminのみ削除可

    // 配信に組み込み済みの設問は削除不可(整合性保護)
    $used = Db::one(
        'SELECT dq.id FROM edu_delivery_questions dq
         JOIN edu_deliveries d ON d.id = dq.delivery_id
         WHERE dq.question_id = ? AND d.tenant_id = ? LIMIT 1',
        [$id, $tenantId]
    );
    if ($used !== null) {
        json_error('配信に使用中の設問は削除できません', 409);
    }

    Db::run('DELETE FROM edu_questions WHERE id = ?', [$id]);
    audit('edu_question.delete', 'question_id=' . $id);
    json_out(['success' => true]);
}

try {
    $action = $_GET['action'] ?? '';
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $actor = require_role($method === 'GET' ? 'viewer' : 'operator');

    if ($action === 'list' && $method === 'GET') {
        edu_q_handle_list($actor);
    }
    if ($action === 'get' && $method === 'GET') {
        edu_q_handle_get($actor);
    }
    if ($action === 'create' && $method === 'POST') {
        edu_q_handle_create($actor);
    }
    if ($action === 'update' && $method === 'POST') {
        edu_q_handle_update($actor);
    }
    if ($action === 'delete' && $method === 'POST') {
        edu_q_handle_delete($actor);
    }
    json_error('不正なアクションです', 400);
} catch (Throwable $e) {
    error_log($e->getMessage());
    json_error('サーバエラー', 500);
}
