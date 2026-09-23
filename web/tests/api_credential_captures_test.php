<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
ob_start();
register_shutdown_function(static function (): void { ob_end_flush(); });
tet2_test_boot();
require_once __DIR__ . '/../lib/CredentialVault.php';
load_api('credential_captures');

$tenantId = Db::insert("INSERT INTO tenants (name, slug, data_dir) VALUES ('API Capture', 'api-capture', '/tmp/api-capture')");
$targetId = Db::insert('INSERT INTO targets (tenant_id, email) VALUES (?, ?)', [$tenantId, 'api-person@example.test']);
$campaignId = Db::insert("INSERT INTO campaigns (tenant_id, name, status) VALUES (?, 'API capture', 'running')", [$tenantId]);
Db::run('INSERT INTO campaign_targets (campaign_id, target_id, tracking_id, auth_flag) VALUES (?, ?, ?, 1)', [$campaignId, $targetId, '1234567890']);

$keyFile = tempnam(sys_get_temp_dir(), 'tet2-capture-api-key-');
if ($keyFile === false) { throw new RuntimeException('test key creation failed'); }
register_shutdown_function(fn() => @unlink($keyFile));
file_put_contents($keyFile, random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES));
chmod($keyFile, 0600);
putenv('TET2_CAPTURE_KEY_FILE=' . $keyFile);

$approval = ['tenant_id' => $tenantId, 'campaign_id' => $campaignId, 'approval_ref' => 'synthetic-approval-002'];
$r = call_handler('credential_captures_handle_approve', $approval, 'tenant_admin');
check($r['code'] === 403, '顧客管理者は本文収集を承認扱いにできない');
$r = call_handler('credential_captures_handle_approve', $approval, 'superadmin');
check($r['code'] === 200, 'システム管理者が承認参照を記録できる');

$id = CredentialVault::record('1234567890', 'box', [
    'email' => 'api-person@example.test', 'password' => 'SYNTHETIC_API_ONLY',
]);

$_GET = ['tenant_id' => (string) $tenantId, 'campaign_id' => (string) $campaignId];
$r = call_handler('credential_captures_handle_list', [], 'tenant_admin');
check($r['code'] === 403, '顧客管理者は本文一覧にアクセスできない');
$r = call_handler('credential_captures_handle_list', [], 'superadmin');
check($r['code'] === 200 && count($r['payload']['captures']) === 1, 'システム管理者のみ本文一覧を取得できる');
check(!str_contains(json_encode($r['payload']), 'SYNTHETIC_API_ONLY'), '一覧に本文は含めない');
$_GET['offset'] = '1';
$r = call_handler('credential_captures_handle_list', [], 'superadmin');
check($r['code'] === 200 && $r['payload']['captures'] === [], '本文一覧をページ送りできる');
unset($_GET['offset']);

$request = ['tenant_id' => $tenantId, 'campaign_id' => $campaignId, 'id' => $id];
$r = call_handler('credential_captures_handle_reveal', $request, 'viewer');
check($r['code'] === 403, '閲覧者は本文を取得できない');
$r = call_handler('credential_captures_handle_reveal', $request, 'operator');
check($r['code'] === 403, '運用担当者は本文を取得できない');
$r = call_handler('credential_captures_handle_reveal', $request, 'tenant_admin');
check($r['code'] === 403, '顧客管理者は本文を取得できない');
$r = call_handler('credential_captures_handle_reveal', $request, 'superadmin');
check($r['code'] === 200 && $r['payload']['fields']['password'] === 'SYNTHETIC_API_ONLY', 'システム管理者のみ本文を取得できる');
check(($GLOBALS['__TET2_TEST_AUDIT'][0]['action'] ?? '') === 'credential_capture.reveal', '本文閲覧を監査する');

$request['tenant_id'] = $tenantId + 1;
$r = call_handler('credential_captures_handle_reveal', $request, 'superadmin');
check($r['code'] === 404, '別テナントのcampaign指定で本文を取得できない');
