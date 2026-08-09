<?php declare(strict_types=1); require __DIR__."/../lib/bootstrap.php";

/**
 * 役職マスタ API。役職名(targets.title)→役職カテゴリの対応表を管理し、
 * 対象者への一括適用と、会社別・役職別の人数集計を提供する。
 *
 * マスタは「マッピング定義」であって外部キーではない。targets.position_category は
 * 各対象者が値として持ち続け、apply で上書きする。マスタを直しただけでは対象者に
 * 反映されないため、ズレ(stale)を coverage で検知して画面に出す。
 */

const POSITION_TITLE_MAX_LEN = 100;
const POSITION_NOTE_MAX_LEN = 200;

function positions_string(array $body, string $key): string
{
    if (!array_key_exists($key, $body) || !is_string($body[$key]) || trim($body[$key]) === '') {
        json_error($key . ' は必須です', 400);
    }
    return trim($body[$key]);
}

function positions_optional_nullable_string(array $body, string $key): ?string
{
    if (!array_key_exists($key, $body) || $body[$key] === null) {
        return null;
    }
    if (!is_string($body[$key])) {
        json_error($key . ' が不正です', 400);
    }
    return trim($body[$key]);
}

function positions_int(array $body, string $key): int
{
    if (!array_key_exists($key, $body) || !is_int($body[$key]) || $body[$key] < 1) {
        json_error($key . ' が不正です', 400);
    }
    return $body[$key];
}

function positions_query_int(string $key): ?int
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

function positions_body_optional_int(array $body, string $key): ?int
{
    if (!array_key_exists($key, $body) || $body[$key] === null) {
        return null;
    }
    if (!is_int($body[$key]) || $body[$key] < 1) {
        json_error($key . ' が不正です', 400);
    }
    return $body[$key];
}

/** 役職名・メモの安全検証(制御文字・長さ)。title は targets.title と突合するキーなので厳しめに。 */
function positions_assert_safe_text(string $value, string $label, int $maxLen): string
{
    if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value) === 1) {
        json_error($label . ' に使用できない文字が含まれています', 400);
    }
    if (mb_strlen($value) > $maxLen) {
        json_error($label . ' が長すぎます（最大' . $maxLen . '文字）', 400);
    }
    return $value;
}

function positions_validate_category(string $category): string
{
    // 旧称「社員」も受け入れて正規値へ寄せる(CSV/API と同じ扱い)。
    $normalized = tet2_normalize_position_category($category);
    if ($normalized === null) {
        json_error('役職カテゴリが不正です（' . implode('/', TET2_POSITION_CATEGORIES) . '）', 400);
    }
    return $normalized;
}

function positions_assert_owned(int $id, int $tenantId): array
{
    $row = Db::one('SELECT * FROM position_masters WHERE id = ? AND tenant_id = ?', [$id, $tenantId]);
    if ($row === null) {
        json_error('役職マスタが見つかりません', 404);
    }
    return $row;
}

/**
 * 集計対象の絞り込み条件。テストユーザ(is_test=1)と削除済み(archived)は既定で除外する。
 * ?include_test=1 / ?include_archived=1 で含められる(targets.php の export_csv と同じ命名)。
 *
 * @return array{0:string, 1:bool, 2:bool} [SQL片, includeTest, includeArchived]
 */
function positions_scope_clause(string $alias = 't'): array
{
    $includeTest = isset($_GET['include_test']) && $_GET['include_test'] === '1';
    $includeArchived = isset($_GET['include_archived']) && $_GET['include_archived'] === '1';
    $clause = '';
    if (!$includeTest) {
        $clause .= " AND {$alias}.is_test = 0";
    }
    if (!$includeArchived) {
        $clause .= " AND {$alias}.status != 'archived'";
    }
    return [$clause, $includeTest, $includeArchived];
}

