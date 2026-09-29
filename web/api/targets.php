<?php declare(strict_types=1); require __DIR__."/../lib/bootstrap.php";

// active=有効, suspended=一時停止(訓練対象外), archived=アーカイブ(退職/過去在籍。履歴は保持し統計に残す)
const TARGET_STATUSES = ['active', 'suspended', 'archived'];
// 正規値は bootstrap の単一定義を参照する(旧称「社員」は入力時にエイリアス変換される)。
const POSITION_CATEGORIES = TET2_POSITION_CATEGORIES;
const TARGET_CSV_HEADERS = [
    'メールアドレス' => 'email',
    '氏名' => 'name',
    '会社名' => 'company',
    '部署' => 'department',
    '役職' => 'title',
    '役職カテゴリ' => 'position_category',
    'email' => 'email',
    'name' => 'name',
    'company' => 'company',
    'department' => 'department',
    'title' => 'title',
    'position_category' => 'position_category',
    '従業員番号' => 'employee_no',
    'メモ' => 'memo',
    'employee_no' => 'employee_no',
    'memo' => 'memo',
];

// name/company/department/title 等の自由入力テキストの共通サニタイズ検証。
// 格納型XSS対策として、値に < > や制御文字(改行・タブ以外)を含むものを拒否し、長さ上限も課す。
// これらの値はメール本文・HTML(reveal/link ページ、CSV等)に差し込まれ得るため、
// 入力段階で危険文字を弾く(create/update/CSV取込の全INSERT経路で共用)。
const TARGET_TEXT_MAX_LEN = 255;
// 従業員番号とメモ(段B2 の G46)。メモも1行にする(CSV の取込は1行ずつ読むので、改行を入れると往復できない)。
const TARGET_EMPLOYEE_NO_MAX_LEN = 64;
const TARGET_MEMO_MAX_LEN = 1000;

/** 自由入力テキストの違反の理由。問題なければ null。 */
function targets_text_violation(string $value, string $label, int $max = TARGET_TEXT_MAX_LEN): ?string
{
    // 文字数(マルチバイト対応)で上限判定。
    if (mb_strlen($value) > $max) {
        return $label . ' が長すぎます（最大' . $max . '文字）';
    }
    // < > を含む値は拒否(HTMLタグ注入の遮断)。
    if (strpbrk($value, '<>') !== false) {
        return $label . ' に使用できない文字（< >）が含まれています';
    }
    // 制御文字(改行・タブ含む)を拒否。ログ/CSV/ヘッダ混入を防ぐ。
    if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
        return $label . ' に制御文字は使用できません';
    }
    return null;
}

function targets_assert_safe_text(?string $value, string $label, int $max = TARGET_TEXT_MAX_LEN): ?string
{
    if ($value === null) {
        return null;
    }
    $violation = targets_text_violation($value, $label, $max);
    if ($violation !== null) {
        json_error($violation, 400);
    }
    return $value;
}

/**
 * 従業員番号とメモ。キーがなければ [false, null](今のまま)、空文字は [true, null](消す)、値は [true, 値]。
 * @return array{0: bool, 1: ?string}
 */
function targets_optional_attr(array $body, string $key, string $label, int $max): array
{
    if (!array_key_exists($key, $body) || $body[$key] === null) {
        return [false, null];
    }
    if (!is_string($body[$key])) {
        json_error($key . ' が不正です', 400);
    }
    $value = trim($body[$key]);
    return [true, $value === '' ? null : targets_assert_safe_text($value, $label, $max)];
}

// email の簡易検証。FILTER_VALIDATE_EMAIL は quoted-local-part("..."@ex.com)等を
// 通してしまうため、空白・引用符・山括弧を含まない単純形式のみ許可する。
function targets_assert_safe_email(string $email): string
{
    if (!preg_match('/^[^@\s"\'<>]+@[^@\s"\'<>]+$/', $email)) {
        json_error('email が不正です', 400);
    }
    return $email;
}

function targets_string(array $body, string $key): string
{
    if (!array_key_exists($key, $body) || !is_string($body[$key]) || trim($body[$key]) === '') {
        json_error($key . ' は必須です', 400);
    }
    return trim($body[$key]);
}

function targets_optional_string(array $body, string $key): ?string
{
    if (!array_key_exists($key, $body)) {
        return null;
    }
    if (!is_string($body[$key]) || trim($body[$key]) === '') {
        json_error($key . ' が不正です', 400);
    }
    return trim($body[$key]);
}

/**
 * 真偽値(is_test 等)を 0/1 で受け取る。未指定は null(=現状維持)。
 * campaigns_optional_bool_int と同じ受け入れ方(bool / 0 / 1)に揃える。
 */
function targets_optional_bool_int(array $body, string $key): ?int
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

function targets_optional_nullable_string(array $body, string $key): ?string
{
    if (!array_key_exists($key, $body) || $body[$key] === null) {
        return null;
    }
    if (!is_string($body[$key])) {
        json_error($key . ' が不正です', 400);
    }
    $value = trim($body[$key]) === '' ? null : trim($body[$key]);
    // name/company/department/title 等の自由入力テキストを安全検証(< > /制御文字/長さ)。
    return targets_assert_safe_text($value, $key);
}

