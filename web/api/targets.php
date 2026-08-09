<?php declare(strict_types=1); require __DIR__."/../lib/bootstrap.php";

// active=有効, suspended=一時停止(訓練対象外), archived=アーカイブ(退職/過去在籍。履歴は保持し統計に残す)
const TARGET_STATUSES = ['active', 'suspended', 'archived'];
const POSITION_CATEGORIES = ['役員', '管理職', '社員'];
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
];

// name/company/department/title 等の自由入力テキストの共通サニタイズ検証。
// 格納型XSS対策として、値に < > や制御文字(改行・タブ以外)を含むものを拒否し、長さ上限も課す。
// これらの値はメール本文・HTML(reveal/link ページ、CSV等)に差し込まれ得るため、
// 入力段階で危険文字を弾く(create/update/CSV取込の全INSERT経路で共用)。
const TARGET_TEXT_MAX_LEN = 255;

function targets_assert_safe_text(?string $value, string $label): ?string
{
    if ($value === null) {
        return null;
    }
    // 文字数(マルチバイト対応)で上限判定。
    if (mb_strlen($value) > TARGET_TEXT_MAX_LEN) {
        json_error($label . ' が長すぎます（最大' . TARGET_TEXT_MAX_LEN . '文字）', 400);
    }
    // < > を含む値は拒否(HTMLタグ注入の遮断)。
    if (strpbrk($value, '<>') !== false) {
        json_error($label . ' に使用できない文字（< >）が含まれています', 400);
    }
    // 制御文字(改行・タブ含む)を拒否。ログ/CSV/ヘッダ混入を防ぐ。
    if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
        json_error($label . ' に制御文字は使用できません', 400);
    }
    return $value;
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