/** 指定 title の対象者だけへマスタのカテゴリを反映する(create 直後の局所適用)。 */
function positions_apply_one(int $tenantId, string $title, string $category): int
{
    return Db::run(
        'UPDATE targets SET position_category = ? WHERE tenant_id = ? AND title = ?',
        [$category, $tenantId, $title]
    );
}

function positions_handle_list(array $actor): never
{
    $tenantId = effective_tenant_id($actor, positions_query_int('tenant_id'));
    [$scope] = positions_scope_clause('t');
    $rows = Db::all(
        "SELECT pm.id, pm.tenant_id, pm.title, pm.category, pm.sort_order, pm.note,
                pm.created_at, pm.updated_at,
                (SELECT COUNT(*) FROM targets t
                  WHERE t.tenant_id = pm.tenant_id AND t.title = pm.title{$scope}) AS target_count
           FROM position_masters pm
          WHERE pm.tenant_id = ?
          ORDER BY pm.sort_order, pm.id",
        [$tenantId]
    );
    json_out(['success' => true, 'masters' => $rows]);
}

/**
 * マスタの網羅状況。画面の中核。
 * - masters:  マスタ一覧(該当人数付き)
 * - unmapped: マスタに無い役職(=マッピング漏れ)。ユーザーがここから登録する
 * - stale:    マスタと対象者のカテゴリがズレている役職(=再適用が必要)
 */
function positions_handle_coverage(array $actor): never
{
    $tenantId = effective_tenant_id($actor, positions_query_int('tenant_id'));
    [$scope, $includeTest, $includeArchived] = positions_scope_clause('t');

    $masters = Db::all(
        "SELECT pm.id, pm.title, pm.category, pm.sort_order, pm.note,
                (SELECT COUNT(*) FROM targets t
                  WHERE t.tenant_id = pm.tenant_id AND t.title = pm.title{$scope}) AS target_count
           FROM position_masters pm
          WHERE pm.tenant_id = ?
          ORDER BY pm.sort_order, pm.id",
        [$tenantId]
    );

    // マスタに存在しない役職。title が空の人は no_title として別に数える。
    $unmapped = Db::all(
        "SELECT t.title, COUNT(*) AS count
           FROM targets t
           LEFT JOIN position_masters pm
                  ON pm.tenant_id = t.tenant_id AND pm.title = t.title
          WHERE t.tenant_id = ? AND pm.id IS NULL
            AND t.title IS NOT NULL AND t.title != ''{$scope}
          GROUP BY t.title
          ORDER BY COUNT(*) DESC, t.title",
        [$tenantId]
    );

    // マスタはあるが対象者の値が違う(=マスタ編集後に未適用)。
    $stale = Db::all(
        "SELECT pm.title, pm.category AS master_category,
                COALESCE(t.position_category, '(未設定)') AS current_category,
                COUNT(*) AS count
           FROM targets t
           INNER JOIN position_masters pm
                   ON pm.tenant_id = t.tenant_id AND pm.title = t.title
          WHERE t.tenant_id = ?
            AND (t.position_category IS NULL OR t.position_category != pm.category){$scope}
          GROUP BY pm.title, pm.category, COALESCE(t.position_category, '(未設定)')
          ORDER BY COUNT(*) DESC, pm.title",
        [$tenantId]
    );

    $noTitle = (int) (Db::one(
        "SELECT COUNT(*) AS c FROM targets t
          WHERE t.tenant_id = ? AND (t.title IS NULL OR t.title = ''){$scope}",
        [$tenantId]
    )['c'] ?? 0);

    $total = (int) (Db::one(
        "SELECT COUNT(*) AS c FROM targets t WHERE t.tenant_id = ?{$scope}",
        [$tenantId]
    )['c'] ?? 0);

    $uncategorized = (int) (Db::one(
        "SELECT COUNT(*) AS c FROM targets t
          WHERE t.tenant_id = ? AND (t.position_category IS NULL OR t.position_category = ''){$scope}",
        [$tenantId]
    )['c'] ?? 0);

    $unmappedCount = 0;
    foreach ($unmapped as $u) {
        $unmappedCount += (int) $u['count'];
    }
    $staleCount = 0;
    foreach ($stale as $s) {
        $staleCount += (int) $s['count'];
    }

    json_out([
        'success' => true,
        'categories' => TET2_POSITION_CATEGORIES,
        'scope' => ['include_test' => $includeTest, 'include_archived' => $includeArchived],
        'masters' => $masters,
        'unmapped' => $unmapped,
        'stale' => $stale,
        'summary' => [
            'total' => $total,
            'unmapped' => $unmappedCount,
            'unmapped_titles' => count($unmapped),
            'stale' => $staleCount,
            'no_title' => $noTitle,
            'uncategorized' => $uncategorized,
        ],
    ]);
}