/** 従業員番号がテナントの中で空いているか(自分以外に使う人がいれば 409)。 */
function targets_assert_employee_no_free(int $tenantId, ?string $employeeNo, int $exceptId = 0): void
{
    if ($employeeNo !== null
        && Db::one('SELECT 1 FROM targets WHERE tenant_id = ? AND employee_no = ? AND id != ?', [$tenantId, $employeeNo, $exceptId]) !== null) {
        json_error('従業員番号は既に使用されています', 409);
    }
}

function targets_int(array $body, string $key): int
{
    if (!array_key_exists($key, $body) || !is_int($body[$key]) || $body[$key] < 1) {
        json_error($key . ' が不正です', 400);
    }
    return $body[$key];
}

function targets_query_int(string $key): ?int
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

function targets_body_optional_int(array $body, string $key): ?int
{
    if (!array_key_exists($key, $body) || $body[$key] === null) {
        return null;
    }
    if (!is_int($body[$key]) || $body[$key] < 1) {
        json_error($key . ' が不正です', 400);
    }
    return $body[$key];
}

function targets_int_array(array $body, string $key): ?array
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

function targets_is_unique_error(Throwable $e): bool
{
    return $e instanceof PDOException && str_contains($e->getMessage(), 'UNIQUE');
}

function targets_assert_group_owned(int $groupId, int $tenantId): array
{
    $group = Db::one("SELECT * FROM groups WHERE id = ? AND tenant_id = ? AND status='active'", [$groupId, $tenantId]);
    if ($group === null) {
        json_error('グループが見つかりません', 404);
    }
    return $group;
}

function targets_assert_groups_owned(array $groupIds, int $tenantId): void
{
    foreach ($groupIds as $groupId) {
        targets_assert_group_owned($groupId, $tenantId);
    }
}

function targets_group_names(array $targetIds, int $tenantId): array
{
    $groupsByTarget = [];
    foreach ($targetIds as $targetId) {
        $groupsByTarget[$targetId] = [];
    }
    foreach ($targetIds as $targetId) {
        $rows = Db::all(
            'SELECT g.name
             FROM groups g
             INNER JOIN target_group tg ON tg.group_id = g.id
             INNER JOIN targets t ON t.id = tg.target_id
             WHERE tg.target_id = ? AND t.tenant_id = ? AND g.tenant_id = ? AND g.status = \'active\'
             ORDER BY g.name',
            [$targetId, $tenantId, $tenantId]
        );
        $groupsByTarget[$targetId] = array_map(static fn (array $row): string => (string) $row['name'], $rows);
    }
    return $groupsByTarget;
}

function targets_attach_groups(array $targets, int $tenantId): array
{
    $ids = array_map(static fn (array $target): int => (int) $target['id'], $targets);
    $groupsByTarget = targets_group_names($ids, $tenantId);
    foreach ($targets as &$target) {
        $target['groups'] = $groupsByTarget[(int) $target['id']] ?? [];
    }
    unset($target);
    return $targets;
}

/**
 * 届かない宛先の記録(段B1、G04)を対象者の一覧に付ける。
 * undeliverable_count = 訓練メールが届かなかった回数。delivery_warning = 2回以上届かず、その後に届いた記録がない
 * (続けて届かない。アドレスの誤りや退職の見落としの疑い)。対象者は自動では消さない。
 * テストの訓練は宛先をテスト用に振り替えて送るので、対象者のアドレスの記録には数えない。
 */
function targets_attach_delivery(array $targets, int $tenantId): array
{
    $stats = [];
    foreach (Db::all(
        "SELECT ct.target_id,
                SUM(CASE WHEN ct.delivery_state = 'undeliverable' THEN 1 ELSE 0 END) AS undeliverable_count,
                MAX(CASE WHEN ct.delivery_state = 'undeliverable' THEN COALESCE(ct.sent_at, ct.delivery_state_at) END) AS last_undeliverable_at,
                MAX(CASE WHEN ct.delivery_state = 'delivered' THEN COALESCE(ct.sent_at, ct.delivery_state_at) END) AS last_delivered_at
         FROM campaign_targets ct
         INNER JOIN campaigns c ON c.id = ct.campaign_id AND c.tenant_id = ?
         INNER JOIN targets t ON t.id = ct.target_id AND t.tenant_id = c.tenant_id
         WHERE ct.delivery_state IS NOT NULL AND c.deleted_at IS NULL
           AND c.is_test = 0
         GROUP BY ct.target_id",
        [$tenantId]
    ) as $row) {
        $stats[(int) $row['target_id']] = $row;
    }
    foreach ($targets as &$target) {
        $row = $stats[(int) $target['id']] ?? null;
        $count = (int) ($row['undeliverable_count'] ?? 0);
        $target['undeliverable_count'] = $count;
        $target['last_undeliverable_at'] = $row['last_undeliverable_at'] ?? null;
        $target['delivery_warning'] = $count >= 2
            && ($row['last_delivered_at'] === null || $row['last_delivered_at'] < $row['last_undeliverable_at']);
    }
    unset($target);
    return $targets;
}

