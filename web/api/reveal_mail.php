<?php declare(strict_types=1); require __DIR__."/../lib/bootstrap.php";
require_once __DIR__ . '/../lib/RevealMail.php';

/**
 * 訓練ごとの種明かしメールの設定(段D の D2、G06)。既定は3つの条件がすべて切。
 *
 *   GET  ?action=get&campaign_id=N   設定と、条件ごとに送った通数(閲覧者以上)
 *   POST ?action=save                {campaign_id, on_fail, on_close, close_scope, on_report} を保存(オペレータ以上、CSRF)
 *
 * 保存では送らない。送るのは timer の CLI(web/db/reveal_mail.php)と、訓練を閉じる操作((b) と (c) だけ)。
 */

function rm_campaign_id(mixed $v): int
{
    $id = filter_var($v, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false) {
        json_error('campaign_id が不正です', 400);
    }
    return (int) $id;
}

function rm_flag(array $body, string $key): bool
{
    $v = $body[$key] ?? false;
    if (!is_bool($v) && $v !== 0 && $v !== 1) {
        json_error($key . ' は true か false で指定してください', 400);
    }
    return (bool) $v;
}

/** 自テナントの削除されていないキャンペーン。 */
function rm_campaign(int $campaignId, int $tenantId): array
{
    $row = Db::one('SELECT id, name, status, is_test, closed_at FROM campaigns WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL',
        [$campaignId, $tenantId]);
    if ($row === null) {
        json_error('キャンペーンが見つかりません', 404);
    }
    return $row;
}

function rm_payload(int $tenantId, array $campaign): array
{
    $counts = [];
    foreach (Db::all("SELECT kind, status, COUNT(*) AS n FROM notification_sends WHERE tenant_id = ? AND campaign_id = ? GROUP BY kind, status",
        [$tenantId, (int) $campaign['id']]) as $r) {
        $counts[$r['kind']][$r['status']] = (int) $r['n'];
    }
    return [
        'success' => true,
        'campaign' => ['id' => (int) $campaign['id'], 'name' => $campaign['name'], 'status' => $campaign['status'],
            'is_test' => (int) $campaign['is_test'] === 1, 'closed_at' => $campaign['closed_at']],
        'settings' => RevealMail::settings($tenantId, (int) $campaign['id']),
        'sent' => $counts,
    ];
}

function rm_handle_get(): never
{
    $actor = require_role('viewer');
    $tenantId = effective_tenant_id($actor, isset($_GET['tenant_id']) ? (int) $_GET['tenant_id'] : null);
    $campaign = rm_campaign(rm_campaign_id($_GET['campaign_id'] ?? null), $tenantId);
    json_out(rm_payload($tenantId, $campaign));
}

function rm_handle_save(): never
{
    $actor = require_role('operator');
    tet2_require_csrf();
    $body = json_body();
    $tenantId = effective_tenant_id($actor, isset($body['tenant_id']) && is_int($body['tenant_id']) ? $body['tenant_id'] : null);
    $campaign = rm_campaign(rm_campaign_id($body['campaign_id'] ?? null), $tenantId);
    $scope = $body['close_scope'] ?? 'all';
    if (!is_string($scope) || !in_array($scope, RevealMail::CLOSE_SCOPES, true)) {
        json_error('close_scope が不正です', 400);
    }
    $in = ['on_fail' => rm_flag($body, 'on_fail'), 'on_close' => rm_flag($body, 'on_close'),
        'close_scope' => $scope, 'on_report' => rm_flag($body, 'on_report')];
    $saved = RevealMail::saveSettings($tenantId, (int) $campaign['id'], $in, (string) ($actor['email'] ?? ''));
    audit('campaign.reveal_mail_settings', 'campaign_id=' . (int) $campaign['id'] . ',on_fail=' . $saved['on_fail']
        . ',on_close=' . $saved['on_close'] . ',close_scope=' . $saved['close_scope'] . ',on_report=' . $saved['on_report']);
    json_out(rm_payload($tenantId, $campaign));
}

try {
    $action = $_GET['action'] ?? '';
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($action === 'get' && $method === 'GET') {
        rm_handle_get();
    }
    if ($action === 'save' && $method === 'POST') {
        rm_handle_save();
    }
    json_error('不正なアクションです', 400);
} catch (Throwable $e) {
    error_log($e->getMessage());
    json_error('サーバエラー', 500);
}
