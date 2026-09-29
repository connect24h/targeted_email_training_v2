<?php
/**
 * 段D の送信(D-a)の設定の画面の E2E(sending_da_e2e.mjs)用の合成 DB を作る。本番の DB とファイルには触れない。
 *
 * 使い方: php sending_da_e2e_db.php <新しい DB の絶対パス> <パスワード(パスワードの決まりに合うもの)>
 * 作るもの(アドレスは .test だけ。組織は Example Tenant(id=1)):
 *   - 組織管理者 e2e-admin@example.test、オペレータ e2e-op@example.test
 *   - 配信の終わった訓練「E2E 訓練」(target1 が失敗、target2 が報告。閉じる前)
 *   - 開始後の教育の配信「E2E 開始後の配信」(期限の後の受講を許す)と、下書きの配信「E2E 下書きの配信」
 * 標準出力に JSON(訓練と配信の id)を出す。
 */
declare(strict_types=1);

require_once __DIR__ . '/TestDatabase.php';
require_once __DIR__ . '/../../lib/PasswordPolicy.php';

[$script, $dbPath, $password] = $argv + [null, '', ''];
if ($dbPath === '' || PasswordPolicy::violation($password) !== null) {
    fwrite(STDERR, "usage: php sending_da_e2e_db.php <db> <password(" . PasswordPolicy::DESCRIPTION . ")>\n");
    exit(2);
}
TestDatabase::create($dbPath);
$pdo = new PDO('sqlite:' . $dbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA foreign_keys = ON');
$insert = static function (string $sql, array $params = []) use ($pdo): int {
    $pdo->prepare($sql)->execute($params);
    return (int) $pdo->lastInsertId();
};
$hash = password_hash($password, PASSWORD_DEFAULT);
$insert("INSERT INTO users (tenant_id, email, password_hash, name, role, status) VALUES (1, 'e2e-admin@example.test', ?, 'E2E 管理者', 'tenant_admin', 'active')", [$hash]);
$insert("INSERT INTO users (tenant_id, email, password_hash, name, role, status) VALUES (1, 'e2e-op@example.test', ?, 'E2E オペレータ', 'operator', 'active')", [$hash]);

$campaign = $insert("INSERT INTO campaigns (tenant_id, name, status, start_at, created_by) VALUES (1, 'E2E 訓練', 'done', '2026-09-01 10:00:00', 1)");
$insert("INSERT INTO campaign_targets (campaign_id, target_id, tracking_id, content_no, send_status, sent_at) VALUES (?, 1, 'E2E0000001', 1, 'sent', '2026-09-01 10:00:00')", [$campaign]);
$insert("INSERT INTO campaign_targets (campaign_id, target_id, tracking_id, content_no, send_status, sent_at) VALUES (?, 2, 'E2E0000002', 1, 'sent', '2026-09-01 10:00:00')", [$campaign]);
$insert("INSERT INTO events (tenant_id, campaign_id, tracking_id, event_type, occurred_at, source) VALUES (1, ?, 'E2E0000001', 'click', '2026-09-01 11:00:00', 'fixture')", [$campaign]);
$insert("INSERT INTO events (tenant_id, campaign_id, tracking_id, event_type, occurred_at, source) VALUES (1, ?, 'E2E0000002', 'report', '2026-09-01 11:00:00', 'fixture')", [$campaign]);

$running = $insert("INSERT INTO edu_deliveries (tenant_id, title, status, delivery_type, target_type, deadline, allow_after_deadline)
    VALUES (1, 'E2E 開始後の配信', 'running', 'awareness_quiz', 'individual', '2026-12-31 17:00:00', 1)");
$insert("INSERT INTO edu_assignments (tenant_id, delivery_id, target_id, access_token, status) VALUES (1, ?, 1, ?, 'assigned')", [$running, str_repeat('e', 32)]);
$draft = $insert("INSERT INTO edu_deliveries (tenant_id, title, status, delivery_type, target_type) VALUES (1, 'E2E 下書きの配信', 'draft', 'awareness_quiz', 'all')");

echo json_encode(['campaign_id' => $campaign, 'running_delivery_id' => $running, 'draft_delivery_id' => $draft]), "\n";