function targets_handle_list(array $actor): never
{
    $tenantId = effective_tenant_id($actor, targets_query_int('tenant_id'));
    $groupId = targets_query_int('group_id');
    // 検索はメール、氏名、従業員番号、メモの部分一致
    $q = isset($_GET['q']) && is_string($_GET['q']) && trim($_GET['q']) !== '' ? '%' . trim($_GET['q']) . '%' : null;
    // 既定はアーカイブ(退職/過去在籍)を除外。?include_archived=1 で全件(アーカイブ管理用)。
    $includeArchived = isset($_GET['include_archived']) && $_GET['include_archived'] === '1';
    $archiveClause = $includeArchived ? '' : " AND t.status != 'archived'";
    if ($groupId !== null) {
        targets_assert_group_owned($groupId, $tenantId);
        $targets = Db::all(
            'SELECT t.id, t.tenant_no, t.tenant_id, t.email, t.name, t.company, t.department, t.title, t.position_category, t.status, t.created_at, t.archived_at, t.is_test, t.employee_no, t.memo
             FROM targets t
             INNER JOIN target_group tg ON tg.target_id = t.id
             INNER JOIN groups g ON g.id = tg.group_id
             WHERE t.tenant_id = ? AND g.tenant_id = ? AND tg.group_id = ?
               AND (? IS NULL OR t.email LIKE ? OR t.name LIKE ? OR t.employee_no LIKE ? OR t.memo LIKE ?)' . $archiveClause . '
             ORDER BY t.tenant_no',
            [$tenantId, $tenantId, $groupId, $q, $q, $q, $q, $q]
        );
        json_out(['success' => true, 'targets' => targets_attach_delivery(targets_attach_groups($targets, $tenantId), $tenantId)]);
    }

    // 非グループ経路は別名 t を使わないので status 条件を素の列名で組む。
    $archiveClause2 = $includeArchived ? '' : " AND status != 'archived'";
    $targets = Db::all(
        'SELECT id, tenant_no, tenant_id, email, name, company, department, title, position_category, status, created_at, archived_at, is_test, employee_no, memo
         FROM targets
         WHERE tenant_id = ? AND (? IS NULL OR email LIKE ? OR name LIKE ? OR employee_no LIKE ? OR memo LIKE ?)' . $archiveClause2 . '
         ORDER BY tenant_no',
        [$tenantId, $q, $q, $q, $q, $q]
    );
    json_out(['success' => true, 'targets' => targets_attach_delivery(targets_attach_groups($targets, $tenantId), $tenantId)]);
}

function targets_handle_get(array $actor): never
{
    $tenantId = effective_tenant_id($actor, targets_query_int('tenant_id'));
    $id = targets_query_int('id');
    if ($id === null) {
        json_error('id が不正です', 400);
    }
    $target = assert_target_owned($id, $tenantId);
    $target['groups'] = targets_group_names([$id], $tenantId)[$id] ?? [];
    json_out(['success' => true, 'target' => $target]);
}

function targets_insert_groups(int $targetId, array $groupIds, int $tenantId): void
{
    foreach ($groupIds as $groupId) {
        Db::run(
            'INSERT OR IGNORE INTO target_group (target_id, group_id)
             SELECT ?, ?
             WHERE EXISTS (SELECT 1 FROM targets WHERE id = ? AND tenant_id = ?)
               AND EXISTS (SELECT 1 FROM groups WHERE id = ? AND tenant_id = ?)',
            [$targetId, $groupId, $targetId, $tenantId, $groupId, $tenantId]
        );
    }
}

function targets_handle_create(array $actor): never
{
    tet2_require_csrf();
    $body = json_body();
    $tenantId = effective_tenant_id($actor, targets_body_optional_int($body, 'tenant_id'));
    $email = targets_string($body, 'email');
    if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        json_error('email が不正です', 400);
    }
    // quoted-local-part 等を弾く簡易検証を追加。
    targets_assert_safe_email($email);
    $groupIds = targets_int_array($body, 'group_ids') ?? [];
    targets_assert_groups_owned($groupIds, $tenantId);

    $positionCategory = targets_optional_nullable_string($body, 'position_category');
    if ($positionCategory !== null) {
        // 旧称「社員」はエイリアスで受け入れ、正規値へ寄せる。
        $normalized = tet2_normalize_position_category($positionCategory);
        if ($normalized === null) {
            json_error('役職カテゴリが不正です（役員/管理職/一般従業員）', 400);
        }
        $positionCategory = $normalized;
    }
    // 検証用ユーザ(レポート集計から除外)。未指定は 0=本番ユーザ。
    $isTest = targets_optional_bool_int($body, 'is_test') ?? 0;
    [, $employeeNo] = targets_optional_attr($body, 'employee_no', '従業員番号', TARGET_EMPLOYEE_NO_MAX_LEN);
    [, $memo] = targets_optional_attr($body, 'memo', 'メモ', TARGET_MEMO_MAX_LEN);
    targets_assert_employee_no_free($tenantId, $employeeNo);
    $id = Db::tx(function () use ($tenantId, $email, $body, $groupIds, $positionCategory, $isTest, $employeeNo, $memo): int {
        $targetId = Db::insert(
            // tenant_no はテナント単位の連番(表示用)。グローバルな id とは別に採番する。
            'INSERT INTO targets (tenant_id, email, name, company, department, title, position_category, is_test, employee_no, memo, tenant_no)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, (SELECT COALESCE(MAX(tenant_no), 0) + 1 FROM targets WHERE tenant_id = ?))',
            [
                $tenantId,
                $email,
                targets_optional_nullable_string($body, 'name'),
                targets_optional_nullable_string($body, 'company'),
                targets_optional_nullable_string($body, 'department'),
                targets_optional_nullable_string($body, 'title'),
                $positionCategory,
                $isTest,
                $employeeNo,
                $memo,
                $tenantId,
            ]
        );
        targets_insert_groups($targetId, $groupIds, $tenantId);
        return $targetId;
    });
    audit('target.create', 'target_id=' . $id);
    $target = assert_target_owned($id, $tenantId);
    $target['groups'] = targets_group_names([$id], $tenantId)[$id] ?? [];
    // テナントの対象者数の上限を超えたら警告する(登録は拒否しない)
    json_out(['success' => true, 'target' => $target, 'limit_warning' => TenantStatus::targetLimitWarning($tenantId)], 201);
}