function positions_handle_create(array $actor): never
{
    tet2_require_csrf();
    $body = json_body();
    $tenantId = effective_tenant_id($actor, positions_body_optional_int($body, 'tenant_id'));
    $title = positions_assert_safe_text(positions_string($body, 'title'), '役職名', POSITION_TITLE_MAX_LEN);
    $category = positions_validate_category(positions_string($body, 'category'));
    $note = positions_optional_nullable_string($body, 'note');
    if ($note !== null && $note !== '') {
        $note = positions_assert_safe_text($note, 'メモ', POSITION_NOTE_MAX_LEN);
    }
    $note = ($note === '') ? null : $note;

    // UNIQUE 制約に頼らず事前チェックする(テストの load_api は try/catch を読まないため、
    // PDOException のまま外へ飛ぶと 500 になる)。
    $dup = Db::one('SELECT id FROM position_masters WHERE tenant_id = ? AND title = ?', [$tenantId, $title]);
    if ($dup !== null) {
        json_error('この役職名は既に登録されています', 409);
    }

    $sortOrder = (int) (Db::one(
        'SELECT COALESCE(MAX(sort_order), 0) + 1 AS n FROM position_masters WHERE tenant_id = ?',
        [$tenantId]
    )['n'] ?? 0);

    $id = Db::insert(
        'INSERT INTO position_masters (tenant_id, title, category, sort_order, note) VALUES (?,?,?,?,?)',
        [$tenantId, $title, $category, $sortOrder, $note]
    );
    // 登録した役職の対象者へ即座に反映する(「登録したら人数が付く」を自然にする)。
    $applied = positions_apply_one($tenantId, $title, $category);
    audit('position.create', 'position_id=' . $id . ' applied=' . $applied);
    json_out([
        'success' => true,
        'master' => positions_assert_owned($id, $tenantId),
        'applied' => $applied,
    ], 201);
}

function positions_handle_update(array $actor): never
{
    tet2_require_csrf();
    $body = json_body();
    $tenantId = effective_tenant_id($actor, positions_body_optional_int($body, 'tenant_id'));
    $id = positions_int($body, 'id');
    $master = positions_assert_owned($id, $tenantId);

    // title は targets との突合キーなので変更させない(変えると旧 title の対象者が孤児になる)。
    if (array_key_exists('title', $body) && is_string($body['title'])
        && trim($body['title']) !== '' && trim($body['title']) !== (string) $master['title']) {
        json_error('役職名は変更できません。削除して登録し直してください', 400);
    }

    $category = null;
    if (array_key_exists('category', $body) && $body['category'] !== null) {
        $category = positions_validate_category(positions_string($body, 'category'));
    }
    $note = positions_optional_nullable_string($body, 'note');
    if ($note !== null && $note !== '') {
        $note = positions_assert_safe_text($note, 'メモ', POSITION_NOTE_MAX_LEN);
    }
    if ($category === null && $note === null) {
        json_error('更新項目がありません', 400);
    }

    Db::run(
        "UPDATE position_masters
            SET category = COALESCE(?, category),
                note = CASE WHEN ? IS NULL THEN note WHEN ? = '' THEN NULL ELSE ? END,
                updated_at = datetime('now','localtime')
          WHERE id = ? AND tenant_id = ?",
        [$category, $note, $note, $note, $id, $tenantId]
    );
    audit('position.update', 'position_id=' . $id);
    json_out(['success' => true, 'master' => positions_assert_owned($id, $tenantId)]);
}

