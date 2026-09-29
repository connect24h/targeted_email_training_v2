<?php declare(strict_types=1); require __DIR__."/../lib/bootstrap.php";
require_once __DIR__ . '/../lib/ReportNotify.php';

/**
 * 担当者への不審メールの報告の通知先(段D の D3、G38)。組織管理者以上。既定は空(通知しない)。
 *
 *   GET  ?action=get    通知先のアドレス、入れた日時、上限の説明
 *   POST ?action=save   {emails: "a@x\nb@y" か ["a@x", ...]} を保存(CSRF)。空にすると通知しない
 *
 * 保存では送らない。送るのは timer の CLI(web/db/reveal_mail.php)。
 */

function rn_payload(int $tenantId): array
{
    $s = ReportNotify::settings($tenantId);
    return ['success' => true, 'tenant_id' => $tenantId, 'emails' => $s['emails'], 'since' => $s['since'],
        'updated_by' => $s['updated_by'], 'updated_at' => $s['updated_at'],
        'limits' => ['emails' => ReportNotify::MAX_EMAILS, 'per_window' => ReportNotify::RATE_LIMIT,
            'window_minutes' => ReportNotify::RATE_WINDOW_MINUTES]];
}

/** 操作の対象のテナント。存在しないテナントは 404。 */
function rn_tenant(array $actor, mixed $requested): int
{
    $req = null;
    if ($requested !== null && $requested !== '') {
        $v = filter_var($requested, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($v === false) {
            json_error('tenant_id が不正です', 400);
        }
        $req = (int) $v;
    }
    $tenantId = effective_tenant_id($actor, $req);
    if (Db::one('SELECT id FROM tenants WHERE id = ?', [$tenantId]) === null) {
        json_error('テナントが見つかりません', 404);
    }
    return $tenantId;
}

function rn_handle_get(): never
{
    $actor = require_role('tenant_admin');
    json_out(rn_payload(rn_tenant($actor, $_GET['tenant_id'] ?? null)));
}

function rn_handle_save(): never
{
    $actor = require_role('tenant_admin');
    tet2_require_csrf();
    $body = json_body();
    $tenantId = rn_tenant($actor, $body['tenant_id'] ?? null);
    $emails = $body['emails'] ?? null;
    if (!is_string($emails) && !is_array($emails)) {
        json_error('emails を指定してください', 400);
    }
    try {
        $saved = ReportNotify::save($tenantId, $emails, (string) ($actor['email'] ?? ''));
    } catch (DomainException $e) {
        json_error($e->getMessage(), 400);
    }
    audit('report_notify.save', 'tenant_id=' . $tenantId . ',count=' . count($saved['emails']));
    json_out(rn_payload($tenantId));
}

try {
    $action = $_GET['action'] ?? '';
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($action === 'get' && $method === 'GET') {
        rn_handle_get();
    }
    if ($action === 'save' && $method === 'POST') {
        rn_handle_save();
    }
    json_error('不正なアクションです', 400);
} catch (Throwable $e) {
    error_log($e->getMessage());
    json_error('サーバエラー', 500);
}