function targets_handle_update(array $actor): never
{
    tet2_require_csrf();
    $body = json_body();
    $tenantId = effective_tenant_id($actor, targets_body_optional_int($body, 'tenant_id'));
    $id = targets_int($body, 'id');
    assert_target_owned($id, $tenantId);
    // name/company/department/title は任意テキスト。フォームは空欄項目も空文字で送るため、
    // 空文字はエラーにせず NULL 扱い(下の COALESCE で現状維持)にする。
    // ※ targets_optional_string(空文字=400) を使うと「役職を空にしたまま会社だけ変更」で
    //   'title が不正です' になる(2026-07-19 バグ報告)。
    $name = targets_optional_nullable_string($body, 'name');
    $company = targets_optional_nullable_string($body, 'company');
    $department = targets_optional_nullable_string($body, 'department');
    $title = targets_optional_nullable_string($body, 'title');
    // position_category も「—」(未設定)で保存できるよう空文字は NULL 扱い。
    $positionCategory = targets_optional_nullable_string($body, 'position_category');
    $status = targets_optional_string($body, 'status');
    // 検証用ユーザ切替。未指定(null)は現状維持。
    $isTest = targets_optional_bool_int($body, 'is_test');
    $groupIds = targets_int_array($body, 'group_ids');
    // 従業員番号とメモは、送られたら空文字でも反映する(空で消せる)。送られなければ今のまま。
    [$hasEmployeeNo, $employeeNo] = targets_optional_attr($body, 'employee_no', '従業員番号', TARGET_EMPLOYEE_NO_MAX_LEN);
    [$hasMemo, $memo] = targets_optional_attr($body, 'memo', 'メモ', TARGET_MEMO_MAX_LEN);

    if ($name === null && $company === null && $department === null && $title === null && $positionCategory === null && $status === null && $isTest === null && $groupIds === null
        && !$hasEmployeeNo && !$hasMemo) {
        json_error('更新項目がありません', 400);
    }
    if ($status !== null && !in_array($status, TARGET_STATUSES, true)) {
        json_error('status が不正です', 400);
    }
    // 空文字は「—(未設定)にする」意図なのでそのまま通す。値があれば正規化(旧称「社員」を救う)。
    if ($positionCategory !== null && $positionCategory !== '') {
        $normalized = tet2_normalize_position_category($positionCategory);
        if ($normalized === null) {
            json_error('役職カテゴリが不正です（役員/管理職/一般従業員）', 400);
        }
        $positionCategory = $normalized;
    }

    if ($groupIds !== null) {
        targets_assert_groups_owned($groupIds, $tenantId);
    }
    targets_assert_employee_no_free($tenantId, $employeeNo, $id);

    Db::tx(function () use ($id, $tenantId, $name, $company, $department, $title, $positionCategory, $status, $isTest, $groupIds,
        $hasEmployeeNo, $employeeNo, $hasMemo, $memo): void {
        Db::run(
            'UPDATE targets
             SET name = COALESCE(?, name),
                 company = COALESCE(?, company),
                 department = COALESCE(?, department),
                 title = COALESCE(?, title),
                 position_category = COALESCE(?, position_category),
                 status = COALESCE(?, status),
                 is_test = COALESCE(?, is_test),
                 employee_no = CASE WHEN ? = \'set\' THEN ? ELSE employee_no END,
                 memo = CASE WHEN ? = \'set\' THEN ? ELSE memo END,
                 -- status を archived にしたら削除日時を記録し、archived から戻したら消す。
                 -- status 未指定(NULL)のときは現状維持。
                 archived_at = CASE
                     WHEN ? IS NULL THEN archived_at
                     WHEN ? = \'archived\' THEN COALESCE(archived_at, datetime(\'now\',\'localtime\'))
                     ELSE NULL
                 END
             WHERE id = ? AND tenant_id = ?',
            [$name, $company, $department, $title, $positionCategory, $status, $isTest,
             $hasEmployeeNo ? 'set' : 'keep', $employeeNo, $hasMemo ? 'set' : 'keep', $memo, $status, $status, $id, $tenantId]
        );
        if ($groupIds !== null) {
            Db::run(
                'DELETE FROM target_group
                 WHERE target_id IN (SELECT id FROM targets WHERE id = ? AND tenant_id = ?)',
                [$id, $tenantId]
            );
            targets_insert_groups($id, $groupIds, $tenantId);
        }
    });
    audit('target.update', 'target_id=' . $id);
    $target = assert_target_owned($id, $tenantId);
    $target['groups'] = targets_group_names([$id], $tenantId)[$id] ?? [];
    json_out(['success' => true, 'target' => $target]);
}