/**
 * マスタ削除。targets.position_category は消さない。
 * 消す実装にすると、誤クリック1回で該当者全員が未設定になる事故が起きる。
 * 削除後は coverage の unmapped に出るので気づける。
 */
function positions_handle_delete(array $actor): never
{
    tet2_require_csrf();
    $body = json_body();
    $tenantId = effective_tenant_id($actor, positions_body_optional_int($body, 'tenant_id'));
    $id = positions_int($body, 'id');
    positions_assert_owned($id, $tenantId);

    Db::run('DELETE FROM position_masters WHERE id = ? AND tenant_id = ?', [$id, $tenantId]);
    audit('position.delete', 'position_id=' . $id);
    json_out(['success' => true, 'deleted' => true]);
}

/**
 * マスタを対象者へ一括適用する。1,900行規模でも単発 UPDATE で完結させる
 * (PHP 側で1行ずつ回すとクエリが行数分発行される)。
 */
function positions_handle_apply(array $actor): never
{
    tet2_require_csrf();
    $body = json_body();
    $tenantId = effective_tenant_id($actor, positions_body_optional_int($body, 'tenant_id'));

    // 相関サブクエリは pm.tenant_id = targets.tenant_id で必ず束縛する(他テナント混入防止)。
    $updated = Db::run(
        'UPDATE targets
            SET position_category = (
                SELECT pm.category FROM position_masters pm
                 WHERE pm.tenant_id = targets.tenant_id AND pm.title = targets.title
            )
          WHERE tenant_id = ?
            AND EXISTS (
                SELECT 1 FROM position_masters pm
                 WHERE pm.tenant_id = targets.tenant_id AND pm.title = targets.title
            )',
        [$tenantId]
    );

    $unmapped = (int) (Db::one(
        "SELECT COUNT(*) AS c
           FROM targets t
           LEFT JOIN position_masters pm ON pm.tenant_id = t.tenant_id AND pm.title = t.title
          WHERE t.tenant_id = ? AND pm.id IS NULL AND t.title IS NOT NULL AND t.title != ''",
        [$tenantId]
    )['c'] ?? 0);

    audit('position.apply', 'updated=' . $updated . ' unmapped=' . $unmapped);
    json_out(['success' => true, 'updated' => $updated, 'unmapped' => $unmapped]);
}

/** 会社別の人数。カテゴリ内訳も同時に返す(会社×カテゴリのクロス集計)。 */
function positions_handle_by_company(array $actor): never
{
    $tenantId = effective_tenant_id($actor, positions_query_int('tenant_id'));
    [$scope, $includeTest, $includeArchived] = positions_scope_clause('t');

    $rows = Db::all(
        "SELECT CASE WHEN t.company IS NULL OR t.company = '' THEN '(未設定)' ELSE t.company END AS company,
                COALESCE(NULLIF(t.position_category, ''), '(未設定)') AS category,
                COUNT(*) AS count
           FROM targets t
          WHERE t.tenant_id = ?{$scope}
          GROUP BY CASE WHEN t.company IS NULL OR t.company = '' THEN '(未設定)' ELSE t.company END,
                   COALESCE(NULLIF(t.position_category, ''), '(未設定)')",
        [$tenantId]
    );

    // 会社ごとにカテゴリ内訳を畳み込む。
    $byCompany = [];
    foreach ($rows as $r) {
        $company = (string) $r['company'];
        $byCompany[$company] ??= ['company' => $company, 'count' => 0, 'by_category' => []];
        $byCompany[$company]['count'] += (int) $r['count'];
        $byCompany[$company]['by_category'][(string) $r['category']] = (int) $r['count'];
    }
    // 人数の多い順。
    usort($byCompany, static fn(array $a, array $b): int => $b['count'] <=> $a['count']);

    $total = 0;
    foreach ($byCompany as $c) {
        $total += $c['count'];
    }

    json_out([
        'success' => true,
        'scope' => ['include_test' => $includeTest, 'include_archived' => $includeArchived],
        'categories' => TET2_POSITION_CATEGORIES,
        'total' => $total,
        'rows' => array_values($byCompany),
    ]);
}

