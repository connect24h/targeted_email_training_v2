<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

tet2_test_boot();
require_once __DIR__ . '/../lib/CredentialVault.php';

$tenantId = Db::insert("INSERT INTO tenants (name, slug, data_dir) VALUES ('Capture Test', 'capture-test', '/tmp/capture-test')");
$targetId = Db::insert('INSERT INTO targets (tenant_id, email) VALUES (?, ?)', [$tenantId, 'person@example.test']);
$campaignId = Db::insert("INSERT INTO campaigns (tenant_id, name, status) VALUES (?, 'Capture test', 'running')", [$tenantId]);
$trackingId = '0123456789';
Db::run('INSERT INTO campaign_targets (campaign_id, target_id, tracking_id, auth_flag) VALUES (?, ?, ?, 1)', [$campaignId, $targetId, $trackingId]);

$keyFile = tempnam(sys_get_temp_dir(), 'tet2-capture-key-');
if ($keyFile === false) { throw new RuntimeException('test key creation failed'); }
register_shutdown_function(fn() => @unlink($keyFile));
file_put_contents($keyFile, random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES));
chmod($keyFile, 0600);
putenv('TET2_CAPTURE_KEY_FILE=' . $keyFile);

$entered = ['email' => 'person@example.test', 'password' => 'SYNTHETIC_ONLY_DO_NOT_USE'];
$rejected = false;
try { CredentialVault::record($trackingId, 'box', $entered); }
catch (DomainException $error) { $rejected = true; }
check($rejected, '承認参照のないキャンペーンは入力値を保存しない');

Db::run('UPDATE campaigns SET credential_capture_approval_ref=? WHERE id=?', ['synthetic-approval-001', $campaignId]);
$id = CredentialVault::record($trackingId, 'box', $entered);
$row = Db::one('SELECT tenant_id, campaign_id, tracking_id, ciphertext, nonce FROM credential_captures WHERE id=?', [$id]);
check($row !== null && (int) $row['tenant_id'] === $tenantId && (int) $row['campaign_id'] === $campaignId, '秘密値はtenant/campaignに紐付く');
check(!str_contains((string) $row['ciphertext'], $entered['password']), 'DBにはpassword平文を保存しない');
check(!str_contains((string) $row['ciphertext'], $entered['email']), 'DBには入力email平文を保存しない');
check(CredentialVault::reveal($id, $tenantId, $campaignId) === $entered, '承認済み本文を復号できる');
check(CredentialVault::reveal($id, $tenantId + 1, $campaignId) === null, '他tenantでは本文を取得できない');
check(CredentialVault::reveal($id, $tenantId, $campaignId + 1) === null, '他campaignでは本文を取得できない');

Db::run('UPDATE credential_captures SET ciphertext=? WHERE id=?', ['tampered', $id]);
$rejected = false;
try { CredentialVault::reveal($id, $tenantId, $campaignId); }
catch (RuntimeException $error) { $rejected = true; }
check($rejected, '暗号文の改ざんを検出し本文を返さない');
Db::run('UPDATE credential_captures SET ciphertext=? WHERE id=?', [$row['ciphertext'], $id]);

$event = Db::one('SELECT event_type, source, raw FROM events WHERE campaign_id=? AND tracking_id=?', [$campaignId, $trackingId]);
check($event !== null && $event['event_type'] === 'auth' && $event['raw'] === null, '統計イベントに本文を混ぜない');

$rejected = false;
try { CredentialVault::record('9999999999', 'box', $entered); }
catch (DomainException $error) { $rejected = true; }
check($rejected, '存在しないtracking_idの秘密値は拒否する');

$rejected = false;
try { CredentialVault::record($trackingId, 'ms365', $entered); }
catch (DomainException $error) { $rejected = true; }
check($rejected, '配信時の偽ログイン画面と異なる種別を拒否する');

$rejected = false;
try { CredentialVault::record($trackingId, 'box', ['email' => 'person@example.test', 'password' => '']); }
catch (DomainException $error) { $rejected = true; }
check($rejected, '空の入力本文を拒否する');

for ($attempt = 0; $attempt < 9; $attempt++) {
    CredentialVault::record($trackingId, 'box', $entered);
}
$rejected = false;
try { CredentialVault::record($trackingId, 'box', $entered); }
catch (DomainException $error) { $rejected = true; }
check($rejected, 'tracking_idあたりの入力回数を制限する');

putenv('TET2_CAPTURE_KEY_FILE=/tmp/tet2-missing-key-does-not-exist');
$rejected = false;
try { CredentialVault::record($trackingId, 'box', $entered); }
catch (RuntimeException $error) { $rejected = true; }
check($rejected, '鍵欠損時は平文保存へフォールバックしない');
putenv('TET2_CAPTURE_KEY_FILE=' . $keyFile);

chmod($keyFile, 0644);
$rejected = false;
try { CredentialVault::record($trackingId, 'box', $entered); }
catch (RuntimeException $error) { $rejected = true; }
check($rejected, '公開権限の鍵ファイルを拒否する');
chmod($keyFile, 0600);

check(CredentialVault::purge($campaignId, $tenantId) === 10, '対象campaignの秘密値を消去できる');
check(CredentialVault::purge($campaignId, $tenantId) === 0, '消去の再実行は冪等');
check(CredentialVault::reveal($id, $tenantId, $campaignId) === null, '消去後は復号できない');
Db::run("UPDATE campaigns SET closed_at=datetime('now') WHERE id=?", [$campaignId]);
$rejected = false;
try { CredentialVault::record($trackingId, 'box', $entered); }
catch (DomainException $error) { $rejected = true; }
check($rejected, 'クローズ後の本文再収集を拒否する');
