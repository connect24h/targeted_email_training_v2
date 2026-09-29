<?php
/**
 * 部署の階層(C3)の E2E(dept_levels_e2e.mjs)用の合成 DB を作る。本番の DB とファイルには触れない。
 *
 * 使い方: php dept_levels_e2e_db.php <新しい DB の絶対パス> <パスワード(パスワードの決まりに合うもの)>
 * 作るもの(アドレスは .test だけ):
 *   - Example Tenant(id=1)の組織管理者 dl-admin@example.test と、組織2の組織管理者 dl-admin2@example.test
 *   - dept_levels_seed.php の対象者、教育の配信と解答、分野のタグ、訓練「部署の階層」(部署は「本部/部/課」)
 *   - 組織2の部署は区切りのない「他社営業部」にする(選択を出さないことを確かめる)
 */
declare(strict_types=1);

require_once __DIR__ . '/TestDatabase.php';
require_once __DIR__ . '/../../lib/PasswordPolicy.php';

[$script, $dbPath, $password] = $argv + [null, '', ''];
if ($dbPath === '' || PasswordPolicy::violation($password) !== null) {
    fwrite(STDERR, "usage: php dept_levels_e2e_db.php <db> <password(" . PasswordPolicy::DESCRIPTION . ")>\n");
    exit(2);
}
TestDatabase::create($dbPath);
putenv("TET2_DB_PATH={$dbPath}");
require_once __DIR__ . '/../../lib/Db.php';
require_once __DIR__ . '/dept_levels_seed.php';

$hash = password_hash($password, PASSWORD_DEFAULT);
foreach ([[1, 'dl-admin@example.test'], [2, 'dl-admin2@example.test']] as [$tenant, $email]) {
    Db::insert("INSERT INTO users (tenant_id, email, password_hash, name, role, status) VALUES (?, ?, ?, 'E2E 管理者', 'tenant_admin', 'active')",
        [$tenant, $email, $hash]);
}
dept_levels_seed();
Db::run("UPDATE targets SET department = '他社営業部' WHERE tenant_id = 2");
echo "ok\n";
