<?php
declare(strict_types=1);

/**
 * T1 テナントの停止を効かせる: ログイン、ログイン中のセッション、自動の処理、送信の操作。
 * 合成 DB(tenant 1 = Example、tenant 2 = Other)と .test のアドレスだけを使う。メールは送らない(送信口を差し替えて数える)。
 */

ini_set('session.use_cookies', '0');
ini_set('session.use_only_cookies', '0');
ini_set('session.use_trans_sid', '0');
ini_set('session.save_path', sys_get_temp_dir());
session_start();
ob_start();

require __DIR__ . '/helpers.php';

$dbPath = tet2_test_boot();
putenv('TET2_EDU_MAIL_DISABLE=1');
require_once __DIR__ . '/../lib/EduMailer.php';
require_once __DIR__ . '/../lib/EduScheduler.php';
require_once __DIR__ . '/../lib/EduAutoEnroll.php';
require_once __DIR__ . '/../lib/EduReminder.php';
require_once __DIR__ . '/../lib/SurveyMailer.php';
require_once __DIR__ . '/../lib/CampaignAutomationRunner.php';
require_once __DIR__ . '/../lib/CampaignLaunchService.php';
require_once __DIR__ . '/../lib/CampaignPreflight.php';
load_api('auth');

$mails = [];
EduMailer::useTransport(static function (string $to, string $subject, string $body) use (&$mails): bool {
    $mails[] = $to;
    return true;
});

function setTenantStatus(int $id, string $status): void
{
    Db::run('UPDATE tenants SET status = ? WHERE id = ?', [$status, $id]);
}

// ---------- TenantStatus の判定 ----------
check(TenantStatus::isOperational(1) === true, 'TS-1: 有効なテナントは動く');
setTenantStatus(2, 'suspended');
check(TenantStatus::isOperational(2) === false, 'TS-1: 停止中のテナントは動かない');
check(TenantStatus::userAllowed(2, 'operator') === false, 'TS-1: 停止中のテナントのユーザは使えない');
check(TenantStatus::userAllowed(2, 'superadmin') === true, 'TS-1: superadmin は所属テナントが停止中でも使える');
check(TenantStatus::userAllowed(null, 'superadmin') === true, 'TS-1: tenant_id のない superadmin は使える');
check(TenantStatus::userAllowed(null, 'operator') === false, 'TS-1: tenant_id のない superadmin 以外は使えない');
check(str_contains((string) TenantStatus::sendBlockReason(2), '停止中'), 'TS-1: 停止中のテナントの送信を止める理由');
setTenantStatus(2, 'deleted');
check(str_contains((string) TenantStatus::sendBlockReason(2), '削除済み'), 'TS-1: 削除済みのテナントの送信を止める理由');
check(TenantStatus::sendBlockReason(1) === null, 'TS-1: 有効なテナントの送信は止めない');
check(TenantStatus::operationalSql('d.tenant_id') === "d.tenant_id IN (SELECT id FROM tenants WHERE status = 'active')",
    'TS-1: SQL の条件を作る');
$rejected = false;
try {
    TenantStatus::operationalSql('1=1; DROP TABLE x');
} catch (InvalidArgumentException) {
    $rejected = true;
}
check($rejected, 'TS-1: 列名以外は SQL に埋めない');
setTenantStatus(2, 'active');

