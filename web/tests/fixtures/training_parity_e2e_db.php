<?php
/**
 * 訓練、報告、設定の小物(A-6〜A-10)の E2E(training_parity_e2e.mjs)用の合成 DB を作る。本番の DB とファイルには触れない。
 *
 * 使い方: php training_parity_e2e_db.php <新しい DB の絶対パス> <パスワード(パスワードの決まりに合うもの)>
 * 作るもの(アドレスは .test だけ):
 *   Example Tenant(id=1)の組織管理者 tp-admin@example.test、オペレータ tp-op@example.test、閲覧者 tp-viewer@example.test
 *   共有のシナリオ(概要つき)と、テナントのシナリオ(HTML の形の文字を含む概要)
 *   訓練「率の確認」: 本番の対象4人とテストの対象1人。防衛失敗2人、報告1人、配信エラー2人
 *   不審メール: テナント1に2件(件名が = で始まるものを含む)、テナント2に1件
 */
declare(strict_types=1);

require_once __DIR__ . '/TestDatabase.php';
require_once __DIR__ . '/../../lib/PasswordPolicy.php';

[$script, $dbPath, $password] = $argv + [null, '', ''];
if ($dbPath === '' || PasswordPolicy::violation($password) !== null) {
    fwrite(STDERR, "usage: php training_parity_e2e_db.php <db> <password(" . PasswordPolicy::DESCRIPTION . ")>\n");
    exit(2);
}
TestDatabase::create($dbPath);
$pdo = new PDO('sqlite:' . $dbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$run = static function (string $sql, array $params = []) use ($pdo): void {
    $pdo->prepare($sql)->execute($params);
};
$hash = password_hash($password, PASSWORD_DEFAULT);
foreach ([['tp-admin@example.test', 'E2E 管理者', 'tenant_admin'], ['tp-op@example.test', 'E2E オペレータ', 'operator'],
          ['tp-viewer@example.test', 'E2E 閲覧者', 'viewer']] as [$email, $name, $role]) {
    $run('INSERT INTO users (tenant_id, email, password_hash, name, role, status) VALUES (1, ?, ?, ?, ?, ?)', [$email, $hash, $name, $role, 'active']);
}

// シナリオ(件名と本文の対)。概要は本文の行に持つ
$run("INSERT INTO templates (tenant_id, kind, name, format, content, is_preset, scenario_key) VALUES (NULL, 'subject', 'E2E共有の件名', 'text', 'お荷物のお届け', 1, 'e2eshared')");
$run("INSERT INTO templates (tenant_id, kind, name, format, content, is_preset, scenario_key, description)
      VALUES (NULL, 'body', 'E2E共有の本文', 'text', '本文', 1, 'e2eshared', 'E2E共有の概要: 宅配業者を装う不在通知')");
$run("INSERT INTO templates (tenant_id, kind, name, format, content, is_preset, scenario_key) VALUES (1, 'subject', 'E2E自社の件名', 'text', '経費の精算', 0, 'e2eown')");
$run("INSERT INTO templates (tenant_id, kind, name, format, content, is_preset, scenario_key, description)
      VALUES (1, 'body', 'E2E自社の本文', 'text', '本文', 0, 'e2eown', '<b>太字にならない</b> 元の事例')");

// 訓練「率の確認」
foreach ([[11, 0], [12, 0], [13, 0], [14, 1]] as [$id, $isTest]) {
    $run("INSERT INTO targets (id, tenant_id, tenant_no, email, name, status, is_test) VALUES (?, 1, ?, ?, ?, 'active', ?)",
        [$id, $id, "tp{$id}@example.test", "対象{$id}", $isTest]);
}
$run("INSERT INTO campaigns (id, tenant_id, name, status, created_by) VALUES (20, 1, '率の確認', 'done', 1)");
foreach ([[1, '2000000001', 'sent'], [11, '2000000011', 'sent'], [12, '2000000012', 'pending'], [13, '2000000013', 'failed'], [14, '2000000014', 'sent']] as [$t, $tr, $st]) {
    $run('INSERT INTO campaign_targets (campaign_id, target_id, tracking_id, content_no, send_status) VALUES (20, ?, ?, 1, ?)', [$t, $tr, $st]);
}
foreach ([['2000000001', 'click'], ['2000000001', 'auth'], ['2000000011', 'auth'], ['2000000012', 'report'], ['2000000014', 'click']] as $i => [$tr, $type]) {
    $run("INSERT INTO events (tenant_id, campaign_id, tracking_id, event_type, occurred_at, source) VALUES (1, 20, ?, ?, ?, 'fixture')",
        [$tr, $type, sprintf('2026-09-01 10:%02d:00', $i)]);
}
$run("INSERT INTO delivery_log (campaign_id, tracking_id, to_email, result) VALUES (20, '2000000012', 'tp12@example.test', 'failed')");

// 不審メール
foreach ([[1, '=cmd E2E の件名', 'a@bad.test'], [1, 'E2E ふつうの件名', 'b@bad.test'], [2, '他社の不審メールE2E', 'c@bad.test']] as $i => [$tenant, $subject, $from]) {
    $run("INSERT INTO suspicious_mails (tenant_id, source, raw_path, sha256, raw_bytes, subject, from_email, received_at, analysis_json, findings_json)
          VALUES (?, 'maildir', '/nonexistent.eml', ?, 10, ?, ?, ?, '{}', '[]')", [$tenant, hash('sha256', $subject), $subject, $from, "2026-09-2{$i} 10:00:00"]);
}
echo "ok\n";
