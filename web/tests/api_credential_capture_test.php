<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
tet2_test_boot();

$tenantId = Db::insert("INSERT INTO tenants (name, slug, data_dir) VALUES ('Capture HTTP', 'capture-http', '/tmp/capture-http')");
$targetId = Db::insert('INSERT INTO targets (tenant_id, email) VALUES (?, ?)', [$tenantId, 'person@example.test']);
$campaignId = Db::insert(
    "INSERT INTO campaigns (tenant_id, name, status, credential_capture_approval_ref) VALUES (?, 'Capture HTTP', 'running', 'synthetic-approval')",
    [$tenantId]
);
Db::run('INSERT INTO campaign_targets (campaign_id, target_id, tracking_id, auth_flag) VALUES (?, ?, ?, 1)',
    [$campaignId, $targetId, '1234567890']);

$keyFile = tempnam(sys_get_temp_dir(), 'tet2-capture-http-key-');
if ($keyFile === false) { throw new RuntimeException('test key creation failed'); }
register_shutdown_function(fn() => @unlink($keyFile));
file_put_contents($keyFile, random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES));
chmod($keyFile, 0600);
putenv('TET2_CAPTURE_KEY_FILE=' . $keyFile);

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTPS'] = 'on';
$_SERVER['CONTENT_LENGTH'] = '256';
$_POST = [
    'random_value' => '1234567890',
    'auth_type' => 'box',
    'email' => 'person@example.test',
    'password' => 'SYNTHETIC_HTTP_ONLY',
];
ob_start();
require __DIR__ . '/../api/credential_capture.php';
$response = ob_get_clean();

check(http_response_code() === 200 && str_contains($response, '<html'), '承認済みHTTPS訓練は種明かしHTMLを返す');
check(!str_contains($response, 'SYNTHETIC_HTTP_ONLY'), '種明かしHTMLに入力本文を戻さない');
$capture = Db::one('SELECT ciphertext FROM credential_captures WHERE campaign_id=?', [$campaignId]);
check($capture !== null && !str_contains($capture['ciphertext'], 'SYNTHETIC_HTTP_ONLY'), '公開受信APIは暗号文だけ保存する');
$event = Db::one('SELECT raw FROM events WHERE campaign_id=? AND event_type=?', [$campaignId, 'auth']);
check($event !== null && $event['raw'] === null, '公開受信APIは通常イベントに本文を残さない');