// ---------- ログイン ----------
$pw = 'TenantPass123!';
$hash = password_hash($pw, PASSWORD_DEFAULT);
Db::run("INSERT INTO users (id, tenant_id, email, password_hash, name, role, status) VALUES
    (8001, 2, 'suspended-tenant@other.example.test', ?, 'Other User', 'operator', 'active'),
    (8002, 2, 'super-in-other@example.test', ?, 'Super', 'superadmin', 'active'),
    (8003, NULL, 'super-null@example.test', ?, 'Super Null', 'superadmin', 'active'),
    (8004, 1, 'active-tenant@example.test', ?, 'Active', 'tenant_admin', 'active')", [$hash, $hash, $hash, $hash]);

setTenantStatus(2, 'suspended');
$r = call_handler('auth_handle_login', ['email' => 'suspended-tenant@other.example.test', 'password' => $pw]);
check($r['code'] === 403, 'T1-L1: 停止中のテナントのユーザのログインは 403');
check(str_contains($r['payload']['error'], '利用が停止されている'), 'T1-L1: 利用者に分かる日本語の理由を返す');
$lastLogin = Db::one('SELECT last_login_at FROM users WHERE id = 8001');
check($lastLogin['last_login_at'] === null, 'T1-L1: 拒否したログインは最後のログイン日時を更新しない');
check(in_array('login.tenant_inactive', array_column($GLOBALS['__TET2_TEST_AUDIT'], 'action'), true), 'T1-L1: 拒否を監査ログに残す');

$r = call_handler('auth_handle_login', ['email' => 'suspended-tenant@other.example.test', 'password' => 'wrong-password']);
check($r['code'] === 401, 'T1-L2: パスワードが違えばテナントの状態は明かさず 401');

setTenantStatus(2, 'deleted');
$r = call_handler('auth_handle_login', ['email' => 'suspended-tenant@other.example.test', 'password' => $pw]);
check($r['code'] === 403, 'T1-L3: 削除済みのテナントのユーザのログインも 403');

$r = call_handler('auth_handle_login', ['email' => 'super-in-other@example.test', 'password' => $pw]);
check($r['code'] === 200, 'T1-L4: 所属テナントが削除済みでも superadmin はログインできる');
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
$r = call_handler('auth_handle_login', ['email' => 'super-null@example.test', 'password' => $pw]);
check($r['code'] === 200, 'T1-L5: tenant_id のない superadmin はログインできる');
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
$r = call_handler('auth_handle_login', ['email' => 'active-tenant@example.test', 'password' => $pw]);
check($r['code'] === 200, 'T1-L6: 有効なテナントのユーザはログインできる');
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

// ---------- ログイン中のセッション(本物の bootstrap を別プロセスで読む) ----------
function probe(string $dbPath, string $mode, int $uid, ?int $tenant, string $role): string
{
    $cmd = 'TET2_DB_PATH=' . escapeshellarg($dbPath) . ' php ' . escapeshellarg(__DIR__ . '/fixtures/session_probe.php')
        . ' ' . escapeshellarg($mode) . ' ' . $uid . ' ' . escapeshellarg($tenant === null ? '-' : (string) $tenant)
        . ' ' . escapeshellarg($role) . ' 2>&1';
    return (string) shell_exec($cmd);
}

setTenantStatus(2, 'suspended');
$out = probe($dbPath, 'auth', 8001, 2, 'operator');
check(str_contains($out, 'ログアウトしました') && !str_contains($out, '"probe":"ok"'),
    'T1-S1: 停止中のテナントのユーザのセッションは次の操作で拒否される');
check(str_contains($out, 'session_uid=none'), 'T1-S1: セッションの中身を消す');
$out = probe($dbPath, 'me', 8001, 2, 'operator');
check(str_contains($out, '"user":null') && str_contains($out, 'ログアウトしました'), 'T1-S2: me はユーザなしと理由を返す');
$out = probe($dbPath, 'auth', 8004, 1, 'tenant_admin');
check(str_contains($out, '"probe":"ok"') && str_contains($out, 'session_uid=8004'), 'T1-S3: 有効なテナントのセッションはそのまま');
$out = probe($dbPath, 'auth', 8002, 2, 'superadmin');
check(str_contains($out, '"probe":"ok"'), 'T1-S4: superadmin は所属テナントが停止中でもセッションが続く');
$out = probe($dbPath, 'auth', 8003, null, 'superadmin');
check(str_contains($out, '"probe":"ok"'), 'T1-S5: tenant_id のない superadmin のセッションは続く');
setTenantStatus(2, 'active');
$out = probe($dbPath, 'auth', 8001, 2, 'operator');
check(str_contains($out, '"probe":"ok"'), 'T1-S6: テナントを有効に戻すと使える');

// ---------- 定期キャンペーン(CampaignAutomationRunner) ----------
$now = new DateTimeImmutable('2026-10-01 09:00:00', new DateTimeZone('Asia/Tokyo'));
Db::run("INSERT INTO campaigns (id, tenant_id, name, status, created_by) VALUES (50, 2, 'Other Source', 'done', 2)");
Db::run("INSERT INTO campaign_automations
    (tenant_id, name, source_campaign_id, frequency, day_of_month, generation_lead_days, time_mode, send_window_start,
     next_due_at, status, created_by)
    VALUES (2, 'Other Rule', 50, 'monthly', 15, 5, 'fixed', '09:30', '2026-09-30 09:00:00', 'active', 2)");
$ruleId = (int) Db::one('SELECT MAX(id) AS id FROM campaign_automations')['id'];
setTenantStatus(2, 'suspended');
$runner = new CampaignAutomationRunner();
check($runner->dueCount($now) === 0, 'T1-A1: 停止中のテナントの定期キャンペーンは予定の件数に入れない');
$result = $runner->runDue($now);
check($result['examined'] === 0, 'T1-A1: 停止中のテナントの定期キャンペーンは処理しない');
check((int) Db::one('SELECT COUNT(*) AS c FROM campaign_automation_runs WHERE automation_id = ?', [$ruleId])['c'] === 0,
    'T1-A1: 実行の記録も作らない');
check($runner->generateOne($ruleId, ['now' => $now])['status'] === 'skipped', 'T1-A1: 1件ずつの生成も停止中のテナントでは飛ばす');
check((string) Db::one('SELECT next_due_at FROM campaign_automations WHERE id = ?', [$ruleId])['next_due_at'] === '2026-09-30 09:00:00',
    'T1-A1: 予定の日時を進めない');
setTenantStatus(2, 'deleted');
check($runner->runDue($now)['examined'] === 0, 'T1-A2: 削除済みのテナントの定期キャンペーンも処理しない');
setTenantStatus(2, 'active');
check($runner->dueCount($now) === 1, 'T1-A3: 有効に戻すと予定の件数に入る');
Db::run("UPDATE campaign_automations SET status = 'paused' WHERE id = ?", [$ruleId]);

// ---------- 教育の配信(EduScheduler: launchDue、series、new_target) ----------
$categoryId = Db::insert("INSERT INTO edu_categories (tenant_id, name, slug, is_active, is_shared) VALUES (NULL, '小問', 'ts-cat', 1, 1)");
for ($i = 1; $i <= 3; $i++) {
    Db::insert(
        "INSERT INTO edu_questions (tenant_id, category_id, title, options, correct_answer, difficulty, is_active, is_shared)
         VALUES (NULL, ?, ?, '[\"A\",\"B\"]', '[0]', 1, 1, 1)",
        [$categoryId, '設問' . $i]
    );
}
$scheduled = Db::insert(
    "INSERT INTO edu_deliveries (tenant_id, title, status, delivery_type, question_count, randomize, scheduled_at,
      target_type, triggered_by, send_invites, created_by)
     VALUES (2, '予約', 'scheduled', 'awareness_quiz', 2, 0, '2026-10-01 08:00:00', 'all', 'manual', 1, 2)"
);
$newTarget = Db::insert(
    "INSERT INTO edu_deliveries (tenant_id, title, status, delivery_type, question_count, randomize,
      target_type, triggered_by, new_target_days, send_invites, created_by)
     VALUES (2, '新入社員', 'running', 'awareness_quiz', 2, 0, 'new_target', 'new_target', 30, 1, 2)"
);
Db::run("UPDATE targets SET created_at = '2026-09-25 09:00:00' WHERE tenant_id = 2");
$seriesId = Db::insert(
    "INSERT INTO edu_delivery_series (tenant_id, title, settings, day_of_month, time_of_day, next_run_at, created_by)
     VALUES (2, '月例', ?, 1, '09:00', '2026-10-01 09:00:00', 2)",
    [json_encode(['delivery_type' => 'awareness_quiz', 'question_count' => 2, 'target_type' => 'all', 'triggered_by' => 'manual'])]
);
setTenantStatus(2, 'suspended');
$mails = [];
$result = EduScheduler::run($now);
check((string) Db::one('SELECT status FROM edu_deliveries WHERE id = ?', [$scheduled])['status'] === 'scheduled',
    'T1-E1: 停止中のテナントの予約の配信は開始しない');
