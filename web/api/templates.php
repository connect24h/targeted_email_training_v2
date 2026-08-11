<?php declare(strict_types=1); require __DIR__."/../lib/bootstrap.php";

const TEMPLATE_KINDS = ['subject', 'body', 'phish_login', 'debrief', 'elearning'];
const TEMPLATE_FORMATS = ['html', 'text'];

function templates_string(array $body, string $key): string
{
    if (!array_key_exists($key, $body) || !is_string($body[$key]) || trim($body[$key]) === '') {
        json_error($key . ' は必須です', 400);
    }
    return trim($body[$key]);
}

function templates_optional_string(array $body, string $key): ?string
{
    if (!array_key_exists($key, $body)) {
        return null;
    }
    if (!is_string($body[$key]) || trim($body[$key]) === '') {
        json_error($key . ' が不正です', 400);
    }
    return trim($body[$key]);
}

function templates_int(array $body, string $key): int
{
    if (!array_key_exists($key, $body) || !is_int($body[$key]) || $body[$key] < 1) {
        json_error($key . ' が不正です', 400);
    }
    return $body[$key];
}

function templates_query_int(string $key): ?int
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

function templates_body_optional_int(array $body, string $key): ?int
{
    if (!array_key_exists($key, $body) || $body[$key] === null) {
        return null;
    }
    if (!is_int($body[$key]) || $body[$key] < 0) {
        json_error($key . ' が不正です', 400);
    }
    return $body[$key];
}

function templates_validate_kind(string $kind): void
{
    if (!in_array($kind, TEMPLATE_KINDS, true)) {
        json_error('kind が不正です', 400);
    }
}

function templates_validate_format(string $format): void
{
    if (!in_array($format, TEMPLATE_FORMATS, true)) {
        json_error('format が不正です', 400);
    }
}

function templates_validate_auth_flag(string $kind, ?int $authFlag): ?int
{
    if ($kind !== 'phish_login') {
        return null;
    }
    if ($authFlag === null || $authFlag < 0 || $authFlag > 4) {
        json_error('auth_flag が不正です', 400);
    }
    return $authFlag;
}

function templates_assert_visible(int $id, int $tenantId): array
{
    $template = Db::one(
        'SELECT * FROM templates WHERE id = ? AND (tenant_id = ? OR tenant_id IS NULL)',
        [$id, $tenantId]
    );
    if ($template === null) {
        json_error('テンプレートが見つかりません', 404);
    }
    return $template;
}

function templates_assert_owned_editable(int $id, int $tenantId): array
{
    $template = templates_assert_visible($id, $tenantId);
    if ($template['tenant_id'] === null || (int) $template['is_preset'] === 1) {
        json_error('プリセットは編集できません', 403);
    }
    if ((int) $template['tenant_id'] !== $tenantId) {
        json_error('他組織のデータにはアクセスできません', 403);
    }
    return $template;
}

function templates_is_unique_error(Throwable $e): bool
{
    return $e instanceof PDOException && str_contains($e->getMessage(), 'UNIQUE');
}

function templates_handle_list(array $actor): never
{
    $tenantId = effective_tenant_id($actor, templates_query_int('tenant_id'));
    $kind = isset($_GET['kind']) && is_string($_GET['kind']) && trim($_GET['kind']) !== '' ? trim($_GET['kind']) : null;
    if ($kind !== null) {
        templates_validate_kind($kind);
    }
    $templates = Db::all(
        'SELECT id, tenant_id, kind, name, lang, format, content, auth_flag, scenario_key, is_preset, created_at
         FROM templates
         WHERE (tenant_id = ? OR tenant_id IS NULL) AND (? IS NULL OR kind = ?)
         ORDER BY is_preset DESC, id',
        [$tenantId, $kind, $kind]
    );
    json_out(['success' => true, 'templates' => $templates]);
}

function templates_handle_get(array $actor): never
{
    $tenantId = effective_tenant_id($actor, templates_query_int('tenant_id'));
    $id = templates_query_int('id');
    if ($id === null) {
        json_error('id が不正です', 400);
    }
    json_out(['success' => true, 'template' => templates_assert_visible($id, $tenantId)]);
}

