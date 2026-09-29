<?php
/**
 * 測定の正しさ(段B1)の E2E(training_measurement_e2e.mjs)用の合成 DB を作る。本番の DB とファイルには触れない。
 *
 * 使い方: php training_measurement_e2e_db.php <新しい DB の絶対パス> <パスワード(パスワードの決まりに合うもの)>
 * 作るもの(アドレスは .test だけ):
 *   Example Tenant(id=1)のオペレータ tm-op@example.test、閲覧者 tm-viewer@example.test
 *   訓練「測定の確認」: 対象4人。
 *     tm11 利用者のクリックと返信、tm12 装置(Proofpoint)のクリックだけ、tm13 届かない宛先(前の訓練でも届かない)、tm14 何もしない
 *   ほかのテナント(id=2)の訓練と装置の行(見えないこと)
 */
declare(strict_types=1);

require_once __DIR__ . '/TestDatabase.php';
require_once __DIR__ . '/../../lib/PasswordPolicy.php';

[$script, $dbPath, $password] = $argv + [null, '', ''];
if ($dbPath === '' || PasswordPolicy::violation($password) !== null) {
    fwrite(STDERR, "usage: php training_measurement_e2e_db.php <db> <password(" . PasswordPolicy::DESCRIPTION . ")>\n");
    exit(2);
}
TestDatabase::create($dbPath);
$pdo = new PDO('sqlite:' . $dbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$run = static function (string $sql, array $params = []) use ($pdo): void {
    $pdo->prepare($sql)->execute($params);
};
$hash = password_hash($password, PASSWORD_DEFAULT);
foreach ([['tm-op@example.test', 'E2E オペレータ', 'operator'], ['tm-viewer@example.test', 'E2E 閲覧者', 'viewer']] as [$email, $name, $role]) {
    $run('INSERT INTO users (tenant_id, email, password_hash, name, role, status) VALUES (1, ?, ?, ?, ?, ?)', [$email, $hash, $name, $role, 'active']);
}
foreach ([11, 12, 13, 14] as $id) {
    $run("INSERT INTO targets (id, tenant_id, tenant_no, email, name, department, status) VALUES (?, 1, ?, ?, ?, '総務', 'active')",
        [$id, $id, "tm{$id}@example.test", "測定{$id}"]);
}
$run("INSERT INTO campaigns (id, tenant_id, name, status, created_by, from_address) VALUES (40, 1, '前の訓練', 'done', 1, 'sender@train.example.test')");
$run("INSERT INTO campaigns (id, tenant_id, name, status, created_by, from_address) VALUES (41, 1, '測定の確認', 'done', 1, 'sender@train.example.test')");
$run("INSERT INTO campaign_targets (campaign_id, target_id, tracking_id, content_no, send_status, sent_at, delivery_state, delivery_state_at, delivery_detail)
      VALUES (40, 13, '4000000013', 1, 'sent', '2026-08-01 10:00:00', 'undeliverable', '2026-08-01 10:00:05', 'mail.log bounced dsn=5.1.1')");
foreach ([11, 12, 13, 14] as $id) {
    $state = $id === 13 ? 'undeliverable' : 'delivered';
    $run("INSERT INTO campaign_targets (campaign_id, target_id, tracking_id, content_no, send_status, sent_at, delivery_state, delivery_state_at, delivery_detail)
          VALUES (41, ?, ?, 1, 'sent', '2026-09-28 10:00:00', ?, '2026-09-28 10:00:05', ?)",
        [$id, '41000000' . $id, $state, $id === 13 ? 'mail.log bounced dsn=5.1.1 (550 User unknown)' : 'mail.log sent dsn=2.0.0']);
}
$human = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36';
$line = static fn (string $ip, string $tid, string $ua): string =>
    $ip . ' - - [28/Sep/2026:11:00:00 +0900] "GET /link-' . $tid . '.html HTTP/1.1" 200 100 "-" "' . $ua . '"';
$event = static function (int $tenant, int $campaign, string $tid, string $type, string $at, string $source, string $raw, string $verdict, ?string $reason) use ($run): void {
    $run('INSERT INTO events (tenant_id, campaign_id, tracking_id, event_type, occurred_at, source, raw, verdict, verdict_reason) VALUES (?,?,?,?,?,?,?,?,?)',
        [$tenant, $campaign, $tid, $type, $at, $source, $raw, $verdict, $reason]);
};
$event(1, 41, '4100000011', 'click', '2026-09-28 11:00:00', 'apache_access', $line('198.51.100.7', '4100000011', $human), 'user', null);
$event(1, 41, '4100000011', 'reply', '2026-09-28 12:00:00', 'reply_mail', '{}', 'user', null);
$event(1, 41, '4100000012', 'click', '2026-09-28 10:00:30', 'apache_access', $line('203.0.113.9', '4100000012', 'Mozilla/5.0 Proofpoint-URLDefense'),
    'scanner', 'User-Agent に「Proofpoint」');
// ほかのテナント
$run("INSERT INTO campaigns (id, tenant_id, name, status, created_by) VALUES (50, 2, '他社の測定', 'done', 2)");
$run("INSERT INTO campaign_targets (campaign_id, target_id, tracking_id, content_no, send_status, sent_at) VALUES (50, 3, '5000000003', 1, 'sent', '2026-09-28 10:00:00')");
$event(2, 50, '5000000003', 'click', '2026-09-28 10:00:30', 'apache_access', $line('203.0.113.1', '5000000003', 'curl/8'), 'scanner', 'User-Agent に「curl」');
echo "ok\n";
