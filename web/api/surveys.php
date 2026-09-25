<?php declare(strict_types=1); require __DIR__."/../lib/bootstrap.php";

/**
 * アンケート(U7)管理 API。設計: docs/spec/07-seculio-benchmark-update-design.md 5.7。
 * 参照は viewer 以上、変更は operator 以上 + CSRF。受講用 URL(トークン)の一覧は、
 * 誰の名前でも回答できてしまう情報なので GET でも operator 以上に限り、監査に残す。
 * メール送信(send_invitations / remind)は TET2_SURVEY_MAIL_ENABLED=1 のときだけ動く(既定は 409)。
 */

require_once __DIR__ . '/../lib/SurveyService.php';
require_once __DIR__ . '/../lib/SurveyTemplates.php';
require_once __DIR__ . '/../lib/SurveyMailer.php';
require_once __DIR__ . '/../lib/SimpleXlsx.php';

function sv_query_int(string $key): int
{
    $v = filter_var($_GET[$key] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($v === false || $v === null) {
        json_error($key . ' が不正です', 400);
    }
    return (int) $v;
}

function sv_body_int(array $body, string $key): int
{
    if (!isset($body[$key]) || !is_int($body[$key]) || $body[$key] < 1) {
        json_error($key . ' が不正です', 400);
    }
    return $body[$key];
}

function sv_query_tenant(array $actor): int
{
    $requested = isset($_GET['tenant_id']) && $_GET['tenant_id'] !== '' ? (int) $_GET['tenant_id'] : null;
    return effective_tenant_id($actor, $requested);
}

function sv_body_tenant(array $actor, array $body): int
{
    $requested = isset($body['tenant_id']) && is_int($body['tenant_id']) ? $body['tenant_id'] : null;
    return effective_tenant_id($actor, $requested);
}

/** SurveyException を HTTP のエラーへ変換して処理を実行する。 */
function sv_run(callable $fn): mixed
{
    try {
        return $fn();
    } catch (SurveyException $e) {
        json_error($e->getMessage(), $e->httpCode);
    }
}

function sv_handle_list(array $actor): never
{
    $tenantId = sv_query_tenant($actor);
    json_out(['success' => true, 'surveys' => SurveyService::listSurveys($tenantId)]);
}

function sv_handle_get(array $actor): never
{
    $tenantId = sv_query_tenant($actor);
    $id = sv_query_int('id');
    json_out(['success' => true, 'survey' => sv_run(fn() => SurveyService::getSurvey($tenantId, $id))]);
}

function sv_handle_templates(array $actor): never
{
    $out = [];
    foreach (SurveyTemplates::all() as $key => $t) {
        $out[] = ['key' => $key, 'label' => $t['label'], 'title' => $t['title'], 'question_count' => count($t['questions'])];
    }
    json_out(['success' => true, 'templates' => $out, 'mail_enabled' => SurveyMailer::enabled()]);
}

function sv_handle_create(array $actor): never
{
    tet2_require_csrf();
    $body = json_body();
    $tenantId = sv_body_tenant($actor, $body);
    $data = $body;
    if (isset($body['template']) && is_string($body['template'])) {
        $template = SurveyTemplates::get($body['template']);
        if ($template === null) {
            json_error('雛形が見つかりません', 404);
        }
        $data = $template;
    }
    $id = sv_run(fn() => SurveyService::createSurvey($tenantId, (int) $actor['id'], $data));
    audit('survey.create', 'id=' . $id);
    json_out(['success' => true, 'id' => $id]);
}

function sv_handle_update(array $actor): never
{
    tet2_require_csrf();
    $body = json_body();
    $tenantId = sv_body_tenant($actor, $body);
    $id = sv_body_int($body, 'id');
    sv_run(fn() => SurveyService::updateSurvey($tenantId, $id, $body));
    audit('survey.update', 'id=' . $id);
    json_out(['success' => true]);
}

function sv_handle_duplicate(array $actor): never
{
    tet2_require_csrf();
    $body = json_body();
    $tenantId = sv_body_tenant($actor, $body);
    $id = sv_body_int($body, 'id');
    $newId = sv_run(fn() => SurveyService::duplicateSurvey($tenantId, $id, (int) $actor['id']));
    audit('survey.duplicate', 'from=' . $id . ' to=' . $newId);
    json_out(['success' => true, 'id' => $newId]);
}

function sv_handle_delete(array $actor): never
{
    tet2_require_csrf();
    $body = json_body();
    $tenantId = sv_body_tenant($actor, $body);
    $id = sv_body_int($body, 'id');
    sv_run(fn() => SurveyService::deleteSurvey($tenantId, $id));
    audit('survey.delete', 'id=' . $id);
    json_out(['success' => true]);
}

function sv_handle_deliveries(array $actor): never
{
    $tenantId = sv_query_tenant($actor);
    $surveyId = isset($_GET['survey_id']) && $_GET['survey_id'] !== '' ? sv_query_int('survey_id') : null;
    json_out(['success' => true, 'deliveries' => SurveyService::listDeliveries($tenantId, $surveyId)]);
}

function sv_handle_deliver(array $actor): never
{
    tet2_require_csrf();
    $body = json_body();
    $tenantId = sv_body_tenant($actor, $body);
    $surveyId = sv_body_int($body, 'survey_id');
    $groupIds = $body['group_ids'] ?? [];
    if (!is_array($groupIds) || array_filter($groupIds, static fn($g): bool => !is_int($g) || $g < 1) !== []) {
        json_error('group_ids は正の整数の配列で指定してください', 400);
    }
    $deadline = isset($body['deadline']) && is_string($body['deadline']) ? $body['deadline'] : null;
    $title = isset($body['title']) && is_string($body['title']) ? $body['title'] : '';
    $result = sv_run(fn() => SurveyService::createDelivery($tenantId, $surveyId, (int) $actor['id'], $title, $deadline, $groupIds));
    audit('survey.deliver', 'survey=' . $surveyId . ' delivery=' . $result['delivery_id'] . ' assigned=' . $result['assigned']);
    json_out(['success' => true] + $result);
}

function sv_handle_close(array $actor): never
{
    tet2_require_csrf();
    $body = json_body();
    $tenantId = sv_body_tenant($actor, $body);
    $id = sv_body_int($body, 'delivery_id');
    sv_run(fn() => SurveyService::closeDelivery($tenantId, $id));
    audit('survey.close', 'delivery=' . $id);
    json_out(['success' => true]);
}

function sv_handle_results(array $actor): never
{
    $tenantId = sv_query_tenant($actor);
    $id = sv_query_int('delivery_id');
    json_out(['success' => true, 'results' => sv_run(fn() => SurveyService::results($tenantId, $id))]);
}

/** @param list<list<string>> $rows */
function sv_csv_download(string $filename, array $rows): never
{
    $out = fopen('php://temp', 'r+');
    foreach ($rows as $row) {
        fputcsv($out, array_map('tet2_csv_sanitize', $row), ',', '"', '');
    }
    rewind($out);
    $csv = stream_get_contents($out);
    fclose($out);
    http_response_code(200);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('X-Content-Type-Options: nosniff');
    echo "\xEF\xBB\xBF";
    echo $csv;
    exit;
}

/** 受講用 URL の配布一覧。未回答者だけを出す。 */
function sv_token_csv_rows(int $tenantId, int $deliveryId): array
{
    $rows = [['メールアドレス', '氏名', '部署', '回答用 URL']];
    foreach (sv_run(fn() => SurveyService::tokenRows($tenantId, $deliveryId)) as $r) {
        if ($r['status'] !== 'assigned') {
            continue;
        }
        $rows[] = [$r['email'], $r['name'], $r['department'], SurveyMailer::surveyUrl($r['token'])];
    }
    return $rows;
}

function sv_handle_tokens_csv(array $actor): never
{
    // 行頭を require にしない(テストの load_api が require 行を読み込み文として除去するため)。
    $actor = require_role('operator');
    $tenantId = sv_query_tenant($actor);
    $id = sv_query_int('delivery_id');
    $rows = sv_token_csv_rows($tenantId, $id);
    audit('survey.tokens_csv', 'delivery=' . $id . ' rows=' . (count($rows) - 1));
    sv_csv_download('survey_urls_' . $id . '_' . date('Ymd_His') . '.csv', $rows);
}

function sv_handle_export_csv(array $actor): never
{
    $tenantId = sv_query_tenant($actor);
    $id = sv_query_int('delivery_id');
    $export = sv_run(fn() => SurveyService::exportRows($tenantId, $id));
    audit('survey.export_csv', 'delivery=' . $id . ' rows=' . count($export['rows']));
    sv_csv_download('survey_answers_' . $id . '_' . date('Ymd_His') . '.csv', array_merge([$export['header']], $export['rows']));
}

function sv_handle_export_xlsx(array $actor): never
{
    $tenantId = sv_query_tenant($actor);
    $id = sv_query_int('delivery_id');
    $export = sv_run(fn() => SurveyService::exportRows($tenantId, $id));
    $results = SurveyService::results($tenantId, $id);
    $summary = [['項目', '値'], ['配信', (string) $results['delivery']['title']], ['対象人数', $results['assigned']],
        ['回答人数', $results['answered']], ['回答率(%)', $results['rate']]];
    $dist = [['設問', '選択肢', '件数']];
    foreach ($results['questions'] as $q) {
        foreach ($q['options'] as $i => $label) {
            $dist[] = [$q['title'], $label, $q['counts'][$i] ?? 0];
        }
    }
    $sanitize = static fn(array $rows): array => array_map(
        static fn(array $row): array => array_map(static fn($v) => is_string($v) ? tet2_csv_sanitize($v) : $v, $row),
        $rows
    );
    $xlsx = new SimpleXlsx();
    $xlsx->addSheet('概要', $sanitize($summary), [0 => 16, 1 => 40]);
    $xlsx->addSheet('選択肢の集計', $sanitize($dist), [0 => 50, 1 => 36, 2 => 8]);
    $xlsx->addSheet('回答', $sanitize(array_merge([$export['header']], $export['rows'])));
    audit('survey.export_xlsx', 'delivery=' . $id . ' rows=' . count($export['rows']));
    $xlsx->download('survey_' . $id . '_' . date('Ymd_His') . '.xlsx');
}

function sv_handle_send_invitations(array $actor): never
{
    tet2_require_csrf();
    $body = json_body();
    $tenantId = sv_body_tenant($actor, $body);
    $id = sv_body_int($body, 'delivery_id');
    $result = sv_run(fn() => SurveyMailer::sendInvitations($tenantId, $id));
    audit('survey.send_invitations', 'delivery=' . $id . ' sent=' . $result['sent'] . ' failed=' . $result['failed']);
    json_out(['success' => true] + $result);
}

function sv_handle_remind(array $actor): never
{
    tet2_require_csrf();
    $body = json_body();
    $tenantId = sv_body_tenant($actor, $body);
    $id = sv_body_int($body, 'delivery_id');
    $result = sv_run(fn() => SurveyMailer::remind($tenantId, $id));
    audit('survey.remind', 'delivery=' . $id . ' sent=' . $result['sent'] . ' failed=' . $result['failed']);
    json_out(['success' => true] + $result);
}

try {
    $action = $_GET['action'] ?? '';
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $actor = require_role($method === 'GET' ? 'viewer' : 'operator');

    $get = ['list' => 'sv_handle_list', 'get' => 'sv_handle_get', 'templates' => 'sv_handle_templates',
        'deliveries' => 'sv_handle_deliveries', 'results' => 'sv_handle_results', 'tokens_csv' => 'sv_handle_tokens_csv',
        'export_csv' => 'sv_handle_export_csv', 'export_xlsx' => 'sv_handle_export_xlsx'];
    $post = ['create' => 'sv_handle_create', 'update' => 'sv_handle_update', 'duplicate' => 'sv_handle_duplicate',
        'delete' => 'sv_handle_delete', 'deliver' => 'sv_handle_deliver', 'close' => 'sv_handle_close',
        'send_invitations' => 'sv_handle_send_invitations', 'remind' => 'sv_handle_remind'];
    $table = $method === 'GET' ? $get : ($method === 'POST' ? $post : []);
    if (isset($table[$action])) {
        $table[$action]($actor);
    }
    json_error('不正なアクションです', 400);
} catch (Throwable $e) {
    error_log($e->getMessage());
    json_error('サーバエラー', 500);
}
