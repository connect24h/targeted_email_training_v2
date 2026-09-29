<?php
declare(strict_types=1);

/**
 * 訓練の一覧の報告率と防衛失敗率、概要の配信エラーの人数(A-7、G56)のテスト。
 * - 防衛失敗 = click か auth(followup.php の防衛失敗者と同じ)。tracking_id ごとに1回。分母は対象数
 * - 報告率 = report の tracking_id 数 ÷ 対象数
 * - 配信エラー = send_status が failed/deferred、または未送信で delivery_log に failed/deferred がある宛先
 * - テストの対象者(targets.is_test=1)は分子にも分母にも入れない。ほかのテナントの数は混ざらない
 */

require_once __DIR__ . '/helpers.php';
tet2_test_boot();
load_api('report');

function rates_call(string $fn, array $get, string $role = 'viewer'): array
{
    $_GET = $get;
    return call_handler($fn, [], $role, []);
}

// 対象者: 1..2 は fixture。ここで 4 人(うち1人はテスト)を足す
foreach ([[11, 'r1'], [12, 'r2'], [13, 'r3'], [14, 'r4']] as [$id, $tag]) {
    Db::run("INSERT INTO targets (id, tenant_id, tenant_no, email, name, status, is_test) VALUES (?, 1, ?, ?, ?, 'active', ?)",
        [$id, $id, "{$tag}@example.test", $tag, $id === 14 ? 1 : 0]);
}
Db::run("INSERT INTO campaigns (id, tenant_id, name, status, created_by) VALUES (20, 1, '率の確認', 'done', 1)");
// 対象4人(本番3人 + テスト1人)と、fixture の対象者1(本番)で本番は4人
$ct = [[1, '2000000001', 'sent'], [11, '2000000011', 'sent'], [12, '2000000012', 'pending'], [13, '2000000013', 'failed'], [14, '2000000014', 'sent']];
foreach ($ct as [$target, $tracking, $status]) {
    Db::run("INSERT INTO campaign_targets (campaign_id, target_id, tracking_id, content_no, send_status) VALUES (20, ?, ?, 1, ?)",
        [$target, $tracking, $status]);
}
$ev = static fn(string $tracking, string $type, string $at) => Db::run(
    "INSERT INTO events (tenant_id, campaign_id, tracking_id, event_type, occurred_at, source) VALUES (1, 20, ?, ?, ?, 'fixture')",
    [$tracking, $type, $at]);
$ev('2000000001', 'click', '2026-09-01 10:00:00');
$ev('2000000001', 'auth', '2026-09-01 10:01:00');   // click と auth の両方 → 失敗は1人
$ev('2000000001', 'click', '2026-09-01 10:05:00');  // 2回目のクリックも1人
$ev('2000000011', 'auth', '2026-09-01 11:00:00');   // auth だけでも失敗
$ev('2000000012', 'report', '2026-09-01 12:00:00'); // 報告
$ev('2000000012', 'open', '2026-09-01 12:30:00');   // open(サイト表示のビーコン)だけでは失敗にしない
$ev('2000000014', 'click', '2026-09-01 13:00:00');  // テストの対象者は数えない
$ev('2000000014', 'report', '2026-09-01 13:01:00');
// 配信エラー: 13 は failed、12 は未送信で delivery_log に failed。11 は送信済みなので delivery_log の failed があっても数えない
Db::run("INSERT INTO delivery_log (campaign_id, tracking_id, to_email, result) VALUES (20, '2000000012', 'r2@example.test', 'failed')");
Db::run("INSERT INTO delivery_log (campaign_id, tracking_id, to_email, result) VALUES (20, '2000000011', 'r1@example.test', 'failed')");
Db::run("INSERT INTO delivery_log (campaign_id, tracking_id, to_email, result) VALUES (20, '2000000014', 'r4@example.test', 'failed')");

// ほかのテナント(2)の同じ形の訓練。テナント1の数に混ざらないこと
Db::run("INSERT INTO campaigns (id, tenant_id, name, status, created_by) VALUES (30, 2, '他社の訓練', 'done', 2)");
Db::run("INSERT INTO campaign_targets (campaign_id, target_id, tracking_id, content_no, send_status) VALUES (30, 3, '3000000003', 1, 'failed')");
Db::run("INSERT INTO events (tenant_id, campaign_id, tracking_id, event_type, occurred_at, source) VALUES (2, 30, '3000000003', 'click', '2026-09-01 10:00:00', 'fixture')");

