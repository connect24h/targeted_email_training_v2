<?php
declare(strict_types=1);

/**
 * 訓練後のアンケートの自動配信(段D の D6、G64)。
 * 既定(設定なし・切)では閉じても何もしないこと、閉じた時だけ1回配ること、配り先(防衛に失敗した人か全員)が正しいこと、
 * メールは SurveyMailer の有効化の時だけ送ること、テスト用のキャンペーンと他テナントを扱わないこと、設定の API の権限を確かめる。
 */

require_once __DIR__ . '/helpers.php';

tet2_test_boot();
require_once __DIR__ . '/../lib/CampaignSurveyFollowup.php';
load_api('report');
load_api('campaign_survey_followup');

$mails = [];
EduMailer::useTransport(static function (string $to, string $subject, string $body) use (&$mails): bool {
    $mails[] = ['to' => $to, 'subject' => $subject, 'body' => $body];
    return true;
});
putenv('TET2_SURVEY_MAIL_ENABLED');

$surveyId = SurveyService::createSurvey(1, 1, ['title' => '振り返りのアンケート', 'questions' => [
    ['question_type' => 'single', 'title' => '分かりやすかったですか', 'options' => ['はい', 'いいえ'], 'is_required' => true],
]]);
$otherSurvey = SurveyService::createSurvey(2, 2, ['title' => '他テナント', 'questions' => [
    ['question_type' => 'single', 'title' => 'q', 'options' => ['a', 'b']],
]]);
Db::run("INSERT INTO targets (id, tenant_id, tenant_no, email, name, status) VALUES (6, 1, 6, 'gone@example.test', 'Gone', 'deleted')");

/** 送り終えた訓練を1つ作る。1 はクリック(利用者)、2 は装置のクリックだけ、6 は削除済みでクリック。 */
function make_campaign(int $id, bool $isTest = false): int
{
    Db::run("INSERT INTO campaigns (id, tenant_id, name, status, is_test, created_by) VALUES (?, 1, ?, 'done', ?, 1)", [$id, "訓練{$id}", $isTest ? 1 : 0]);
    foreach ([1, 2, 6] as $t) {
        Db::run("INSERT INTO campaign_targets (campaign_id, target_id, tracking_id, content_no, send_status, sent_at) VALUES (?, ?, ?, 1, 'sent', '2026-10-01 09:00:00')",
            [$id, $t, sprintf('%04d%06d', $id, $t)]);
    }
    Db::run("INSERT INTO events (tenant_id, campaign_id, tracking_id, event_type, occurred_at, source, verdict) VALUES
        (1, ?, ?, 'click', '2026-10-01 10:00:00', 'fixture', 'user'),
        (1, ?, ?, 'click', '2026-10-01 10:00:00', 'fixture', 'scanner'),
        (1, ?, ?, 'auth', '2026-10-01 10:00:00', 'fixture', 'user')",
        [$id, sprintf('%04d%06d', $id, 1), $id, sprintf('%04d%06d', $id, 2), $id, sprintf('%04d%06d', $id, 6)]);
    return $id;
}

function close_campaign(int $id): array
{
    $_GET = ['campaign_id' => (string) $id];
    check(call_handler('report_handle_commit', [], 'operator')['code'] === 200, "訓練{$id} のレポートを確定");
    $r = call_handler('report_handle_close', [], 'superadmin');
    check($r['code'] === 200, "訓練{$id} をクローズ");
    return $r;
}

function survey_deliveries(): int
{
    return (int) Db::one('SELECT COUNT(*) AS n FROM survey_deliveries')['n'];
}

// --- 既定: 設定なしで閉じても何もしない ---
make_campaign(41);
$r = close_campaign(41);
check($r['payload']['survey_followup'] === ['status' => 'off'] && survey_deliveries() === 0 && $mails === []
    && (int) Db::one('SELECT COUNT(*) AS n FROM campaign_survey_followups')['n'] === 0,
    'D6-1: 設定がなければ、閉じても配らず、メールも送らず、表にも書かない');
check(count(array_filter($GLOBALS['__TET2_TEST_AUDIT'], static fn(array $a): bool => $a['action'] === 'campaign.survey_followup_run')) === 0,
    'D6-1: 切の時は自動配信の監査も残さない');

// --- 設定の API: 権限、検証、テナントの分離 ---
make_campaign(42);
$save = static fn(array $body, string $role = 'operator'): array => call_handler('csf_handle_save', $body + ['campaign_id' => 42], $role, []);
check($save(['enabled' => true, 'survey_id' => $surveyId], 'viewer')['code'] === 403, 'D6-2: 閲覧者は設定を変えられない');
check($save(['enabled' => true, 'survey_id' => null])['code'] === 400, 'D6-2: 有効にするにはアンケートを選ぶ');
check($save(['enabled' => true, 'survey_id' => $otherSurvey])['code'] === 404, 'D6-2: 他テナントのアンケートは選べない');
check($save(['enabled' => true, 'survey_id' => $surveyId, 'audience' => 'everyone'])['code'] === 400, 'D6-2: 配り先は failed か all');
check($save(['enabled' => true, 'survey_id' => $surveyId, 'deadline_days' => 0])['code'] === 400, 'D6-2: 締切は1日以上');
check(call_handler('csf_handle_save', ['campaign_id' => 3, 'enabled' => false], 'operator', [])['code'] === 404, 'D6-2: 他テナントのキャンペーンは設定できない');
$r = $save(['enabled' => false, 'survey_id' => $surveyId, 'audience' => 'failed', 'deadline_days' => 7]);
check($r['code'] === 200 && $r['payload']['setting']['enabled'] === false && $GLOBALS['__TET2_TEST_CSRF_CALLS'] === 1
    && ($GLOBALS['__TET2_TEST_AUDIT'][0]['action'] ?? '') === 'campaign.survey_followup', 'D6-2: 保存は CSRF を確かめ、監査に残す');