function templates_handle_create(array $actor): never
{
    tet2_require_csrf();
    $body = json_body();
    $tenantId = effective_tenant_id($actor, templates_body_optional_int($body, 'tenant_id'));
    $kind = templates_string($body, 'kind');
    $format = templates_optional_string($body, 'format') ?? 'html';
    templates_validate_kind($kind);
    templates_validate_format($format);
    $authFlag = templates_validate_auth_flag($kind, templates_body_optional_int($body, 'auth_flag'));

    $id = Db::insert(
        'INSERT INTO templates (tenant_id, kind, name, lang, format, content, auth_flag, scenario_key, is_preset)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0)',
        [
            $tenantId,
            $kind,
            templates_string($body, 'name'),
            templates_optional_string($body, 'lang') ?? 'ja',
            $format,
            templates_string($body, 'content'),
            $authFlag,
            templates_optional_string($body, 'scenario_key'),
        ]
    );
    audit('template.create', 'template_id=' . $id);
    json_out(['success' => true, 'template' => templates_assert_visible($id, $tenantId)], 201);
}

/**
 * シナリオ(件名+本文のペア)を一括作成する。
 * 件名と本文は 1 つの訓練シナリオを構成する対なので、同じ scenario_key を持つ
 * 2 レコードを 1 トランザクションで作る。片方だけ登録されて対が壊れることを防ぐ。
 * scenario_key は利用者に入力させず自動採番する(既存キーと衝突しない sc<N> 形式)。
 */
function templates_next_scenario_key(): string
{
    // 既存の sc<N> の最大値 + 1。共有プリセットの英単語キー(mfa 等)とは名前空間が分かれる。
    $rows = Db::all("SELECT scenario_key FROM templates WHERE scenario_key LIKE 'sc%'", []);
    $max = 0;
    foreach ($rows as $r) {
        if (preg_match('/^sc(\d+)$/', (string) $r['scenario_key'], $m)) {
            $max = max($max, (int) $m[1]);
        }
    }
    return 'sc' . ($max + 1);
}

function templates_handle_create_scenario(array $actor): never
{
    tet2_require_csrf();
    $body = json_body();
    $tenantId = effective_tenant_id($actor, templates_body_optional_int($body, 'tenant_id'));
    $format = templates_optional_string($body, 'format') ?? 'html';
    templates_validate_format($format);

    $name = templates_string($body, 'name');
    $subject = templates_string($body, 'subject_content');
    $bodyContent = templates_string($body, 'body_content');
    $lang = templates_optional_string($body, 'lang') ?? 'ja';

    $ids = Db::tx(function () use ($tenantId, $name, $subject, $bodyContent, $format, $lang): array {
        $key = templates_next_scenario_key();
        // 件名は本文と違い装飾が不要なので text 固定。本文のみ利用者指定の format に従う。
        $subjectId = Db::insert(
            'INSERT INTO templates (tenant_id, kind, name, lang, format, content, scenario_key, is_preset)
             VALUES (?, ?, ?, ?, ?, ?, ?, 0)',
            [$tenantId, 'subject', $name, $lang, 'text', $subject, $key]
        );
        $bodyId = Db::insert(
            'INSERT INTO templates (tenant_id, kind, name, lang, format, content, scenario_key, is_preset)
             VALUES (?, ?, ?, ?, ?, ?, ?, 0)',
            [$tenantId, 'body', $name, $lang, $format, $bodyContent, $key]
        );
        return ['key' => $key, 'subject_id' => $subjectId, 'body_id' => $bodyId];
    });

    audit('template.create_scenario', 'key=' . $ids['key']
        . ' subject_id=' . $ids['subject_id'] . ' body_id=' . $ids['body_id']);
    json_out([
        'success' => true,
        'scenario_key' => $ids['key'],
        'subject' => templates_assert_visible($ids['subject_id'], $tenantId),
        'body' => templates_assert_visible($ids['body_id'], $tenantId),
    ], 201);
}