function targets_handle_delete(array $actor): never
{
    tet2_require_csrf();
    $body = json_body();
    $tenantId = effective_tenant_id($actor, targets_body_optional_int($body, 'tenant_id'));
    $id = targets_int($body, 'id');
    assert_target_owned($id, $tenantId);

    // 履歴(訓練、教育、アンケート、グループ)が1件もない対象者は、実名とメールアドレスを残さないよう本当に消す。
    // 派生のデータ(支援優先度のスコア)は履歴として数えず、一緒に消す。
    $deleted = Db::txImmediate(static function () use ($id, $tenantId): bool {
        if (targets_history_refs($id) !== []) {
            return false;
        }
        foreach (TARGET_DERIVED_TABLES as $table) {
            Db::run("DELETE FROM {$table} WHERE target_id = ?", [$id]);
        }
        // その人の受講者のマイページのアカウント(users.target_id で対象者につながる。トークンは外部キーで一緒に消える)
        Db::run("DELETE FROM users WHERE target_id = ? AND role = 'learner' AND tenant_id = ?", [$id, $tenantId]);
        Db::run('DELETE FROM targets WHERE id = ? AND tenant_id = ?', [$id, $tenantId]);
        return true;
    });
    if ($deleted) {
        audit('target.delete', 'target_id=' . $id);
        json_out(['success' => true, 'archived' => false, 'deleted' => true]);
    }

    // 履歴がある対象者は論理削除(アーカイブ)。物理削除しない理由:
    //  1. 訓練履歴(campaign_targets/events/edu_*)を保持し、退職者も「よく開封する人」等の
    //     個人別統計レポートに残す(=履歴管理の要件)。
    //  2. これらは target_id を外部キー(CASCADEなし)で参照するため、物理 DELETE は FK 違反→
    //     サーバエラーになる。アーカイブなら FK 問題も原理的に発生しない。
    // アーカイブされた対象者は一覧から除外表示され、新規キャンペーンの対象にも出ない。
    // archived_at に削除日時を残す(在籍期間の判定・誤削除の追跡用)。
    Db::run(
        "UPDATE targets SET status = 'archived', archived_at = datetime('now','localtime')
         WHERE id = ? AND tenant_id = ?",
        [$id, $tenantId]
    );
    audit('target.archive', 'target_id=' . $id);
    json_out(['success' => true, 'archived' => true, 'deleted' => false]);
}

/** 対象者を参照していても履歴ではない(消してよい)派生のデータ。 */
const TARGET_DERIVED_TABLES = ['human_risk_scores'];

/**
 * 対象者を参照している履歴のテーブルと件数。外部キーの定義から毎回調べるので、テーブルが増えても漏れない。
 * @return array<string,int> テーブル名 => 件数(0件のテーブルは含めない)
 */
function targets_history_refs(int $targetId): array
{
    $refs = [];
    foreach (Db::all("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'") as $row) {
        $table = (string) $row['name'];
        if (in_array($table, TARGET_DERIVED_TABLES, true)) {
            continue;
        }
        foreach (Db::all('SELECT "table" AS parent, "from" AS col FROM pragma_foreign_key_list(?)', [$table]) as $fk) {
            if ($fk['parent'] !== 'targets') {
                continue;
            }
            $col = (string) $fk['col'];
            $n = (int) Db::one("SELECT COUNT(*) AS c FROM \"{$table}\" WHERE \"{$col}\" = ?", [$targetId])['c'];
            if ($n > 0) {
                $refs[$table] = ($refs[$table] ?? 0) + $n;
            }
        }
    }
    return $refs;
}

/**
 * アーカイブ(論理削除)した対象者を active に戻す。誤削除の復旧用。
 * 復活後は削除日を持たないので archived_at を NULL に戻す。
 */
function targets_handle_restore(array $actor): never
{
    tet2_require_csrf();
    $body = json_body();
    $tenantId = effective_tenant_id($actor, targets_body_optional_int($body, 'tenant_id'));
    $id = targets_int($body, 'id');
    $target = assert_target_owned($id, $tenantId);
    if (($target['status'] ?? '') !== 'archived') {
        json_error('削除済みの対象者ではありません', 400);
    }

    Db::run(
        "UPDATE targets SET status = 'active', archived_at = NULL WHERE id = ? AND tenant_id = ?",
        [$id, $tenantId]
    );
    audit('target.restore', 'target_id=' . $id);
    json_out(['success' => true, 'restored' => true, 'limit_warning' => TenantStatus::targetLimitWarning($tenantId)]);
}

function targets_csv_header_map(array $headers): array
{
    $map = [];
    foreach ($headers as $index => $header) {
        $key = trim((string) $header);
        if ($index === 0) {
            $key = preg_replace('/^\xEF\xBB\xBF/', '', $key) ?? $key;
        }
        if (array_key_exists($key, TARGET_CSV_HEADERS)) {
            $map[TARGET_CSV_HEADERS[$key]] = $index;
        }
    }
    if (!array_key_exists('email', $map)) {
        json_error('メールアドレス列は必須です', 400);
    }
    return $map;
}