function targets_handle_list(array $actor): never
{
    $tenantId = effective_tenant_id($actor, targets_query_int('tenant_id'));
    $groupId = targets_query_int('group_id');
    $q = isset($_GET['q']) && is_string($_GET['q']) && trim($_GET['q']) !== '' ? '%' . trim($_GET['q']) . '%' : null;
    // 既定はアーカイブ(退職/過去在籍)を除外。?include_archived=1 で全件(アーカイブ管理用)。
    $includeArchived = isset($_GET['include_archived']) && $_GET['include_archived'] === '1';
    $archiveClause = $includeArchived ? '' : " AND t.status != 'archived'";
    if ($groupId !== null) {
        targets_assert_group_owned($groupId, $tenantId);
        $targets = Db::all(
            'SELECT t.id, t.tenant_no, t.tenant_id, t.email, t.name, t.company, t.department, t.title, t.position_category, t.status, t.created_at
             FROM targets t
             INNER JOIN target_group tg ON tg.target_id = t.id
             INNER JOIN groups g ON g.id = tg.group_id
             WHERE t.tenant_id = ? AND g.tenant_id = ? AND tg.group_id = ? AND (? IS NULL OR t.email LIKE ? OR t.name LIKE ?)' . $archiveClause . '
             ORDER BY t.tenant_no',
            [$tenantId, $tenantId, $groupId, $q, $q, $q]
        );
        json_out(['success' => true, 'targets' => targets_attach_groups($targets, $tenantId)]);
    }

    // 非グループ経路は別名 t を使わないので status 条件を素の列名で組む。
    $archiveClause2 = $includeArchived ? '' : " AND status != 'archived'";
    $targets = Db::all(
        'SELECT id, tenant_no, tenant_id, email, name, company, department, title, position_category, status, created_at
         FROM targets
         WHERE tenant_id = ? AND (? IS NULL OR email LIKE ? OR name LIKE ?)' . $archiveClause2 . '
         ORDER BY tenant_no',
        [$tenantId, $q, $q, $q]
    );
    json_out(['success' => true, 'targets' => targets_attach_groups($targets, $tenantId)]);
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
    if ($positionCategory !== null && !in_array($positionCategory, POSITION_CATEGORIES, true)) {
        json_error('役職カテゴリが不正です（役員/管理職/社員）', 400);
    }
    $id = Db::tx(function () use ($tenantId, $email, $body, $groupIds, $positionCategory): int {
        $targetId = Db::insert(
            // tenant_no はテナント単位の連番(表示用)。グローバルな id とは別に採番する。
            'INSERT INTO targets (tenant_id, email, name, company, department, title, position_category, tenant_no)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, (SELECT COALESCE(MAX(tenant_no), 0) + 1 FROM targets WHERE tenant_id = ?))',
            [
                $tenantId,
                $email,
                targets_optional_nullable_string($body, 'name'),
                targets_optional_nullable_string($body, 'company'),
                targets_optional_nullable_string($body, 'department'),
                targets_optional_nullable_string($body, 'title'),
                $positionCategory,
                $tenantId,
            ]
        );
        targets_insert_groups($targetId, $groupIds, $tenantId);
        return $targetId;
    });
    audit('target.create', 'target_id=' . $id);
    $target = assert_target_owned($id, $tenantId);
    $target['groups'] = targets_group_names([$id], $tenantId)[$id] ?? [];
    json_out(['success' => true, 'target' => $target], 201);
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
    $groupIds = targets_int_array($body, 'group_ids');

    if ($name === null && $company === null && $department === null && $title === null && $positionCategory === null && $status === null && $groupIds === null) {
        json_error('更新項目がありません', 400);
    }
    if ($status !== null && !in_array($status, TARGET_STATUSES, true)) {
        json_error('status が不正です', 400);
    }
    if ($positionCategory !== null && $positionCategory !== '' && !in_array($positionCategory, POSITION_CATEGORIES, true)) {
        json_error('役職カテゴリが不正です（役員/管理職/社員）', 400);
    }

    if ($groupIds !== null) {
        targets_assert_groups_owned($groupIds, $tenantId);
    }

    Db::tx(function () use ($id, $tenantId, $name, $company, $department, $title, $positionCategory, $status, $groupIds): void {
        Db::run(
            'UPDATE targets
             SET name = COALESCE(?, name),
                 company = COALESCE(?, company),
                 department = COALESCE(?, department),
                 title = COALESCE(?, title),
                 position_category = COALESCE(?, position_category),
                 status = COALESCE(?, status)
             WHERE id = ? AND tenant_id = ?',
            [$name, $company, $department, $title, $positionCategory, $status, $id, $tenantId]
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

    // 論理削除(アーカイブ)。物理削除しない理由:
    //  1. 訓練履歴(campaign_targets/events/edu_*)を保持し、退職者も「よく開封する人」等の
    //     個人別統計レポートに残す(=履歴管理の要件)。
    //  2. これらは target_id を外部キー(CASCADEなし)で参照するため、物理 DELETE は FK 違反→
    //     サーバエラーになる。アーカイブなら FK 問題も原理的に発生しない。
    // アーカイブされた対象者は一覧から除外表示され、新規キャンペーンの対象にも出ない。
    Db::run(
        "UPDATE targets SET status = 'archived' WHERE id = ? AND tenant_id = ?",
        [$id, $tenantId]
    );
    audit('target.archive', 'target_id=' . $id);
    json_out(['success' => true, 'archived' => true]);
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

function targets_import_row(int $tenantId, array $row, array $map, ?int $groupId): string
{
    $email = targets_csv_value($row, $map, 'email');
    if ($email === null || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        throw new InvalidArgumentException('email が不正です');
    }
    // quoted-local-part 等を弾く簡易検証(create経路と同一ルール)。
    if (!preg_match('/^[^@\s"\'<>]+@[^@\s"\'<>]+$/', $email)) {
        throw new InvalidArgumentException('email が不正です');
    }
    $existing = Db::one('SELECT id FROM targets WHERE tenant_id = ? AND email = ?', [$tenantId, $email]);
    // 役職カテゴリは正規値(役員/管理職/社員)のみ採用。それ以外・空は NULL(取込を止めない)。
    $posCat = targets_csv_value($row, $map, 'position_category');
    if ($posCat !== null && !in_array($posCat, POSITION_CATEGORIES, true)) {
        $posCat = null;
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
            'INSERT INTO targets (tenant_id, email, name, company, department, title, position_category, tenant_no)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, (SELECT COALESCE(MAX(tenant_no), 0) + 1 FROM targets WHERE tenant_id = ?))',
            [$tenantId, $email, $values[0], $values[1], $values[2], $values[3], $values[4], $tenantId]
        );
        $result = 'imported';
    } else {
        $targetId = (int) $existing['id'];
        Db::run(
            'UPDATE targets SET name = ?, company = ?, department = ?, title = ?, position_category = COALESCE(?, position_category) WHERE id = ? AND tenant_id = ?',
            [$values[0], $values[1], $values[2], $values[3], $values[4], $targetId, $tenantId]
        );
        $result = 'updated';
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
    $result = ['imported' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []];

    Db::tx(function () use ($lines, $tenantId, $map, $groupId, &$result): void {
        foreach ($lines as $index => $line) {
            if (trim((string) $line) === '') {
                continue;
            }
            $lineNumber = $index + 2;
            try {
                $status = targets_import_row($tenantId, str_getcsv((string) $line), $map, $groupId);
                $result[$status]++;
            } catch (InvalidArgumentException $e) {
                $result['skipped']++;
                $result['errors'][] = ['line' => $lineNumber, 'reason' => $e->getMessage()];
            }
        }
    });
    audit('target.import_csv', 'imported=' . $result['imported'] . ',updated=' . $result['updated']);
    json_out([
        'success' => true,
        'imported' => $result['imported'],
        'updated' => $result['updated'],
        'skipped' => $result['skipped'],
        'errors' => $result['errors'],
    ]);
}

/**
 * 指定テナントの対象者を CSV 文字列に組む(import_csv と対称のヘッダー/カラム順)。
 * 出力副作用を持たないのでユニットテスト可能。BOM は付けない(出力ハンドラ側で付与)。
 */
function targets_build_csv(int $tenantId): string
{
    $rows = Db::all(
        'SELECT email, name, company, department, title, position_category
         FROM targets WHERE tenant_id = ? ORDER BY id',
        [$tenantId]
    );
    $out = fopen('php://temp', 'r+');
    // import が受け付ける日本語ヘッダーと同じ並びで出力(往復可能にする)。
    fputcsv($out, ['メールアドレス', '氏名', '会社名', '部署', '役職', '役職カテゴリ']);
    foreach ($rows as $r) {
        fputcsv($out, [
            (string) $r['email'],
            (string) ($r['name'] ?? ''),
            (string) ($r['company'] ?? ''),
            (string) ($r['department'] ?? ''),
            (string) ($r['title'] ?? ''),
            (string) ($r['position_category'] ?? ''),
        ]);
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
    $csv = targets_build_csv($tenantId);
    $count = max(0, substr_count($csv, "\n") - 1); // ヘッダー1行を除く概数
    audit('target.export_csv', 'count=' . $count);

    $filename = 'targets_' . date('Ymd_His') . '.csv';
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
    if ($action === 'import_csv' && $method === 'POST') {
        targets_handle_import_csv($actor);
    }
    json_error('不正なアクションです', 400);
} catch (Throwable $e) {
    if (targets_is_unique_error($e)) {
        json_error('email は既に使用されています', 409);
    }
    error_log($e->getMessage());
    json_error('サーバエラー', 500);
}
