<?php
declare(strict_types=1);

/**
 * 段B1(G01、G57): 訓練のレポートの行動履歴、利用者ごと、判定の修正。
 * - 行動履歴は装置の行(scanner)も判定と理由つきで出す。IP と端末の概要を出し、元の行(raw)は返さない
 * - 判定の修正はオペレータ以上、CSRF、監査ログ。ほかのテナントの行は 404、ほかのテナントの指定は 403
 * - 直した判定は集計(概要のクリック数)にすぐ効く。click_bot(事故の記録)とクローズ済みの訓練は直せない
 * - 利用者ごとは宛先1件1行で、初回クリック、報告、返信、配信エラーをまとめる(装置の行は入れない)
 */

require_once __DIR__ . '/helpers.php';
tet2_test_boot();
require_once __DIR__ . '/../lib/TrainingActions.php';
load_api('report');

$HUMAN = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5.2 Mobile/15E148 Safari/604.1';
$apache = static fn (string $ip, string $path, string $ua): string =>
    $ip . ' - - [01/Sep/2026:10:00:00 +0900] "GET ' . $path . ' HTTP/1.1" 200 100 "-" "' . $ua . '"';

foreach ([51, 52] as $id) {
    Db::run("INSERT INTO targets (id, tenant_id, tenant_no, email, name, department, status) VALUES (?, 1, ?, ?, ?, '総務', 'active')",
        [$id, $id, "a{$id}@example.test", "対象{$id}"]);
}
Db::run("INSERT INTO campaigns (id, tenant_id, name, status, created_by) VALUES (50, 1, '行動履歴', 'done', 1)");
Db::run("INSERT INTO campaign_targets (campaign_id, target_id, tracking_id, content_no, send_status, sent_at) VALUES (50, 51, '5000000051', 1, 'sent', '2026-09-01 09:00:00')");
Db::run("INSERT INTO campaign_targets (campaign_id, target_id, tracking_id, content_no, send_status, sent_at) VALUES (50, 52, '5000000052', 1, 'failed', NULL)");
$event = static function (string $tracking, string $type, string $at, string $verdict, string $raw, string $source = 'apache_access', int $tenant = 1, int $campaign = 50): int {
    return Db::insert("INSERT INTO events (tenant_id, campaign_id, tracking_id, event_type, occurred_at, source, raw, verdict, verdict_reason)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)", [$tenant, $campaign, $tracking, $type, $at, $source, $raw, $verdict,
        $verdict === 'scanner' ? 'User-Agent に「Proofpoint」' : null]);
};
$userClick = $event('5000000051', 'click', '2026-09-01 10:00:00', 'user', $apache('198.51.100.7', '/link-5000000051.html', $HUMAN));
$scanClick = $event('5000000052', 'click', '2026-09-01 10:01:00', 'scanner', $apache('203.0.113.9', '/link-5000000052.html', 'Proofpoint-URLDefense'));
$event('5000000051', 'report', '2026-09-01 11:00:00', 'user', '{"message_id":"<x@example.test>"}', 'report_mail');
$event('5000000051', 'reply', '2026-09-01 12:00:00', 'user', '{"message_id":"<y@example.test>"}', 'reply_mail');
$legacy = $event('5000000052', 'click_bot', '2026-09-01 10:02:00', 'user', $apache('203.0.113.10', '/link-5000000052.html', 'Slackbot 1.0'));
// ほかのテナントの訓練と行動
Db::run("INSERT INTO campaigns (id, tenant_id, name, status, created_by) VALUES (60, 2, '他社', 'done', 2)");
Db::run("INSERT INTO campaign_targets (campaign_id, target_id, tracking_id, content_no, send_status) VALUES (60, 3, '6000000003', 1, 'sent')");
$otherEvent = $event('6000000003', 'click', '2026-09-01 10:00:00', 'scanner', $apache('203.0.113.1', '/link-6000000003.html', 'curl/8'), 'apache_access', 2, 60);

$get = static function (string $fn, array $query, string $role = 'viewer'): array {
    $_GET = $query;
    return call_handler($fn, [], $role, []);
};
$post = static function (array $body, string $role = 'operator'): array {
    $_GET = [];
    return call_handler('report_handle_set_verdict', $body, $role, []);
};

// ---- 行動履歴 ----
$r = $get('report_handle_actions', ['campaign_id' => '50']);
$actions = array_column($r['payload']['actions'], null, 'id');
check($r['code'] === 200 && count($actions) === 5, '行動履歴: 装置の行と事故の記録も含めて5件');
check($actions[$scanClick]['verdict'] === 'scanner' && $actions[$scanClick]['verdict_reason'] === 'User-Agent に「Proofpoint」'
    && $actions[$scanClick]['ip'] === '203.0.113.9' && $actions[$scanClick]['device'] === 'Proofpoint', '行動履歴: 装置の行は判定、理由、IP、端末を出す');
check($actions[$userClick]['device'] === 'iPhone / Safari' && $actions[$userClick]['verdict'] === 'user', '行動履歴: 利用者の端末の概要(iPhone / Safari)');
check($actions[$legacy]['event_type'] === 'click' && $actions[$legacy]['verdict'] === 'scanner' && $actions[$legacy]['editable'] === false,
    '行動履歴: click_bot(事故の記録)は装置のクリックとして読むだけ');