function targets_csv_value(array $row, array $map, string $key): ?string
{
    if (!array_key_exists($key, $map)) {
        return null;
    }
    $value = trim((string) ($row[$map[$key]] ?? ''));
    return $value === '' ? null : $value;
}

/**
 * 役職マスタを [役職名 => カテゴリ] の連想配列で返す。CSV 取込の補完用。
 * position_masters が無い環境(古いDB)でも取込を止めないよう、失敗時は空配列を返す。
 *
 * @return array<string, string>
 */
function targets_position_master_map(int $tenantId): array
{
    try {
        $rows = Db::all('SELECT title, category FROM position_masters WHERE tenant_id = ?', [$tenantId]);
    } catch (Throwable) {
        return [];
    }
    $map = [];
    foreach ($rows as $r) {
        $map[(string) $r['title']] = (string) $r['category'];
    }
    return $map;
}

/**
 * 従業員番号とメモの CSV の値。空は null(取込では今の値を残す)。
 * 出力で式の無害化(tet2_csv_sanitize)が付けた先頭の ' は外し、出力した CSV をそのまま取り込めるようにする。
 */
function targets_csv_attr(array $row, array $map, string $key, string $label, int $max): ?string
{
    $value = targets_csv_value($row, $map, $key);
    if ($value === null) {
        return null;
    }
    if (preg_match("/^'[=+\-@]/", $value) === 1) {
        $value = substr($value, 1);
    }
    $violation = targets_text_violation($value, $label, $max);
    if ($violation !== null) {
        throw new InvalidArgumentException($violation);
    }
    return $value;
}

/**
 * 取込の行に当たる既存の対象者。従業員番号が入っていればまず番号で探し、なければメールアドレスで探す。
 * 番号で当たった人はメールアドレスを CSV の値に変える(アドレスが変わった人を別人として足さない)。
 * @return ?array 既存の対象者の行(id、email、employee_no)か null
 */
function targets_import_match(int $tenantId, string $email, ?string $employeeNo): ?array
{
    if ($employeeNo !== null) {
        $byNo = Db::one('SELECT id, email, employee_no FROM targets WHERE tenant_id = ? AND employee_no = ?', [$tenantId, $employeeNo]);
        if ($byNo !== null) {
            if ((string) $byNo['email'] !== $email
                && Db::one('SELECT 1 FROM targets WHERE tenant_id = ? AND email = ? AND id != ?', [$tenantId, $email, (int) $byNo['id']]) !== null) {
                throw new InvalidArgumentException('メールアドレスは別の対象者が使っています（従業員番号 ' . $employeeNo . ' の人のアドレスに変えられません）');
            }
            return $byNo;
        }
    }
    $byEmail = Db::one('SELECT id, email, employee_no FROM targets WHERE tenant_id = ? AND email = ?', [$tenantId, $email]);
    if ($byEmail !== null && $employeeNo !== null && $byEmail['employee_no'] !== null && (string) $byEmail['employee_no'] !== $employeeNo) {
        throw new InvalidArgumentException('このメールアドレスの対象者には別の従業員番号（' . $byEmail['employee_no'] . '）が登録されています');
    }
    return $byEmail;
}

/**
 * @param array<string, string> $positionMap 役職名→カテゴリ。CSV にカテゴリ列が無いときの補完に使う
 * @param array<string, true> $seenEmployeeNos この取込で既に読んだ従業員番号(同じ番号の2行目は取り込まない)
 */
