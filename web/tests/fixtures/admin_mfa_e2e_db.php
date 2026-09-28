<?php
/**
 * 多要素認証の E2E(admin_mfa_e2e.mjs)用の合成 DB を作る。本番の DB とファイルには触れない。
 *
 * 使い方: php admin_mfa_e2e_db.php <新しい DB の絶対パス> <パスワード(パスワードの決まりに合うもの)>
 * 作るもの(アドレスは .test だけ。どちらも多要素認証は未登録):
 *   Example Tenant(id=1)の組織管理者 mfa-e2e-admin@example.test
 *   Example Tenant(id=1)のオペレータ mfa-e2e-op@example.test
 * サーバーは TET2_SECRETS_FILE に [mfa] secret_key(32バイトの base64)を書いた一時ファイルを渡して起動する。
 */
declare(strict_types=1);

require_once __DIR__ . '/TestDatabase.php';
require_once __DIR__ . '/../../lib/PasswordPolicy.php';

[$script, $dbPath, $password] = $argv + [null, '', ''];
if ($dbPath === '' || PasswordPolicy::violation($password) !== null) {
    fwrite(STDERR, "usage: php admin_mfa_e2e_db.php <db> <password(" . PasswordPolicy::DESCRIPTION . ")>\n");
    exit(2);
}
TestDatabase::create($dbPath);
$pdo = new PDO('sqlite:' . $dbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$insert = $pdo->prepare('INSERT INTO users (tenant_id, email, password_hash, name, role, status) VALUES (?, ?, ?, ?, ?, ?)');
$hash = password_hash($password, PASSWORD_DEFAULT);
$insert->execute([1, 'mfa-e2e-admin@example.test', $hash, 'E2E 管理者', 'tenant_admin', 'active']);
$insert->execute([1, 'mfa-e2e-op@example.test', $hash, 'E2E オペレータ', 'operator', 'active']);
echo "ok\n";
