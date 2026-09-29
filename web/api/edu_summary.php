<?php declare(strict_types=1); require __DIR__."/../lib/bootstrap.php";
require_once __DIR__ . '/../lib/EduDeliverySummary.php';

/**
 * 受講期間の終了時の集計通知の設定(段D の D5、G63)。既定は切(設定の行がない)。
 *
 *   GET  ?action=setting  今の設定(有効か、送り先の担当者)と、直近の送信の記録。オペレータ以上
 *   POST ?action=save     {enabled, recipients:[...]} を保存。組織管理者以上 + CSRF
 *
 * 送るのは CLI(db/edu_delivery_summary.php)と、その timer(既定で無効)だけ。この API はメールを送らない。
 */

function es_query_tenant(): ?int
{
    if (!isset($_GET['tenant_id']) || $_GET['tenant_id'] === '') {
        return null;
    }
    $v = filter_var($_GET['tenant_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($v === false) {
        json_error('tenant_id が不正です', 400);
    }
    return (int) $v;
}

function es_handle_setting(): never
{
    $actor = require_role('operator');
    $tenantId = effective_tenant_id($actor, es_query_tenant());
    json_out(['success' => true, 'setting' => EduDeliverySummary::setting($tenantId),
        'history' => EduDeliverySummary::history($tenantId),
        'max_recipients' => EduDeliverySummary::MAX_RECIPIENTS,
        'can_edit' => in_array((string) $actor['role'], ['tenant_admin', 'superadmin'], true)]);
}

function es_handle_save(): never
{
    $actor = require_role('tenant_admin');
    tet2_require_csrf();
    $body = json_body();
    $tenantId = effective_tenant_id($actor, es_query_tenant());
    if (!is_bool($body['enabled'] ?? null)) {
        json_error('enabled が不正です', 400);
    }
    try {
        $setting = EduDeliverySummary::saveSetting($tenantId, $body['enabled'], $body['recipients'] ?? [], (string) ($actor['email'] ?? ''));
    } catch (EduDeliverySummaryException $e) {
        json_error($e->getMessage(), 400);
    }
    audit('edu_delivery.summary_setting', 'tenant_id=' . $tenantId . ',enabled=' . ($setting['enabled'] ? 1 : 0)
        . ',recipients=' . count($setting['recipients']));
    json_out(['success' => true, 'setting' => $setting]);
}

try {
    $action = $_GET['action'] ?? '';
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($action === 'setting' && $method === 'GET') {
        es_handle_setting();
    }
    if ($action === 'save' && $method === 'POST') {
        es_handle_save();
    }
    json_error('不正なアクションです', 400);
} catch (Throwable $e) {
    error_log($e->getMessage());
    json_error('サーバエラー', 500);
}