function targets_import_row(int $tenantId, array $row, array $map, ?int $groupId, array $positionMap = [], array &$seenEmployeeNos = []): string
{
    $email = targets_csv_value($row, $map, 'email');
    if ($email === null || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        throw new InvalidArgumentException('email が不正です');
    }
    // quoted-local-part 等を弾く簡易検証(create経路と同一ルール)。
    if (!preg_match('/^[^@\s"\'<>]+@[^@\s"\'<>]+$/', $email)) {
        throw new InvalidArgumentException('email が不正です');
    }
    $employeeNo = targets_csv_attr($row, $map, 'employee_no', '従業員番号', TARGET_EMPLOYEE_NO_MAX_LEN);
    $memo = targets_csv_attr($row, $map, 'memo', 'メモ', TARGET_MEMO_MAX_LEN);
    if ($employeeNo !== null && isset($seenEmployeeNos[$employeeNo])) {
        throw new InvalidArgumentException('従業員番号 ' . $employeeNo . ' が CSV の中で重複しています');
    }
    $existing = targets_import_match($tenantId, $email, $employeeNo);
    if ($employeeNo !== null) {
        $seenEmployeeNos[$employeeNo] = true;
    }
    // 役職カテゴリは正規値のみ採用。旧称「社員」はエイリアスで救い、
    // それ以外・空は NULL(取込を止めない)。
    $posCat = tet2_normalize_position_category(targets_csv_value($row, $map, 'position_category'));
    // カテゴリ列が無い/空なら役職名から役職マスタを引いて補完する。
    if ($posCat === null) {
        $csvTitle = targets_csv_value($row, $map, 'title');
        if ($csvTitle !== null && isset($positionMap[$csvTitle])) {
            $posCat = $positionMap[$csvTitle];
        }
    }
    // 自由入力テキストは create/update と同一ルールで安全検証(< > /制御文字/長さ)。
    // 取込は行単位で例外送出するため、json_error 版ではなく個別チェックする。
    $textFields = ['name' => '氏名', 'company' => '会社名', 'department' => '部署', 'title' => '役職'];
    $sanitized = [];
    foreach ($textFields as $field => $label) {
        $val = targets_csv_value($row, $map, $field);
        if ($val !== null) {
            if (mb_strlen($val) > TARGET_TEXT_MAX_LEN) {
                throw new InvalidArgumentException($label . ' が長すぎます（最大' . TARGET_TEXT_MAX_LEN . '文字）');
            }
            if (strpbrk($val, '<>') !== false) {
                throw new InvalidArgumentException($label . ' に使用できない文字（< >）が含まれています');
            }
            if (preg_match('/[\x00-\x1F\x7F]/', $val) === 1) {
                throw new InvalidArgumentException($label . ' に制御文字は使用できません');
            }
        }
        $sanitized[$field] = $val;
    }
    $values = [
        $sanitized['name'],
        $sanitized['company'],
        $sanitized['department'],
        $sanitized['title'],
        $posCat,
    ];

    if ($existing === null) {
        $targetId = Db::insert(
            // tenant_no はテナント単位の連番(表示用)。グローバルな id とは別に採番する。
            'INSERT INTO targets (tenant_id, email, name, company, department, title, position_category, employee_no, memo, tenant_no)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, (SELECT COALESCE(MAX(tenant_no), 0) + 1 FROM targets WHERE tenant_id = ?))',
            [$tenantId, $email, $values[0], $values[1], $values[2], $values[3], $values[4], $employeeNo, $memo, $tenantId]
        );
        $result = 'imported';
    } else {
        $targetId = (int) $existing['id'];
        // 従業員番号とメモは、CSV の列がないか空なら今の値を残す(列を足す前の CSV でも消えない)
        Db::run(
            'UPDATE targets SET email = ?, name = ?, company = ?, department = ?, title = ?, position_category = COALESCE(?, position_category),
                    employee_no = COALESCE(?, employee_no), memo = COALESCE(?, memo)
             WHERE id = ? AND tenant_id = ?',
            [$email, $values[0], $values[1], $values[2], $values[3], $values[4], $employeeNo, $memo, $targetId, $tenantId]
        );
        $result = (string) $existing['email'] === $email ? 'updated' : 'email_changed';
    }
    if ($groupId !== null) {
        Db::run(
            'INSERT OR IGNORE INTO target_group (target_id, group_id)
             SELECT ?, ?
             WHERE EXISTS (SELECT 1 FROM targets WHERE id = ? AND tenant_id = ?)
               AND EXISTS (SELECT 1 FROM groups WHERE id = ? AND tenant_id = ?)',
            [$targetId, $groupId, $targetId, $tenantId, $groupId, $tenantId]
        );
    }
    return $result;
}

function targets_handle_import_csv(array $actor): never
{
    tet2_require_csrf();
    $body = json_body();
    $tenantId = effective_tenant_id($actor, targets_body_optional_int($body, 'tenant_id'));
    $csv = targets_string($body, 'csv');
    $groupId = targets_body_optional_int($body, 'group_id');
    if ($groupId !== null) {
        targets_assert_group_owned($groupId, $tenantId);
    }

    $lines = preg_split('/\r\n|\n|\r/', $csv);
    if ($lines === false || count($lines) === 0) {
        json_error('CSV が不正です', 400);
    }
    $headers = str_getcsv((string) array_shift($lines));
    $map = targets_csv_header_map($headers);
    // 既存の対象者との照合は、従業員番号(列があり値が入った行)が先、なければメールアドレス(targets_import_match)
    $result = ['imported' => 0, 'updated' => 0, 'email_changed' => 0, 'skipped' => 0, 'errors' => []];
    // 役職マスタを1回だけ読み込む(行ごとに引くとクエリが行数分発行される)。
    // CSV に役職カテゴリ列が無い/空のとき、役職名から補完するために使う。
    $positionMap = targets_position_master_map($tenantId);

    Db::tx(function () use ($lines, $tenantId, $map, $groupId, $positionMap, &$result): void {
        $seenEmployeeNos = [];
        foreach ($lines as $index => $line) {
            if (trim((string) $line) === '') {
                continue;
            }
            $lineNumber = $index + 2;
            try {
                $status = targets_import_row($tenantId, str_getcsv((string) $line), $map, $groupId, $positionMap, $seenEmployeeNos);
                $result[$status]++;
            } catch (InvalidArgumentException $e) {
                $result['skipped']++;
                $result['errors'][] = ['line' => $lineNumber, 'reason' => $e->getMessage()];
            }
        }
    });
    // 従業員番号で当たりメールアドレスを変えた人も「更新」に数え、内訳を別に返す
    $updated = $result['updated'] + $result['email_changed'];
    audit('target.import_csv', 'imported=' . $result['imported'] . ',updated=' . $updated . ',email_changed=' . $result['email_changed']);
    json_out([
        'success' => true,
        'imported' => $result['imported'],
        'updated' => $updated,
        'email_changed' => $result['email_changed'],
        'skipped' => $result['skipped'],
        'errors' => $result['errors'],
        'limit_warning' => TenantStatus::targetLimitWarning($tenantId),
    ]);
}

