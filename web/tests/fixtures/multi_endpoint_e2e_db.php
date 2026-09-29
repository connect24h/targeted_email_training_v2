<?php
/**
 * マルチエンドポイントの E2E(multi_endpoint_e2e.mjs)用の合成 DB を作る。本番の DB とファイルには触れない。
 *
 * 使い方: php multi_endpoint_e2e_db.php <新しい DB の絶対パス> <管理者のパスワード(パスワードの決まりに合うもの)>
 * 作るもの(アドレス/ホストは .test だけ。組織は Example Tenant(id=1)):
 *   - 組織管理者 e2e-admin@example.test(tenant_admin)/ システム管理者 e2e-super@example.test(superadmin)
 *   - 件名/本文/偽ログインのテンプレート各1本、全職員グループと対象者3人
 *   - 送信エンドポイントのマスタ: 共有の既定IP(migration 由来)、テナントの beacon 1件、テナントの from 2件
 */
declare(strict_types=1);

require_once __DIR__ . '/TestDatabase.php';
require_once __DIR__ . '/../../lib/PasswordPolicy.php';

[$script, $dbPath, $password] = $argv + [null, '', ''];
if ($dbPath === '' || PasswordPolicy::violation($password) !== null) {
    fwrite(STDERR, "usage: php multi_endpoint_e2e_db.php <db> <password(" . PasswordPolicy::DESCRIPTION . ")>\n");
    exit(2);
}
TestDatabase::create($dbPath);
$pdo = new PDO('sqlite:' . $dbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA foreign_keys = ON');
$run = static function (string $sql, array $params = []) use ($pdo): int {
    $pdo->prepare($sql)->execute($params);
    return (int) $pdo->lastInsertId();
};
$hash = password_hash($password, PASSWORD_DEFAULT);

$run('INSERT INTO users (tenant_id, email, password_hash, name, role, status) VALUES (?, ?, ?, ?, ?, ?)',
    [1, 'e2e-admin@example.test', $hash, 'E2E 管理者', 'tenant_admin', 'active']);
$run('INSERT INTO users (tenant_id, email, password_hash, name, role, status) VALUES (?, ?, ?, ?, ?, ?)',
    [null, 'e2e-super@example.test', $hash, 'E2E Super', 'superadmin', 'active']);

// テンプレート(件名/本文/偽ログイン)
$subject = $run("INSERT INTO templates (tenant_id, kind, name, content) VALUES (1, 'subject', 'E2E 件名', '重要なお知らせ')");
$body = $run("INSERT INTO templates (tenant_id, kind, name, content) VALUES (1, 'body', 'E2E 本文', 'こちら #\$1\$# をご確認ください #\$2\$# 様')");
$run("INSERT INTO templates (tenant_id, kind, name, content, auth_flag) VALUES (1, 'phish_login', 'E2E 偽ログイン', '<html><body><form>ログイン</form></body></html>', 2)");

// 対象者3人と全職員グループ
$group = $run("INSERT INTO groups (tenant_id, name, kind) VALUES (1, '全職員', 'all')");
for ($i = 1; $i <= 3; $i++) {
    $tid = $run('INSERT INTO targets (tenant_id, tenant_no, email, name, company, status) VALUES (1, ?, ?, ?, ?, ?)',
        [100 + $i, "member{$i}@example.test", "従業員 {$i}", 'Example Co', 'active']);
    $run('INSERT INTO target_group (target_id, group_id) VALUES (?, ?)', [$tid, $group]);
}

// 送信エンドポイントのマスタ(共有の既定IP は migration 由来。テナントの候補を追加)。
$run("INSERT INTO send_endpoints (tenant_id, kind, value, label, sort_order) VALUES (1, 'beacon', 'https://track1.example.test/', 'テナントの追跡1', 1)");
$run("INSERT INTO send_endpoints (tenant_id, kind, value, label, sort_order) VALUES (1, 'from', 'sales@example.test', '営業', 1)");
$run("INSERT INTO send_endpoints (tenant_id, kind, value, label, sort_order) VALUES (1, 'from', 'support@example.test', 'サポート', 2)");

echo "ok\n";