$_GET = ['campaign_id' => '42'];
$g = call_handler('csf_handle_get', [], 'operator', []);
check($g['code'] === 200 && $g['payload']['setting']['survey_id'] === $surveyId && $g['payload']['mail_enabled'] === false
    && in_array($surveyId, array_map('intval', array_column($g['payload']['surveys'], 'id')), true)
    && !in_array($otherSurvey, array_map('intval', array_column($g['payload']['surveys'], 'id')), true), 'D6-2: 設定と、自組織の選べるアンケートを返す');

// --- 切のまま閉じても配らない ---
close_campaign(42);
check(survey_deliveries() === 0 && $mails === [] && CampaignSurveyFollowup::get(1, 42)['processed_at'] === null, 'D6-3: 切のまま閉じても配らない');
check($save(['enabled' => true, 'survey_id' => $surveyId])['code'] === 409, 'D6-3: 閉じたキャンペーンの設定は変えられない');

// --- 有効(防衛に失敗した人)、メール送信は無効 → 配信だけ作り、メールは送らない ---
make_campaign(43);
check(call_handler('csf_handle_save', ['campaign_id' => 43, 'enabled' => true, 'survey_id' => $surveyId, 'audience' => 'failed', 'deadline_days' => 14], 'operator', [])['code'] === 200,
    '訓練43 を有効にする');
check(CampaignSurveyFollowup::onClosed(1, 43)['status'] === 'not_closed' && survey_deliveries() === 0, 'D6-4: 閉じる前に呼ばれても配らない');
$r = close_campaign(43);
$f = $r['payload']['survey_followup'];
$assigned = array_map('intval', array_column(Db::all('SELECT target_id FROM survey_assignments WHERE delivery_id = ? ORDER BY target_id', [$f['delivery_id'] ?? 0]), 'target_id'));
check($f['status'] === 'delivered' && $f['assigned'] === 1 && $assigned === [1] && $mails === [] && $f['mail'] === 'disabled',
    'D6-4: 閉じた時に、防衛に失敗した有効な人(装置のクリックと削除済みを除く)にだけ配り、メール送信が無効なら送らない');
$delivery = Db::one('SELECT * FROM survey_deliveries WHERE id = ?', [$f['delivery_id']]);
check((int) $delivery['tenant_id'] === 1 && (int) $delivery['survey_id'] === $surveyId && str_starts_with((string) $delivery['deadline'], date('Y-m-d', time() + 14 * 86400)),
    'D6-4: 自組織のアンケートの配信を、締切つきで作る');
$row = CampaignSurveyFollowup::get(1, 43);
check($row['processed_at'] !== null && $row['delivery_id'] === (int) $f['delivery_id'] && str_contains((string) $row['result'], 'CSV'),
    'D6-4: 処理した印と結果を設定に残す');
check(CampaignSurveyFollowup::onClosed(1, 43)['status'] === 'already' && survey_deliveries() === 1, 'D6-4: 2回目は配らない(1回だけ)');

// --- 有効(全員)、メール送信も有効 → 送った全員へ案内メール ---
putenv('TET2_SURVEY_MAIL_ENABLED=1');
make_campaign(44);
CampaignSurveyFollowup::save(1, 44, ['enabled' => true, 'survey_id' => $surveyId, 'audience' => 'all', 'deadline_days' => null], 'x');
$r = close_campaign(44);
$f = $r['payload']['survey_followup'];
check($f['status'] === 'delivered' && $f['assigned'] === 2 && $f['mail_sent'] === 2
    && array_column($mails, 'to') === ['target1@example.test', 'target2@example.test'], 'D6-5: 全員を選ぶと、訓練のメールを送った有効な人全員へ配り、案内メールを送る');
check(!str_contains($mails[0]['body'], '訓練') && str_contains($mails[0]['subject'], '振り返りのアンケート'), 'D6-5: 案内メールはアンケートの案内の文面で送る');
$audit = array_values(array_filter($GLOBALS['__TET2_TEST_AUDIT'], static fn(array $a): bool => $a['action'] === 'campaign.survey_followup_run'));
check(count($audit) === 1 && str_contains($audit[0]['detail'], 'status=delivered'), 'D6-5: 自動配信の結果を監査に残す');

// --- テスト用のキャンペーンでは配らない ---
$before = count($mails);
make_campaign(45, true);
CampaignSurveyFollowup::save(1, 45, ['enabled' => true, 'survey_id' => $surveyId, 'audience' => 'all'], 'x');
$r = close_campaign(45);
check($r['payload']['survey_followup']['status'] === 'skipped' && count($mails) === $before && survey_deliveries() === 2,
    'D6-6: テスト用のキャンペーンでは配らない(本物の対象者へ届かない)');

// --- 防衛に失敗した人がいなければ配らない ---
make_campaign(46);
Db::run("DELETE FROM events WHERE campaign_id = 46 AND verdict = 'user'");
CampaignSurveyFollowup::save(1, 46, ['enabled' => true, 'survey_id' => $surveyId, 'audience' => 'failed'], 'x');
$r = close_campaign(46);
check($r['payload']['survey_followup']['status'] === 'skipped' && survey_deliveries() === 2 && count($mails) === $before,
    'D6-7: 防衛に失敗した人がいなければ配らない');
putenv('TET2_SURVEY_MAIL_ENABLED');

echo "ALL TESTS PASSED\n";