check(!array_key_exists('raw', $actions[$userClick]), '行動履歴: 元の行(raw)は返さない');
check($r['payload']['counts'] === ['user' => 3, 'scanner' => 2], '行動履歴: 利用者3件、装置2件の内訳');
check($get('report_handle_actions', ['campaign_id' => '60'])['code'] === 404, 'テナントの分離: ほかのテナントの訓練の行動履歴は 404');
check($get('report_handle_actions', ['campaign_id' => '60', 'tenant_id' => '2'])['code'] === 403, 'テナントの分離: ほかのテナントの指定は 403');

// ---- 利用者ごと ----
$r = $get('report_handle_people', ['campaign_id' => '50']);
$people = array_column($r['payload']['people'], null, 'tracking_id');
$p51 = $people['5000000051'];
$p52 = $people['5000000052'];
check($p51['first_click_at'] === '2026-09-01 10:00:00' && $p51['report_at'] === '2026-09-01 11:00:00' && $p51['reply_at'] === '2026-09-01 12:00:00'
    && $p51['delivery_error'] === false, '利用者ごと: 初回クリック、報告、返信を1行にまとめる');
check($p52['first_click_at'] === null && $p52['scanner_count'] === 2 && $p52['delivery_error'] === true,
    '利用者ごと: 装置のクリックは初回クリックに入れず、装置の件数と配信エラーを出す');
check($get('report_handle_people', ['campaign_id' => '60'])['code'] === 404, 'テナントの分離: ほかのテナントの訓練の利用者ごとは 404');

// ---- 判定の修正 ----
$summary = static fn (): array => $get('report_handle_summary', ['campaign_id' => '50'])['payload']['summary'];
check($summary()['click_count'] === 1, '直す前: クリックは利用者の1人');
check($post(['event_id' => $scanClick, 'verdict' => 'user'], 'viewer')['code'] === 403, '閲覧者は判定を直せない(403)');
$r = $post(['event_id' => $scanClick, 'verdict' => 'user']);
check($r['code'] === 200 && $r['payload']['changed'] === true && $GLOBALS['__TET2_TEST_CSRF_CALLS'] === 1, 'オペレータは判定を直せる(CSRF を確かめる)');
$audit = $GLOBALS['__TET2_TEST_AUDIT'];
check(count($audit) === 1 && $audit[0]['action'] === 'report.event_verdict'
    && str_contains($audit[0]['detail'], '"from":"scanner"') && str_contains($audit[0]['detail'], '"to":"user"'), '判定の修正を監査ログに残す(前と後)');
$row = Db::one('SELECT verdict, verdict_source, verdict_by FROM events WHERE id = ?', [$scanClick]);
check($row['verdict'] === 'user' && $row['verdict_source'] === 'manual' && (int) $row['verdict_by'] === 1, '直した行は manual と直した人を持つ');
check($summary()['click_count'] === 2 && $summary()['failure_count'] === 2, '直した判定は集計に入る(クリック 1 → 2)');
$r = $post(['event_id' => $scanClick, 'verdict' => 'user']);
check($r['code'] === 200 && $r['payload']['changed'] === false && $GLOBALS['__TET2_TEST_AUDIT'] === [], '同じ判定に直しても変えず、監査ログも増やさない');
$back = $post(['event_id' => $scanClick, 'verdict' => 'scanner']);
check($back['code'] === 200 && $summary()['click_count'] === 1, '装置に戻すと集計から外れる');
check($post(['event_id' => $scanClick, 'verdict' => 'bot'])['code'] === 400, '判定は user か scanner だけ(400)');
check($post(['event_id' => 0, 'verdict' => 'user'])['code'] === 400, 'event_id がなければ 400');
check($post(['event_id' => $legacy, 'verdict' => 'user'])['code'] === 409, 'click_bot(事故の記録)は直せない(409)');
check($post(['event_id' => $otherEvent, 'verdict' => 'user'])['code'] === 404
    && Db::one('SELECT verdict FROM events WHERE id = ?', [$otherEvent])['verdict'] === 'scanner', 'テナントの分離: ほかのテナントの行は 404 で、変わらない');
check($post(['event_id' => $otherEvent, 'verdict' => 'user', 'tenant_id' => 2])['code'] === 403, 'テナントの分離: ほかのテナントの指定は 403');
$r = $post(['event_id' => $otherEvent, 'verdict' => 'user', 'tenant_id' => 2], 'superadmin');
check($r['code'] === 200 && $r['payload']['changed'] === true, 'システム管理者はテナントを指定して直せる');
Db::run("UPDATE campaigns SET closed_at = '2026-09-10 00:00:00' WHERE id = 50");
check($post(['event_id' => $userClick, 'verdict' => 'scanner'])['code'] === 409, 'クローズ済みの訓練の判定は直せない(409)');
Db::run('UPDATE campaigns SET closed_at = NULL WHERE id = 50');
Db::run("INSERT INTO campaign_report_snapshots (campaign_id, tenant_id, payload) VALUES (50, 1, '{}')");
$r = $post(['event_id' => $userClick, 'verdict' => 'scanner']);
check($r['code'] === 200 && $r['payload']['is_committed'] === true, '確定済みの訓練は直せるが、確定値は変わらないことを知らせる');

// ---- 端末の概要 ----
check(training_device_summary('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36 Edg/150.0') === 'Windows / Edge',
    '端末: Windows / Edge');
check(training_device_summary(null) === '不明' && training_device_summary('x', 'report_mail') === 'メール', '端末: 読めない時は不明、メールはメール');

echo "ALL TESTS PASSED\n";
