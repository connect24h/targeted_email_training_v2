<?php
/**
 * 通知の文面の E2E(notification_templates_e2e.mjs)用の合成 DB を作る。本番の DB とファイルには触れない。
 *
 * 使い方: php notification_templates_e2e_db.php <新しい DB の絶対パス> <パスワード(パスワードの決まりに合うもの)>
 * 作るもの(アドレスは .test だけ):
 *   Example Tenant(id=1)の組織管理者 e2e-admin@example.test と、オペレータ e2e-op@example.test
 */
declare(strict_types=1);

require_once __DIR__ . '/TestDatabase.php';
require_once __DIR__ . '/../../lib/PasswordPolicy.php';

[$script, $dbPath, $password] = $argv + [null, '', ''];
if ($dbPath === '' || PasswordPolicy::violation($password) !== null) {
    fwrite(STDERR, "usage: php notification_templates_e2e_db.php <db> <password(" . PasswordPolicy::DESCRIPTION . ")>\n");
    exit(2);
}
TestDatabase::create($dbPath);
$pdo = new PDO('sqlite:' . $dbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$hash = password_hash($password, PASSWORD_DEFAULT);
$stmt = $pdo->prepare('INSERT INTO users (tenant_id, email, password_hash, name, role, status) VALUES (?, ?, ?, ?, ?, ?)');
$stmt->execute([1, 'e2e-admin@example.test', $hash, 'E2E 管理者', 'tenant_admin', 'active']);
$stmt->execute([1, 'e2e-op@example.test', $hash, 'E2E オペレータ', 'operator', 'active']);
echo "ok\n";