check($result['launched'] === 0 && $result['failed'] === 0, 'T1-E1: 失敗としても数えない');
check($result['series_created'] === 0, 'T1-E2: 停止中のテナントの毎月の系列は回を作らない');
check((string) Db::one('SELECT next_run_at FROM edu_delivery_series WHERE id = ?', [$seriesId])['next_run_at'] === '2026-10-01 09:00:00',
    'T1-E2: 系列の次の回の日時を進めない');
check($result['new_target_assigned'] === 0 && (int) Db::one('SELECT COUNT(*) AS c FROM edu_assignments WHERE delivery_id = ?', [$newTarget])['c'] === 0,
    'T1-E3: 停止中のテナントの新入社員の配信に入れない');
check($mails === [], 'T1-E: 停止中のテナントへメールを送らない');

$caught = null;
try {
    EduDeliveryLauncher::launch(Db::one('SELECT * FROM edu_deliveries WHERE id = ?', [$scheduled]), 2, $now);
} catch (EduDeliveryError $e) {
    $caught = $e;
}
check($caught !== null && $caught->getCode() === 409 && str_contains($caught->getMessage(), '停止中'),
    'T1-E4: 画面からの教育の配信の開始も停止中のテナントでは 409');

setTenantStatus(2, 'active');
$result = EduScheduler::run($now);
check($result['launched'] >= 1, 'T1-E5: 有効に戻すと予約の配信を開始する');
check($result['series_created'] === 1, 'T1-E5: 有効に戻すと系列の回を作る');

