<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/CredentialVault.php';

function credential_captures_int(array $source, string $key): int
{
    $value = $source[$key] ?? null;
    if (!is_scalar($value) || !preg_match('/^[1-9][0-9]*$/D', (string) $value)) {
        json_error($key . ' は正の整数で指定してください', 400);
    }
    return (int) $value;
}

/** 顧客承認を確認したシステム管理者が、その参照番号をキャンペーンに紐付ける。 */
function credential_captures_handle_approve(): never
{
    $actor = require_role('superadmin');
    tet2_require_csrf();
    $body = json_body();
    $tenantId = effective_tenant_id($actor, credential_captures_int($body, 'tenant_id'));
    $campaignId = credential_captures_int($body, 'campaign_id');
    $campaign = assert_campaign_owned($campaignId, $tenantId);
    if ($campaign['closed_at'] !== null) {
        json_error('クローズ済みのキャンペーンは本文収集を承認できません', 409);
    }
    $reference = $body['approval_ref'] ?? null;
    if (!is_string($reference) || trim($reference) === '' || strlen($reference) > 200
        || preg_match('/[\x00-\x1f\x7f]/', $reference)) {
        json_error('顧客承認の参照番号を指定してください', 400);
    }
    if (Db::run('UPDATE campaigns SET credential_capture_approval_ref=? WHERE id=? AND tenant_id=? AND closed_at IS NULL',
        [trim($reference), $campaignId, $tenantId]) !== 1) {
        json_error('クローズ済みのキャンペーンは本文収集を承認できません', 409);
    }
    audit('credential_capture.approve', 'tenant_id=' . $tenantId . ',campaign_id=' . $campaignId);
    json_out(['success' => true]);
}

/** 本文は含めず、システム管理者に対象キャンペーンの収集履歴だけを示す。 */
function credential_captures_handle_list(): never
{
    $actor = require_role('superadmin');
    $tenantId = effective_tenant_id($actor, credential_captures_int($_GET, 'tenant_id'));
    $campaignId = credential_captures_int($_GET, 'campaign_id');
    assert_campaign_owned($campaignId, $tenantId);
    $offset = max(0, min(100000, (int) ($_GET['offset'] ?? 0)));
    header('Cache-Control: no-store');
    $rows = Db::all(
        'SELECT id, tracking_id, auth_type, created_at FROM credential_captures
          WHERE tenant_id=? AND campaign_id=? ORDER BY id DESC LIMIT 100 OFFSET ?',
        [$tenantId, $campaignId, $offset]
    );
    audit('credential_capture.list', 'tenant_id=' . $tenantId . ',campaign_id=' . $campaignId);
    json_out(['success' => true, 'captures' => $rows]);
}

/** 本文を復号する唯一のAPI。GET・CSV・通常レポートからは呼べない。 */
function credential_captures_handle_reveal(): never
{
    $actor = require_role('superadmin');
    tet2_require_csrf();
    $body = json_body();
    $tenantId = effective_tenant_id($actor, credential_captures_int($body, 'tenant_id'));
    $campaignId = credential_captures_int($body, 'campaign_id');
    $id = credential_captures_int($body, 'id');
    assert_campaign_owned($campaignId, $tenantId);
    header('Cache-Control: no-store');
    header('Pragma: no-cache');
    header('Referrer-Policy: no-referrer');
    $fields = CredentialVault::reveal($id, $tenantId, $campaignId);
    if ($fields === null) { json_error('入力本文が見つかりません', 404); }
    audit('credential_capture.reveal', 'tenant_id=' . $tenantId . ',campaign_id=' . $campaignId . ',capture_id=' . $id);
    json_out(['success' => true, 'fields' => $fields]);
}

try {
    $action = $_GET['action'] ?? '';
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($action === 'approve' && $method === 'POST') { credential_captures_handle_approve(); }
    if ($action === 'list' && $method === 'GET') { credential_captures_handle_list(); }
    if ($action === 'reveal' && $method === 'POST') { credential_captures_handle_reveal(); }
    json_error('不正なアクションです', 400);
} catch (Throwable $error) {
    error_log('[credential_captures] request failed: ' . get_class($error));
    json_error('サーバエラー', 500);
}