function templates_handle_update(array $actor): never
{
    tet2_require_csrf();
    $body = json_body();
    $tenantId = effective_tenant_id($actor, templates_body_optional_int($body, 'tenant_id'));
    $id = templates_int($body, 'id');
    $template = templates_assert_visible($id, $tenantId);

    // 共有プリセット(tenant_id NULL または is_preset=1)は全テナント共通。編集は tenant_admin 以上。
    // 自テナントの非プリセットは operator 以上(従来どおり所有者のみ)。
    $isShared = ($template['tenant_id'] === null || (int) $template['is_preset'] === 1);
    if ($isShared) {
        $rank = ['viewer' => 1, 'operator' => 2, 'tenant_admin' => 3, 'superadmin' => 4];
        if (($rank[$actor['role']] ?? 0) < 3) {
            json_error('共有テンプレートの編集はテナント管理者以上が可能です', 403);
        }
    } elseif ((int) $template['tenant_id'] !== $tenantId) {
        json_error('他組織のデータにはアクセスできません', 403);
    }

    $name = templates_optional_string($body, 'name');
    $content = templates_optional_string($body, 'content');
    $format = templates_optional_string($body, 'format');
    $authFlag = templates_body_optional_int($body, 'auth_flag');

    if ($name === null && $content === null && $format === null && $authFlag === null) {
        json_error('更新項目がありません', 400);
    }
    if ($format !== null) {
        templates_validate_format($format);
    }
    if ($authFlag !== null && ((string) $template['kind'] !== 'phish_login' || $authFlag > 4)) {
        json_error('auth_flag が不正です', 400);
    }

    // 共有は tenant_id 条件を外して更新(NULL にはマッチしないため)。自テナント分は所有条件つき。
    if ($isShared) {
        Db::run(
            'UPDATE templates SET name = COALESCE(?, name), content = COALESCE(?, content),
                    format = COALESCE(?, format), auth_flag = COALESCE(?, auth_flag) WHERE id = ?',
            [$name, $content, $format, $authFlag, $id]
        );
    } else {
        Db::run(
            'UPDATE templates SET name = COALESCE(?, name), content = COALESCE(?, content),
                    format = COALESCE(?, format), auth_flag = COALESCE(?, auth_flag) WHERE id = ? AND tenant_id = ?',
            [$name, $content, $format, $authFlag, $id, $tenantId]
        );
    }
    audit('template.update', 'template_id=' . $id . ($isShared ? ',shared' : ''));
    json_out(['success' => true, 'template' => templates_assert_visible($id, $tenantId)]);
}

function templates_handle_delete(array $actor): never
{
    tet2_require_csrf();
    $body = json_body();
    $tenantId = effective_tenant_id($actor, templates_body_optional_int($body, 'tenant_id'));
    $id = templates_int($body, 'id');
    $template = templates_assert_visible($id, $tenantId);

    // 共有プリセット(tenant_id NULL / is_preset=1)は全テナント共通 → 削除は tenant_admin 以上。
    $isShared = ($template['tenant_id'] === null || (int) $template['is_preset'] === 1);
    if ($isShared) {
        $rank = ['viewer' => 1, 'operator' => 2, 'tenant_admin' => 3, 'superadmin' => 4];
        if (($rank[$actor['role']] ?? 0) < 3) {
            json_error('共有テンプレートの削除はテナント管理者以上が可能です', 403);
        }
    } elseif ((int) $template['tenant_id'] !== $tenantId) {
        json_error('他組織のデータにはアクセスできません', 403);
    }

    // 使用中チェック: campaigns 本体 + campaign_contents(複数コンテンツ)。
    // 共有テンプレは全テナントの参照を見る(tenant条件なし)。自テナント分は当テナントのみ。
    if ($isShared) {
        $used = Db::one(
            'SELECT 1 FROM campaigns WHERE subject_template_id = ? OR body_template_id = ? OR phish_template_id = ?
             UNION SELECT 1 FROM campaign_contents WHERE subject_template_id = ? OR body_template_id = ? OR phish_template_id = ?
             LIMIT 1',
            [$id, $id, $id, $id, $id, $id]
        );
    } else {
        $used = Db::one(
            'SELECT 1 FROM campaigns WHERE tenant_id = ? AND (subject_template_id = ? OR body_template_id = ? OR phish_template_id = ?) LIMIT 1',
            [$tenantId, $id, $id, $id]
        );
    }
    if ($used !== null) {
        json_error('使用中のテンプレートは削除できません', 409);
    }

    if ($isShared) {
        Db::run('DELETE FROM templates WHERE id = ?', [$id]);
    } else {
        Db::run('DELETE FROM templates WHERE id = ? AND tenant_id = ?', [$id, $tenantId]);
    }
    audit('template.delete', 'template_id=' . $id . ($isShared ? ',shared' : ''));
    json_out(['success' => true]);
}