// 一覧(report.php?action=campaigns)
$r = rates_call('report_handle_campaigns', []);
$rows = array_column($r['payload']['campaigns'], null, 'id');
$c = $rows[20];
check($r['code'] === 200 && $c['target_count'] === 4, '一覧: 対象数はテストの対象者を除いて4');
check($c['failure_count'] === 2 && $c['failure_rate'] === 50.0, '一覧: 防衛失敗は click か auth の2人(50.0%)');
check($c['report_count'] === 1 && $c['report_rate'] === 25.0, '一覧: 報告は1人(25.0%)。テストの対象者の報告は数えない');
check($c['delivery_error_count'] === 2, '一覧: 配信エラーは failed の1人と、未送信で delivery_log が失敗の1人');
check(!isset($rows[30]), 'テナントの分離: ほかのテナントの訓練は一覧に出ない');

// 概要(report.php?action=summary)
$r = rates_call('report_handle_summary', ['campaign_id' => '20']);
$s = $r['payload']['summary'];
check($r['code'] === 200 && $s['failure_count'] === 2 && $s['report_count'] === 1 && $s['delivery_error_count'] === 2
    && $s['failure_rate'] === 50.0 && $s['report_rate'] === 25.0, '概要: 一覧と同じ数(防衛失敗2、報告1、配信エラー2)');
check(rates_call('report_handle_summary', ['campaign_id' => '30'])['code'] === 404, 'テナントの分離: ほかのテナントの訓練の概要は 404');
check(rates_call('report_handle_summary', ['campaign_id' => '30', 'tenant_id' => '2'])['code'] === 403, 'テナントの分離: ほかのテナントを指定すると 403');

// 防衛失敗の定義は followup.php の防衛失敗者と同じ(テストの対象者の扱いを除く)
$followup = Db::all("SELECT DISTINCT ct.tracking_id FROM events e JOIN campaign_targets ct ON ct.tracking_id = e.tracking_id AND ct.campaign_id = e.campaign_id
    JOIN targets t ON t.id = ct.target_id WHERE e.campaign_id = 20 AND e.tenant_id = 1 AND e.event_type IN ('click','auth') AND t.is_test = 0");
check(count($followup) === $c['failure_count'], '防衛失敗の数は防衛失敗者の一覧(click か auth)の人数と一致する');

// クローズ済み: 報告率などを持たない旧版の確定値は、その項目だけ今の集計で補い、確定の値は変えない
$old = ['campaign_summary' => ['target_count' => 4, 'sent_count' => 2, 'sent_rate' => 50.0, 'open_count' => 0, 'open_rate' => 0.0,
    'click_count' => 9, 'click_rate' => 99.0, 'auth_count' => 2, 'auth_rate' => 22.2]];
Db::run("INSERT INTO campaign_report_snapshots (campaign_id, tenant_id, payload) VALUES (20, 1, ?)", [json_encode($old)]);
Db::run("UPDATE campaigns SET closed_at = '2026-09-10 00:00:00' WHERE id = 20");
$r = rates_call('report_handle_campaigns', []);
$c = array_column($r['payload']['campaigns'], null, 'id')[20];
check($c['click_count'] === 9 && (float) $c['click_rate'] === 99.0, 'クローズ済み: 確定の値はそのまま');
check($c['failure_count'] === 2 && $c['report_rate'] === 25.0 && $c['delivery_error_count'] === 2, 'クローズ済み: 旧版にない項目は今の集計で補う');

// 空の訓練(対象0)は率 0 で、0 除算にならない
Db::run("INSERT INTO campaigns (id, tenant_id, name, status, created_by) VALUES (21, 1, '空', 'draft', 1)");
$c = array_column(rates_call('report_handle_campaigns', [])['payload']['campaigns'], null, 'id')[21];
check($c['target_count'] === 0 && $c['failure_rate'] === 0.0 && $c['report_rate'] === 0.0 && $c['delivery_error_count'] === 0, '対象0の訓練は率 0');

echo "ALL TESTS PASSED\n";