/** 役職カテゴリ別 + 役職名別の人数。役職名別にはマスタ登録の有無を添える。 */
function positions_handle_by_position(array $actor): never
{
    $tenantId = effective_tenant_id($actor, positions_query_int('tenant_id'));
    [$scope, $includeTest, $includeArchived] = positions_scope_clause('t');

    $byCategory = Db::all(
        "SELECT COALESCE(NULLIF(t.position_category, ''), '(未設定)') AS category, COUNT(*) AS count
           FROM targets t
          WHERE t.tenant_id = ?{$scope}
          GROUP BY COALESCE(NULLIF(t.position_category, ''), '(未設定)')
          ORDER BY COUNT(*) DESC",
        [$tenantId]
    );

    // in_master=0 の行が「マスタ未登録の役職」。集計画面でも漏れが見えるようにする。
    // GROUP BY/ORDER BY は列名ではなく式で書く(title は targets と position_masters の
    // 双方にあり、別名で参照すると ambiguous column name になる)。
    $byTitle = Db::all(
        "SELECT COALESCE(NULLIF(t.title, ''), '(未設定)') AS title,
                COALESCE(NULLIF(t.position_category, ''), '(未設定)') AS category,
                CASE WHEN pm.id IS NULL THEN 0 ELSE 1 END AS in_master,
                COUNT(*) AS count
           FROM targets t
           LEFT JOIN position_masters pm ON pm.tenant_id = t.tenant_id AND pm.title = t.title
          WHERE t.tenant_id = ?{$scope}
          GROUP BY COALESCE(NULLIF(t.title, ''), '(未設定)'),
                   COALESCE(NULLIF(t.position_category, ''), '(未設定)'),
                   CASE WHEN pm.id IS NULL THEN 0 ELSE 1 END
          ORDER BY COUNT(*) DESC, COALESCE(NULLIF(t.title, ''), '(未設定)')",
        [$tenantId]
    );

    $total = 0;
    foreach ($byCategory as $c) {
        $total += (int) $c['count'];
    }

    json_out([
        'success' => true,
        'scope' => ['include_test' => $includeTest, 'include_archived' => $includeArchived],
        'categories' => TET2_POSITION_CATEGORIES,
        'total' => $total,
        'by_category' => $byCategory,
        'by_title' => $byTitle,
    ]);
}

try {
    $action = $_GET['action'] ?? '';
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $actor = require_role($method === 'GET' ? 'viewer' : 'operator');

    if ($action === 'list' && $method === 'GET') {
        positions_handle_list($actor);
    }
    if ($action === 'coverage' && $method === 'GET') {
        positions_handle_coverage($actor);
    }
    if ($action === 'by_company' && $method === 'GET') {
        positions_handle_by_company($actor);
    }
    if ($action === 'by_position' && $method === 'GET') {
        positions_handle_by_position($actor);
    }
    if ($action === 'create' && $method === 'POST') {
        positions_handle_create($actor);
    }
    if ($action === 'update' && $method === 'POST') {
        positions_handle_update($actor);
    }
    if ($action === 'delete' && $method === 'POST') {
        positions_handle_delete($actor);
    }
    if ($action === 'apply' && $method === 'POST') {
        positions_handle_apply($actor);
    }
    json_error('不正なアクションです', 400);
} catch (Throwable $e) {
    error_log($e->getMessage());
    json_error('サーバエラー', 500);
}