// ---------- 訓練の結果からの自動投入(EduAutoEnroll)と教育のリマインダ(EduReminder) ----------
$trigger = Db::insert(
    "INSERT INTO edu_deliveries (tenant_id, title, status, delivery_type, question_count, randomize,
      target_type, triggered_by, created_by)
     VALUES (2, '自動投入', 'running', 'awareness_quiz', 2, 0, 'all', 'phishing_failure', 2)"
);
Db::run("INSERT INTO campaign_targets (campaign_id, target_id, tracking_id, content_no, send_status) VALUES (50, 3, '0000000950', 1, 'sent')");
Db::run("INSERT INTO events (tenant_id, campaign_id, tracking_id, event_type, occurred_at, source)
    VALUES (2, 50, '0000000950', 'click', datetime('now','localtime','+1 minutes'), 'fixture')");
setTenantStatus(2, 'suspended');
$enroll = EduAutoEnroll::run();
$triggerIds = array_column($enroll['details'], 'delivery_id');
check(!in_array($trigger, $triggerIds, true) && (int) Db::one('SELECT COUNT(*) AS c FROM edu_assignments WHERE delivery_id = ?', [$trigger])['c'] === 0,
    'T1-E6: 停止中のテナントの訓練の結果からの自動投入をしない');

$pending = (int) Db::one("SELECT COUNT(*) AS c FROM edu_assignments a JOIN edu_deliveries d ON d.id = a.delivery_id
    WHERE d.tenant_id = 2 AND d.status = 'running' AND a.status IN ('assigned','started')")['c'];
check($pending > 0, 'T1-E7: 前提 停止中のテナントに未受講の割当がある');
$mails = [];
$remind = EduReminder::run();
check($remind['targets'] === 0 && $mails === [], 'T1-E7: 停止中のテナントの受講者にはリマインダを送らない');

load_api('edu_deliveries');
$r = call_handler('edu_d_handle_remind', ['id' => $newTarget, 'tenant_id' => 2], 'superadmin');
check($r['code'] === 409 && str_contains($r['payload']['error'], '停止中'), 'T1-E8: 画面からの催促も superadmin でも 409');
setTenantStatus(2, 'active');
$remind = EduReminder::run();
check($remind['targets'] > 0, 'T1-E9: 有効に戻すとリマインダの対象に入る');

// ---------- アンケート(SurveyMailer) ----------
putenv('TET2_SURVEY_MAIL_ENABLED=1');
Db::run("INSERT INTO groups (id, tenant_id, name, kind) VALUES (20, 2, '全職員', 'all')");
$sid = SurveyService::createSurvey(2, 2, ['title' => '停止のテスト', 'questions' => [
    ['question_type' => 'single', 'title' => 'q', 'options' => ['a', 'b'], 'is_required' => true],
]]);
$sd = SurveyService::createDelivery(2, $sid, 2, '停止', date('Y-m-d H:i:s', time() + 86400), [20]);
setTenantStatus(2, 'suspended');
$caught = null;
try {
    SurveyMailer::sendInvitations(2, $sd['delivery_id']);
} catch (SurveyException $e) {
    $caught = $e;
}
check($caught !== null && $caught->httpCode === 409, 'T1-U1: 停止中のテナントのアンケートの案内は 409');
Db::run("UPDATE survey_assignments SET invited_at = datetime('now','localtime') WHERE tenant_id = 2");
$mails = [];
$sr = SurveyMailer::remind(null, null);
check($sr['targets'] === 0 && $mails === [], 'T1-U2: CLI の催促は停止中のテナントを飛ばす');
$caught = null;
try {
    SurveyMailer::remind(2, $sd['delivery_id']);
} catch (SurveyException $e) {
    $caught = $e;
}
check($caught !== null && $caught->httpCode === 409, 'T1-U3: 画面からの催促も 409');
setTenantStatus(2, 'active');
check(SurveyMailer::remind(null, null)['targets'] > 0, 'T1-U4: 有効に戻すと催促の対象に入る');
putenv('TET2_SURVEY_MAIL_ENABLED');

// ---------- キャンペーンの開始(CampaignLaunchService、CampaignPreflight) ----------
setTenantStatus(2, 'suspended');
$launch = CampaignLaunchService::launch(3, 2, str_repeat('a', 64));
check($launch['ok'] === false && $launch['status'] === 409 && str_contains($launch['error'], '停止中'),
    'T1-C1: 停止中のテナントのキャンペーンの開始は 409 と理由');
$review = CampaignPreflight::inspect(3, 2);
check($review['can_launch'] === false && in_array(TenantStatus::sendBlockReason(2), $review['blockers'], true),
    'T1-C2: 配信前確認に停止中の理由が出る');
setTenantStatus(2, 'deleted');
$launch = CampaignLaunchService::launch(3, 2, str_repeat('a', 64));
check($launch['status'] === 409 && str_contains($launch['error'], '削除済み'), 'T1-C3: 削除済みのテナントの開始も 409');
setTenantStatus(2, 'active');
$review = CampaignPreflight::inspect(3, 2);
check(!in_array('停止中のテナントでは送信の操作はできません。テナントを有効にしてから操作してください', $review['blockers'], true),
    'T1-C4: 有効なテナントでは停止の理由を出さない');

echo "ALL TESTS PASSED\n";
ob_end_flush();