/** 指定 kind のテンプレート(共有+自テナント)を CSV でダウンロードする。 */
function templates_handle_export_csv(array $actor): never
{
    $tenantId = effective_tenant_id($actor, templates_query_int('tenant_id'));
    $kind = (string) ($_GET['kind'] ?? '');
    templates_validate_kind($kind);
    $rows = Db::all(
        'SELECT name, kind, format, content, auth_flag, scenario_key
         FROM templates
         WHERE kind = ? AND (tenant_id = ? OR tenant_id IS NULL)
         ORDER BY is_preset DESC, id',
        [$kind, $tenantId]
    );
    $out = fopen('php://temp', 'r+');
    fputcsv($out, ['name', 'kind', 'format', 'content', 'auth_flag', 'scenario_key']);
    foreach ($rows as $r) {
        // CSVインジェクション対策: name/scenario_key を無害化。
        // content(HTML本文)は re-import で本文として復元する必要があり、先頭 ' 付与は
        // 本文を壊すため対象外(HTML本文が =,+,-,@ で始まることは実運用上ない)。
        // kind/format/auth_flag は列挙値・数値のため危険性なし。
        fputcsv($out, [
            tet2_csv_sanitize($r['name']), (string) $r['kind'], (string) $r['format'],
            (string) ($r['content'] ?? ''),
            $r['auth_flag'] === null ? '' : (string) $r['auth_flag'],
            tet2_csv_sanitize($r['scenario_key'] ?? ''),
        ]);
    }
    rewind($out);
    $csv = stream_get_contents($out);
    fclose($out);
    audit('template.export_csv', 'kind=' . $kind . ',count=' . count($rows));
    http_response_code(200);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="templates_' . $kind . '_' . date('Ymd_His') . '.csv"');
    header('X-Content-Type-Options: nosniff');
    echo "\xEF\xBB\xBF"; // UTF-8 BOM
    echo $csv;
    exit;
}

/**
 * CSV でテンプレートを一括登録する。共有テンプレート(tenant_id NULL, is_preset=1)として扱う。
 * mode=add: 同名(name+kind)が既存ならスキップ、無ければ追加。
 * mode=upsert: 同名があれば上書き更新、無ければ追加。
 * 全テナント共通に影響するため tenant_admin 以上。
 */
