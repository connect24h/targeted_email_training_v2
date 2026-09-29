<?php
/**
 * 段D の D4〜D6 の E2E(sending_db_e2e.mjs)用の合成 DB を作る。本番の DB とファイルには触れない。
 *
 * 使い方: php sending_db_e2e_db.php <新しい DB の絶対パス> <パスワード(パスワードの決まりに合うもの)>
 * 作るもの(アドレスは .test だけ):
 *   Example Tenant(id=1)の組織管理者 sd-admin@example.test、オペレータ sd-op@example.test、システム管理者 sd-super@example.test
 *   対象者 sd11、sd12。訓練 30(配信中)、31(クローズ済み)、32 と 33(送り終えた。クローズ前)
 *   不審メール: 訓練に結び付かない報告(sd11)、閉じる前の訓練の報告(sd11)、閉じた訓練の報告(sd12)
 *   教育の配信 40(期限を過ぎた、合格点 80。sd11 は 90点で完了、sd12 は未受講)
 *   アンケート「E2E 振り返り」(訓練後に配る)と「E2E その他」(その他のある設問。sd11 への配信とトークン)
 */
declare(strict_types=1);

require_once __DIR__ . '/TestDatabase.php';
require_once __DIR__ . '/../../lib/PasswordPolicy.php';

[$script, $dbPath, $password] = $argv + [null, '', ''];
if ($dbPath === '' || PasswordPolicy::violation($password) !== null) {
    fwrite(STDERR, "usage: php sending_db_e2e_db.php <db> <password(" . PasswordPolicy::DESCRIPTION . ")>\n");
    exit(2);
}
TestDatabase::create($dbPath);
$pdo = new PDO('sqlite:' . $dbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$run = static function (string $sql, array $params = []) use ($pdo): void {
    $pdo->prepare($sql)->execute($params);
};
$hash = password_hash($password, PASSWORD_DEFAULT);
foreach ([[1, 'sd-admin@example.test', 'E2E 管理者', 'tenant_admin'], [1, 'sd-op@example.test', 'E2E オペレータ', 'operator'],
          [null, 'sd-super@example.test', 'E2E システム管理者', 'superadmin']] as [$tenant, $email, $name, $role]) {
    $run('INSERT INTO users (tenant_id, email, password_hash, name, role, status) VALUES (?, ?, ?, ?, ?, ?)', [$tenant, $email, $hash, $name, $role, 'active']);
}
foreach ([11, 12] as $id) {
    $run("INSERT INTO targets (id, tenant_id, tenant_no, email, name, status) VALUES (?, 1, ?, ?, ?, 'active')", [$id, $id, "sd{$id}@example.test", "対象{$id}"]);
}

// 訓練
$run("INSERT INTO campaigns (id, tenant_id, name, status, created_by) VALUES (30, 1, '閉じる前の訓練', 'running', 1)");
$run("INSERT INTO campaigns (id, tenant_id, name, status, created_by, closed_at) VALUES (31, 1, '閉じた訓練', 'done', 1, '2026-09-20 10:00:00')");
$run("INSERT INTO campaigns (id, tenant_id, name, status, created_by) VALUES (32, 1, 'アンケートを配る訓練', 'done', 1)");
$run("INSERT INTO campaigns (id, tenant_id, name, status, created_by) VALUES (33, 1, '設定のない訓練', 'done', 1)");
foreach ([[30, 11], [31, 12], [32, 11], [32, 12], [33, 11]] as [$c, $t]) {
    $run("INSERT INTO campaign_targets (campaign_id, target_id, tracking_id, content_no, send_status, sent_at) VALUES (?, ?, ?, 1, 'sent', '2026-09-10 09:00:00')",
        [$c, $t, sprintf('%02d000000%02d', $c, $t)]);
}
foreach ([[32, 11, 'click'], [33, 11, 'auth']] as [$c, $t, $type]) {
    $run("INSERT INTO events (tenant_id, campaign_id, tracking_id, event_type, occurred_at, source) VALUES (1, ?, ?, ?, '2026-09-10 10:00:00', 'fixture')",
        [$c, sprintf('%02d000000%02d', $c, $t), $type]);
}

// 不審メールの報告
foreach ([['E2E 請求書の確認', 'sd11@example.test', null], ['E2E パスワードの期限', 'sd11@example.test', '3000000011'],
          ['E2E 社内アンケート', 'sd12@example.test', '3100000012']] as $i => [$subject, $reporter, $tracking]) {
    $run("INSERT INTO suspicious_mails (tenant_id, source, reporter_email, raw_path, sha256, raw_bytes, subject, from_email, received_at,
            is_training, tracking_id, analysis_json, findings_json)
          VALUES (1, 'maildir', ?, '/nonexistent.eml', ?, 10, ?, 'x@bad.test', ?, ?, ?, '{\"messages\":[]}', '[]')",
        [$reporter, hash('sha256', $subject), $subject, "2026-09-2{$i} 10:00:00", $tracking !== null ? 1 : 0, $tracking]);
}

// 教育の配信(期限を過ぎた)
$run("INSERT INTO edu_deliveries (id, tenant_id, title, status, pass_score, deadline) VALUES (40, 1, 'E2E 9月の教育', 'running', 80, '2026-09-25 17:00:00')");
$run("INSERT INTO edu_assignments (id, tenant_id, delivery_id, target_id, access_token, status, completed_at) VALUES (401, 1, 40, 11, 'e2e-tok-401', 'completed', '2026-09-20 10:00:00')");
$run("INSERT INTO edu_assignments (id, tenant_id, delivery_id, target_id, access_token, status) VALUES (402, 1, 40, 12, 'e2e-tok-402', 'assigned')");
$run('INSERT INTO edu_responses (tenant_id, assignment_id, percentage) VALUES (1, 401, 90)');

// アンケート
$run("INSERT INTO surveys (id, tenant_id, title, status) VALUES (50, 1, 'E2E 振り返り', 'draft')");
$run("INSERT INTO survey_questions (survey_id, sort_order, question_type, title, options, is_required) VALUES (50, 0, 'single', '分かりやすかったですか', '[\"はい\",\"いいえ\"]', 1)");
$run("INSERT INTO surveys (id, tenant_id, title, status) VALUES (51, 1, 'E2E その他', 'published')");
$run("INSERT INTO survey_questions (id, survey_id, sort_order, question_type, title, options, is_required, allow_other)
      VALUES (510, 51, 0, 'single', '使っている端末', '[\"会社の PC\",\"私物の PC\"]', 1, 1)");
$run("INSERT INTO survey_deliveries (id, tenant_id, survey_id, title) VALUES (52, 1, 51, 'E2E その他の配信')");
$run("INSERT INTO survey_assignments (tenant_id, delivery_id, target_id, access_token) VALUES (1, 52, 11, ?)", [str_repeat('ab', 16)]);
echo "ok\n";
