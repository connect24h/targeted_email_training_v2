<?php
declare(strict_types=1);

/**
 * 受講期間の終了時の集計通知(段D の D5、G63)。
 * 既定(設定なし)で1通も送らないこと、有効にした組織の担当者へ配信1件につき1回だけ送ること、本文は人数と率だけであること、
 * 数字が教育レポートの配信一覧と一致すること(テスト用と削除済みを除く)、テナントの分離、設定の API の権限を確かめる。
 */

require_once __DIR__ . '/helpers.php';

tet2_test_boot();
require_once __DIR__ . '/../lib/EduDeliverySummary.php';
require_once __DIR__ . '/../lib/EduAnswerReport.php';
require_once __DIR__ . '/../lib/EduAutoEnrollRuns.php';
load_api('edu_report');
load_api('edu_summary');

$mails = [];
EduMailer::useTransport(static function (string $to, string $subject, string $body) use (&$mails): bool {
    $mails[] = ['to' => $to, 'subject' => $subject, 'body' => $body];
    return true;
});

// 対象者: 1, 2 は本物。4 はテスト用、5 は削除済み(数えない)。tenant 2 の 3。
Db::run("INSERT INTO targets (id, tenant_id, tenant_no, email, name, status, is_test) VALUES
    (4, 1, 4, 'test-user@example.test', 'Test User', 'active', 1),
    (5, 1, 5, 'gone@example.test', 'Gone User', 'deleted', 0)");
Db::run("INSERT INTO edu_deliveries (id, tenant_id, title, status, pass_score, deadline) VALUES
    (31, 1, '10月の教育', 'running', 80, '2026-10-20 17:00:00'),
    (32, 1, '期限前の教育', 'running', 80, '2026-12-31 17:00:00'),
    (33, 1, '期限のない教育', 'running', 80, NULL),
    (34, 2, '他テナントの教育', 'running', 80, '2026-10-20 17:00:00'),
    (35, 1, '下書きの教育', 'draft', 80, '2026-10-20 17:00:00'),
    (36, 1, '9月の教育', 'done', 80, '2026-09-20')");
$assign = static function (int $id, int $delivery, int $tenant, int $target, string $status, ?string $completedAt, ?int $pct): void {
    Db::run('INSERT INTO edu_assignments (id, tenant_id, delivery_id, target_id, access_token, status, completed_at) VALUES (?,?,?,?,?,?,?)',
        [$id, $tenant, $delivery, $target, 'tok' . $id, $status, $completedAt]);
    if ($pct !== null) {
        Db::run('INSERT INTO edu_responses (tenant_id, assignment_id, percentage) VALUES (?,?,?)', [$tenant, $id, $pct]);
    }
};
$assign(301, 31, 1, 1, 'completed', '2026-10-19 10:00:00', 90);  // 期限内に合格
$assign(302, 31, 1, 2, 'completed', '2026-10-21 10:00:00', 85);  // 期限後に合格
$assign(303, 31, 1, 4, 'completed', '2026-10-19 10:00:00', 100); // テスト用(数えない)
$assign(304, 31, 1, 5, 'assigned', null, null);                   // 削除済み(数えない)
$assign(305, 34, 2, 3, 'completed', '2026-10-19 10:00:00', 90);

$now = new DateTimeImmutable('2026-10-21 12:00:00', new DateTimeZone('Asia/Tokyo'));

// --- 既定(設定の行なし)では1通も送らない ---
$r = EduDeliverySummary::run($now);
check($r === ['tenants' => 0, 'deliveries' => 0, 'sent' => 0, 'failed' => 0] && $mails === []
    && (int) Db::one('SELECT COUNT(*) AS n FROM edu_delivery_summaries')['n'] === 0, 'D5-1: 既定(設定なし)では1通も送らず、記録も作らない');
$cli = shell_exec('TET2_DB_PATH=' . escapeshellarg((string) getenv('TET2_DB_PATH')) . ' TET2_EDU_MAIL_DISABLE=1 php '
    . escapeshellarg(__DIR__ . '/../db/edu_delivery_summary.php') . ' 2>&1');
check(trim((string) $cli) === 'edu_delivery_summary: tenants=0 deliveries=0 sent=0 failed=0', 'D5-1: CLI も既定では何も送らずに1行で終わる');

// --- 切のまま担当者だけ登録しても送らない ---
EduDeliverySummary::saveSetting(1, false, ['edu-owner@example.test'], 'admin@example.test');
check(EduDeliverySummary::run($now)['sent'] === 0 && $mails === [], 'D5-2: 切のまま担当者を登録しても送らない');

// --- 有効にする(有効にした日時より前に期限を過ぎた配信は送らない) ---
Db::run("UPDATE edu_summary_settings SET enabled = 1, enabled_at = '2026-10-01 00:00:00' WHERE tenant_id = 1");
Db::run("UPDATE edu_summary_settings SET recipients = ? WHERE tenant_id = 1", [json_encode(['edu-owner@example.test', 'second@example.test'])]);
$r = EduDeliverySummary::run($now);
check($r['tenants'] === 1 && $r['deliveries'] === 1 && $r['sent'] === 2 && count($mails) === 2,
    'D5-3: 期限を過ぎた配信1件の集計を、登録した担当者2人へ送る');
check(array_column($mails, 'to') === ['edu-owner@example.test', 'second@example.test'], 'D5-3: 送り先は登録した担当者だけ');
$body = $mails[0]['body'];
check($mails[0]['subject'] === '【受講の集計】10月の教育' && str_contains($body, '対象: 2人')
    && str_contains($body, '受講を終えた人: 2人（受講率 100.0%）') && str_contains($body, '合格: 2人（合格率 100.0%）')
    && str_contains($body, '期限内の合格: 1人（期限内合格率 50.0%）'), 'D5-3: 人数と率を送る(テスト用と削除済みを除く)');
check(!str_contains($body, 'target1@') && !str_contains($body, 'Target One') && !str_contains($body, 'Test User'),
    'D5-3: 本文に受講者の名前やアドレスを入れない');
check(Db::one('SELECT status, sent FROM edu_delivery_summaries WHERE delivery_id = 31') === ['status' => 'sent', 'sent' => 2],
    'D5-3: 台帳に送った記録を残す');
foreach (['32' => '期限前', '33' => '期限なし', '34' => '他テナント', '35' => '下書き', '36' => '有効にする前に期限切れ'] as $id => $why) {
    check(Db::one('SELECT 1 FROM edu_delivery_summaries WHERE delivery_id = ?', [(int) $id]) === null, "D5-3: {$why}の配信は送らない");
}

// --- 数字は教育レポートの配信一覧と同じ ---
$rep = call_handler('edu_rep_handle_deliveries', [], 'viewer');
$row = array_column($rep['payload']['deliveries'], null, 'id')[31];
$counts = EduDeliverySummary::counts(1, 31);
check($counts['assigned'] === $row['assigned'] && $counts['completed'] === $row['completed']
    && $counts['completion_rate'] === $row['completion_rate'] && $counts['passed'] === $row['passed']
    && $counts['pass_rate'] === $row['pass_rate'] && $counts['on_time_passed'] === $row['on_time_passed']
    && $counts['on_time_pass_rate'] === $row['on_time_pass_rate'], 'D5-4: 人数と率は教育レポートの配信一覧と一致する');

// --- 冪等: 何度動かしても二重に送らない ---
check(EduDeliverySummary::run($now)['deliveries'] === 0 && count($mails) === 2, 'D5-5: 2回目の実行では送らない(配信1件につき1回)');
Db::run("INSERT INTO edu_delivery_summaries (tenant_id, delivery_id, status) VALUES (1, 32, 'sending')");
check(EduDeliverySummary::run(new DateTimeImmutable('2027-01-02 00:00:00'))['deliveries'] === 0 && count($mails) === 2,
    'D5-5: 別の実行が先に台帳の行を作った配信は送らない');

// --- 合格点のない配信は合格の行を消す ---
Db::run("INSERT INTO edu_deliveries (id, tenant_id, title, status, delivery_type, pass_score, deadline) VALUES (37, 1, 'アウェアネス', 'running', 'awareness_quiz', NULL, '2026-10-21 09:00')");
$assign(306, 37, 1, 1, 'completed', '2026-10-20 10:00:00', 50);
EduDeliverySummary::run($now);
check(count($mails) === 4 && !str_contains($mails[2]['body'], '合格') && str_contains($mails[2]['body'], '受講率 100.0%'),
    'D5-6: 合格点のない配信は、合格と期限内合格の行を出さない');

// --- 停止中のテナントには送らない。テナントの分離 ---
EduDeliverySummary::saveSetting(2, true, ['owner@other.example.test'], 'admin@other.example.test');
Db::run("UPDATE edu_summary_settings SET enabled_at = '2026-10-01 00:00:00' WHERE tenant_id = 2");
Db::run("UPDATE tenants SET status = 'suspended' WHERE id = 2");
EduDeliverySummary::run($now);
check(count($mails) === 4, 'D5-7: 停止中のテナントには送らない');
Db::run("UPDATE tenants SET status = 'active' WHERE id = 2");
EduDeliverySummary::run($now);
check(count($mails) === 5 && $mails[4]['to'] === 'owner@other.example.test' && str_contains($mails[4]['body'], '対象: 1人')
    && str_contains($mails[4]['subject'], '他テナントの教育'), 'D5-7: 他テナントの配信は、そのテナントの担当者へだけ送る');

// --- 設定の API ---
$r = call_handler('es_handle_setting', [], 'viewer', []);
check($r['code'] === 403, 'D5-8: 閲覧者は設定を見られない');
$r = call_handler('es_handle_setting', [], 'operator', []);
check($r['code'] === 200 && $r['payload']['setting']['enabled'] === true && $r['payload']['can_edit'] === false
    && count($r['payload']['history']) >= 2, 'D5-8: オペレータは設定と送信の記録を見られる(変えられない)');
check(call_handler('es_handle_save', ['enabled' => false, 'recipients' => []], 'operator', [])['code'] === 403, 'D5-8: オペレータは設定を変えられない');
$r = call_handler('es_handle_save', ['enabled' => true, 'recipients' => []], 'tenant_admin', []);
check($r['code'] === 400, 'D5-8: 送り先なしでは有効にできない');
$r = call_handler('es_handle_save', ['enabled' => true, 'recipients' => ['bad-address']], 'tenant_admin', []);
check($r['code'] === 400, 'D5-8: 不正なアドレスは保存しない');
$r = call_handler('es_handle_save', ['enabled' => true, 'recipients' => array_map(static fn(int $i): string => "u{$i}@example.test", range(1, 11))], 'tenant_admin', []);
check($r['code'] === 400, 'D5-8: 送り先は10件まで');
$r = call_handler('es_handle_save', ['enabled' => false, 'recipients' => ['A@example.test', 'a@example.test', ' ']], 'tenant_admin', []);
check($r['code'] === 200 && $r['payload']['setting'] === EduDeliverySummary::setting(1) && $r['payload']['setting']['recipients'] === ['a@example.test']
    && $r['payload']['setting']['enabled_at'] === null && $GLOBALS['__TET2_TEST_CSRF_CALLS'] === 1
    && ($GLOBALS['__TET2_TEST_AUDIT'][0]['action'] ?? '') === 'edu_delivery.summary_setting', 'D5-8: 組織管理者は保存でき、重複と空欄を除き、監査に残す');
$r = call_handler('es_handle_save', ['enabled' => true, 'recipients' => ['a@example.test']], 'tenant_admin', []);
check($r['payload']['setting']['enabled_at'] !== null && $r['payload']['setting']['enabled_at'] >= date('Y-m-d'), 'D5-8: 有効にした日時を今にする');
// 有効にした日時(今)より前に期限を過ぎた配信
Db::run("INSERT INTO edu_deliveries (id, tenant_id, title, status, pass_score, deadline) VALUES (38, 1, '昔の教育', 'done', 80, ?)",
    [date('Y-m-d H:i:s', time() - 3600)]);
$before = count($mails);
EduDeliverySummary::run($now);
check(count($mails) === $before, 'D5-8: 有効にし直すと、その前に期限を過ぎた配信の分は送らない');

echo "ALL TESTS PASSED\n";