function templates_handle_import_csv(array $actor): never
{
    tet2_require_csrf();
    $rank = ['viewer' => 1, 'operator' => 2, 'tenant_admin' => 3, 'superadmin' => 4];
    if (($rank[$actor['role']] ?? 0) < 3) {
        json_error('テンプレートの一括登録はテナント管理者以上が可能です', 403);
    }
    $body = json_body();
    $csv = isset($body['csv']) && is_string($body['csv']) ? $body['csv'] : '';
    $mode = ($body['mode'] ?? 'add') === 'upsert' ? 'upsert' : 'add';
    if (trim($csv) === '') {
        json_error('CSV が空です', 400);
    }

    // 引用符内改行(HTML本文)に対応するため php://temp + fgetcsv でパースする。
    $fp = fopen('php://temp', 'r+');
    fwrite($fp, $csv);
    rewind($fp);
    $header = fgetcsv($fp);
    if ($header === false) {
        fclose($fp);
        json_error('CSV のヘッダーが読めません', 400);
    }
    $idx = array_flip(array_map('strval', $header));
    foreach (['name', 'kind', 'content'] as $req) {
        if (!isset($idx[$req])) {
            fclose($fp);
            json_error('CSV に必須列がありません（name, kind, content）', 400);
        }
    }
    $result = ['added' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []];
    $line = 1;
    Db::tx(function () use ($fp, $idx, $mode, &$result, &$line): void {
        while (($row = fgetcsv($fp)) !== false) {
            $line++;
            $get = fn(string $k) => isset($idx[$k], $row[$idx[$k]]) ? trim((string) $row[$idx[$k]]) : '';
            $name = $get('name');
            $kind = $get('kind');
            $content = isset($idx['content'], $row[$idx['content']]) ? (string) $row[$idx['content']] : '';
            if ($name === '' || $kind === '' || trim($content) === '') {
                if (implode('', $row) === '') { continue; } // 空行はスキップ
                $result['skipped']++;
                $result['errors'][] = ['line' => $line, 'reason' => 'name/kind/content が空'];
                continue;
            }
            if (!in_array($kind, TEMPLATE_KINDS, true)) {
                $result['skipped']++;
                $result['errors'][] = ['line' => $line, 'reason' => 'kind が不正: ' . $kind];
                continue;
            }
            $format = $get('format') !== '' ? $get('format') : 'html';
            if (!in_array($format, ['html', 'text'], true)) { $format = 'html'; }
            $authRaw = $get('auth_flag');
            $authFlag = ($kind === 'phish_login' && $authRaw !== '' && ctype_digit($authRaw) && (int) $authRaw <= 4) ? (int) $authRaw : null;
            $scenario = $get('scenario_key') !== '' ? $get('scenario_key') : null;

            $existing = Db::one('SELECT id FROM templates WHERE kind = ? AND name = ? AND tenant_id IS NULL', [$kind, $name]);
            if ($existing !== null) {
                if ($mode === 'upsert') {
                    Db::run(
                        'UPDATE templates SET content = ?, format = ?, auth_flag = ?, scenario_key = ? WHERE id = ?',
                        [$content, $format, $authFlag, $scenario, (int) $existing['id']]
                    );
                    $result['updated']++;
                } else {
                    $result['skipped']++;
                }
            } else {
                Db::run(
                    'INSERT INTO templates (tenant_id, kind, name, lang, format, content, auth_flag, scenario_key, is_preset)
                     VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, 1)',
                    [$kind, $name, 'ja', $format, $content, $authFlag, $scenario]
                );
                $result['added']++;
            }
        }
    });
    fclose($fp);
    audit('template.import_csv', 'mode=' . $mode . ',added=' . $result['added'] . ',updated=' . $result['updated']);
    json_out(['success' => true] + $result);
}

try {
    $action = $_GET['action'] ?? '';
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $actor = require_role($method === 'GET' ? 'viewer' : 'operator');

    if ($action === 'list' && $method === 'GET') {
        templates_handle_list($actor);
    }
    if ($action === 'get' && $method === 'GET') {
        templates_handle_get($actor);
    }
    if ($action === 'create_scenario' && $method === 'POST') {
        templates_handle_create_scenario($actor);
    }
    if ($action === 'create' && $method === 'POST') {
        templates_handle_create($actor);
    }
    if ($action === 'update' && $method === 'POST') {
        templates_handle_update($actor);
    }
    if ($action === 'delete' && $method === 'POST') {
        templates_handle_delete($actor);
    }
    if ($action === 'export_csv' && $method === 'GET') {
        templates_handle_export_csv($actor);
    }
    if ($action === 'import_csv' && $method === 'POST') {
        templates_handle_import_csv($actor);
    }
    json_error('不正なアクションです', 400);
} catch (Throwable $e) {
    if (templates_is_unique_error($e)) {
        json_error('テンプレートは既に存在します', 409);
    }
    error_log($e->getMessage());
    json_error('サーバエラー', 500);
}