/**
 * 指定テナントの対象者を CSV 文字列に組む(import_csv と対称のヘッダー/カラム順)。
 * 出力副作用を持たないのでユニットテスト可能。BOM は付けない(出力ハンドラ側で付与)。
 */
/**
 * 対象者 CSV を組み立てる。
 *
 * 既定はアーカイブ(退職/過去在籍)を除外する。list と同じ既定にすることで
 * 「画面に出ていない人が CSV には出る」ズレを防ぐ。
 * $includeArchived=true のときだけアーカイブも含め、削除日(archived_at)列を追加する。
 */
function targets_build_csv(int $tenantId, bool $includeArchived = false): string
{
    $archiveClause = $includeArchived ? '' : " AND status != 'archived'";
    $rows = Db::all(
        'SELECT email, name, company, department, title, position_category, employee_no, memo, status, archived_at, is_test
         FROM targets WHERE tenant_id = ?' . $archiveClause . ' ORDER BY id',
        [$tenantId]
    );
    $out = fopen('php://temp', 'r+');
    // import が受け付ける日本語ヘッダーと同じ並びで出力(往復可能にする)。
    // アーカイブ込みのときだけ状態/削除日/テスト区分を足す(import 側は未知の列を無視する)。
    // 従業員番号とメモは自由な文なので、先頭が = + - @ の値は式として開かれないよう ' を付ける(取込で外す)
    $header = ['メールアドレス', '氏名', '会社名', '部署', '役職', '役職カテゴリ', '従業員番号', 'メモ'];
    if ($includeArchived) {
        $header[] = '状態';
        $header[] = '削除日';
        $header[] = 'テストユーザ';
    }
    fputcsv($out, $header);
    foreach ($rows as $r) {
        $line = [
            (string) $r['email'],
            (string) ($r['name'] ?? ''),
            (string) ($r['company'] ?? ''),
            (string) ($r['department'] ?? ''),
            (string) ($r['title'] ?? ''),
            (string) ($r['position_category'] ?? ''),
            tet2_csv_sanitize($r['employee_no'] ?? ''),
            tet2_csv_sanitize($r['memo'] ?? ''),
        ];
        if ($includeArchived) {
            $line[] = (string) ($r['status'] ?? '');
            $line[] = (string) ($r['archived_at'] ?? '');
            $line[] = ((int) ($r['is_test'] ?? 0) === 1) ? 'テスト' : '';
        }
        fputcsv($out, $line);
    }
    rewind($out);
    $csv = stream_get_contents($out);
    fclose($out);
    return $csv;
}

/**
 * 現対象者を CSV で出力する。Excel で文字化けしないよう UTF-8 BOM を付ける。
 * JSON ではなく CSV を直接返す。
 */
function targets_handle_export_csv(array $actor): never
{
    $tenantId = effective_tenant_id($actor, isset($_GET['tenant_id']) ? (int) $_GET['tenant_id'] : null);
    // list と同じ既定(アーカイブ除外)。?include_archived=1 で削除済みも含めて出力する。
    $includeArchived = isset($_GET['include_archived']) && $_GET['include_archived'] === '1';
    $csv = targets_build_csv($tenantId, $includeArchived);
    $count = max(0, substr_count($csv, "\n") - 1); // ヘッダー1行を除く概数
    // 削除済みを含む持ち出しかを監査で区別できるようにする。
    audit('target.export_csv', 'count=' . $count . ($includeArchived ? ' include_archived=1' : ''));

    $filename = 'targets_' . ($includeArchived ? 'all_' : '') . date('Ymd_His') . '.csv';
    http_response_code(200);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('X-Content-Type-Options: nosniff');
    echo "\xEF\xBB\xBF"; // UTF-8 BOM(Excel 文字化け対策)
    echo $csv;
    exit;
}

try {
    $action = $_GET['action'] ?? '';
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $actor = require_role($method === 'GET' ? 'viewer' : 'operator');

    if ($action === 'list' && $method === 'GET') {
        targets_handle_list($actor);
    }
    if ($action === 'get' && $method === 'GET') {
        targets_handle_get($actor);
    }
    if ($action === 'export_csv' && $method === 'GET') {
        targets_handle_export_csv($actor);
    }
    if ($action === 'create' && $method === 'POST') {
        targets_handle_create($actor);
    }
    if ($action === 'update' && $method === 'POST') {
        targets_handle_update($actor);
    }
    if ($action === 'delete' && $method === 'POST') {
        targets_handle_delete($actor);
    }
    if ($action === 'restore' && $method === 'POST') {
        targets_handle_restore($actor);
    }
    if ($action === 'import_csv' && $method === 'POST') {
        targets_handle_import_csv($actor);
    }
    json_error('不正なアクションです', 400);
} catch (Throwable $e) {
    if (targets_is_unique_error($e)) {
        json_error(str_contains($e->getMessage(), 'employee_no') ? '従業員番号は既に使用されています' : 'email は既に使用されています', 409);
    }
    error_log($e->getMessage());
    json_error('サーバエラー', 500);
}
