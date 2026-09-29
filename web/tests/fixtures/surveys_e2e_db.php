<?php
/**
 * アンケート(U7)の E2E(surveys_e2e.mjs)用の合成 DB を作る。本番の DB とファイルには触れない。
 *
 * 使い方: php surveys_e2e_db.php <新しい DB の絶対パス> <パスワード(パスワードの決まりに合うもの)>
 * 作るもの(アドレスは .test だけ。組織は Example Tenant(id=1)):
 *   - 組織管理者 sv-admin@example.test(パスワードは引数のもの)
 *   - 対象者2名: 合成データの target1@example.test(総務部)と target2@example.test(情報システム部)。
 *     結果の部署別と回答率 50% の確かめに使う
 *   - グループ「全職員」(kind='all'。テナントの有効な対象者の全員。配信の宛先に選ぶ)
 * アンケートは作らない(E2E が画面から作る)。
 */
declare(strict_types=1);

require_once __DIR__ . '/TestDatabase.php';
require_once __DIR__ . '/../../lib/PasswordPolicy.php';

[$script, $dbPath, $password] = $argv + [null, '', ''];
if ($dbPath === '' || PasswordPolicy::violation($password) !== null) {
    fwrite(STDERR, "usage: php surveys_e2e_db.php <db> <password(" . PasswordPolicy::DESCRIPTION . ")>\n");
    exit(2);
}
TestDatabase::create($dbPath);
$pdo = new PDO('sqlite:' . $dbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA foreign_keys = ON');
$run = static function (string $sql, array $params = []) use ($pdo): void {
    $pdo->prepare($sql)->execute($params);
};

$run("INSERT INTO users (tenant_id, email, password_hash, name, role, status) VALUES (1, 'sv-admin@example.test', ?, 'E2E 管理者', 'tenant_admin', 'active')",
    [password_hash($password, PASSWORD_DEFAULT)]);
// 対象者は TestDatabase の合成データの target1、target2(tenant 1 の有効な対象者はこの2名だけ)に部署を付けて使う。
foreach ([[1, '総務部'], [2, '情報システム部']] as [$id, $department]) {
    $run('UPDATE targets SET department = ? WHERE id = ? AND tenant_id = 1', [$department, $id]);
}
$count = (int) $pdo->query("SELECT COUNT(*) FROM targets WHERE tenant_id = 1 AND status = 'active'")->fetchColumn();
if ($count !== 2) {
    fwrite(STDERR, "expected 2 active targets in tenant 1, got {$count}\n");
    exit(1);
}
$run("INSERT INTO groups (tenant_id, name, kind) VALUES (1, '全職員', 'all')");
echo "ok\n";
