<?php
/**
 * シナリオ画面の E2E(scenario_templates_e2e.mjs)用の合成 DB を作る。本番の DB とファイルには触れない。
 *
 * 使い方: php scenario_templates_e2e_db.php <新しい DB の絶対パス> <パスワード(パスワードの決まりに合うもの)>
 * 作るもの(アドレスは .test だけ):
 *   Example Tenant(id=1)の組織管理者 e2e-admin@example.test と、閲覧者 e2e-viewer@example.test
 *   共有プリセットのシナリオ(件名+本文、scenario_key=e2e_shared)と、自テナントのシナリオ(sc1)
 */
declare(strict_types=1);

require_once __DIR__ . '/TestDatabase.php';
require_once __DIR__ . '/../../lib/PasswordPolicy.php';

[$script, $dbPath, $password] = $argv + [null, '', ''];
if ($dbPath === '' || PasswordPolicy::violation($password) !== null) {
    fwrite(STDERR, "usage: php scenario_templates_e2e_db.php <db> <password(" . PasswordPolicy::DESCRIPTION . ")>\n");
    exit(2);
}
TestDatabase::create($dbPath);
$pdo = new PDO('sqlite:' . $dbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$hash = password_hash($password, PASSWORD_DEFAULT);
$stmt = $pdo->prepare('INSERT INTO users (tenant_id, email, password_hash, name, role, status) VALUES (?, ?, ?, ?, ?, ?)');
$stmt->execute([1, 'e2e-admin@example.test', $hash, 'E2E 管理者', 'tenant_admin', 'active']);
$stmt->execute([1, 'e2e-viewer@example.test', $hash, 'E2E 閲覧者', 'viewer', 'active']);
$tpl = $pdo->prepare('INSERT INTO templates (tenant_id, kind, name, lang, format, content, scenario_key, description, is_preset) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
$tpl->execute([null, 'subject', '共有の宅配不在通知', 'ja', 'text', 'お荷物のお届けについて', 'e2e_shared', null, 1]);
$tpl->execute([null, 'body', '共有の宅配不在通知', 'ja', 'html', '<p>共有本文のプレビュー</p>', 'e2e_shared', '共有の概要', 1]);
$tpl->execute([1, 'subject', '既存の経費精算', 'ja', 'text', '経費精算の差し戻し', 'sc1', null, 0]);
$tpl->execute([1, 'body', '既存の経費精算', 'ja', 'html', '<p>既存本文</p>', 'sc1', null, 0]);
echo "ok\n";
