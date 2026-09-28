<?php
/**
 * テナントの管理の E2E(tenant_management_e2e.mjs)用の合成 DB を作る。本番の DB とファイルには触れない。
 *
 * 使い方: php tenant_e2e_db.php <新しい DB の絶対パス> <データの置き場の絶対パス> <パスワード>
 * 作るもの(アドレスは .test だけ):
 *   superadmin     e2e-super@example.test
 *   Example(有効) の利用者 e2e-active@example.test(セッションの切断を確かめる)
 *   Globex E2E(停止中)の利用者 e2e-suspended@globex.test(ログインの拒否を確かめる)
 *   Old Co(削除から100日。完全削除できる)と、そのファイル <データの置き場>/old-co/
 */
declare(strict_types=1);

require_once __DIR__ . '/TestDatabase.php';

[$script, $dbPath, $dataRoot, $password] = $argv + [null, '', '', ''];
if ($dbPath === '' || $dataRoot === '' || strlen($password) < 8) {
    fwrite(STDERR, "usage: php tenant_e2e_db.php <db> <data_root> <password(8+)>\n");
    exit(2);
}
TestDatabase::create($dbPath);
$pdo = new PDO('sqlite:' . $dbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$hash = password_hash($password, PASSWORD_DEFAULT);
$deletedAt = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->modify('-100 days')->format('Y-m-d H:i:s');

$pdo->prepare("INSERT INTO tenants (id, name, slug, data_dir, status) VALUES (5, 'Globex E2E', 'globex-e2e', ?, 'suspended')")
    ->execute([$dataRoot . '/globex-e2e']);
$pdo->prepare("INSERT INTO tenants (id, name, slug, data_dir, status, deleted_at) VALUES (6, 'Old Co', 'old-co', ?, 'deleted', ?)")
    ->execute([$dataRoot . '/old-co', $deletedAt]);
$insertUser = $pdo->prepare('INSERT INTO users (tenant_id, email, password_hash, name, role, status) VALUES (?, ?, ?, ?, ?, ?)');
$insertUser->execute([null, 'e2e-super@example.test', $hash, 'E2E Super', 'superadmin', 'active']);
$insertUser->execute([1, 'e2e-active@example.test', $hash, 'E2E Active', 'operator', 'active']);
$insertUser->execute([5, 'e2e-suspended@globex.test', $hash, 'E2E Globex', 'tenant_admin', 'active']);
$insertUser->execute([6, 'e2e-old@old.test', $hash, 'E2E Old', 'tenant_admin', 'active']);
$pdo->exec("INSERT INTO targets (tenant_id, tenant_no, email, name, status) VALUES (6, 1, 'old-target@old.test', 'Old', 'active')");
$pdo->exec("INSERT INTO campaigns (tenant_id, name, status) VALUES (6, 'Old Campaign', 'done')");
// 停止中のテナントの実行中の教育の配信(画面からの催促が 409 になることを確かめる)
$pdo->exec("INSERT INTO edu_deliveries (id, tenant_id, title, status) VALUES (900, 5, 'Globex 配信', 'running')");

if (!is_dir($dataRoot . '/old-co/campaign_1')) {
    mkdir($dataRoot . '/old-co/campaign_1', 0775, true);
}
file_put_contents($dataRoot . '/old-co/campaign_1/list.csv', "email\nold-target@old.test\n");
echo "ok\n";
