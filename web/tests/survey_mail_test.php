<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

tet2_test_boot();
require_once __DIR__ . '/../lib/SurveyMailer.php';

Db::run("INSERT INTO groups (id, tenant_id, name, kind) VALUES (10, 1, '全職員', 'all')");
$sid = SurveyService::createSurvey(1, 1, ['title' => '送信テスト', 'questions' => [
    ['question_type' => 'single', 'title' => 'q', 'options' => ['a', 'b'], 'is_required' => true],
]]);
$qid = (int) SurveyService::getSurvey(1, $sid)['questions'][0]['id'];
$deadline = date('Y-m-d H:i:s', time() + 86400); // 締切まで1日 = 催促の対象期間内
$d = SurveyService::createDelivery(1, $sid, 1, '送信', $deadline, [10]);
$deliveryId = $d['delivery_id'];

// --- 既定では送らない ---
putenv('TET2_SURVEY_MAIL_ENABLED');
putenv('TET2_EDU_MAIL_DISABLE=1');
check(SurveyMailer::enabled() === false, 'SM-1: 環境変数がなければ送信は無効');
try {
    SurveyMailer::sendInvitations(1, $deliveryId);
    throw new RuntimeException('FAIL: SM-1: 無効なのに送信できた');
} catch (SurveyException $e) {
    check($e->httpCode === 409, 'SM-1: 無効時の案内メールは 409');
}
$cli = shell_exec('TET2_DB_PATH=' . escapeshellarg(getenv('TET2_DB_PATH')) . ' php ' . escapeshellarg(__DIR__ . '/../db/survey_reminder.php') . ' 2>&1');
check(str_contains((string) $cli, '送信は無効です'), 'SM-1: 無効時の催促 CLI は何も送らずに終わる');
check((int) Db::one('SELECT COUNT(*) AS n FROM survey_assignments WHERE invited_at IS NOT NULL OR last_reminded_at IS NOT NULL')['n'] === 0,
    'SM-1: 無効時は送信の記録も残らない');

// --- 有効にしても、投函は TET2_EDU_MAIL_DISABLE=1 で省く ---
putenv('TET2_SURVEY_MAIL_ENABLED=1');
$r = SurveyMailer::sendInvitations(1, $deliveryId);
check($r['targets'] === 2 && $r['sent'] === 2 && $r['failed'] === 0, 'SM-2: 未回答者に案内する');
$again = SurveyMailer::sendInvitations(1, $deliveryId);
check($again['targets'] === 0, 'SM-2: 案内済みの人には二重に案内しない');
try {
    SurveyMailer::sendInvitations(2, $deliveryId);
    throw new RuntimeException('FAIL: SM-3: 他テナントの配信へ送れた');
} catch (SurveyException $e) {
    check($e->httpCode === 404, 'SM-3: 他テナントの配信には送れない');
}

// 1人が回答すると催促の対象から外れる。
$token = (string) Db::one('SELECT access_token FROM survey_assignments WHERE delivery_id = ? AND target_id = 1', [$deliveryId])['access_token'];
SurveyService::submitByToken($token, [$qid => 0]);
$early = SurveyMailer::remind(null, null, time() - 5 * 86400);
check($early['targets'] === 0, 'SM-4: 締切の2日より前は催促しない');
$rem = SurveyMailer::remind();
check($rem['targets'] === 1 && $rem['sent'] === 1, 'SM-4: 締切前に未回答者だけへ催促する');
check(SurveyMailer::remind()['targets'] === 0, 'SM-4: 催促は1人1回だけ');
$late = SurveyMailer::remind(null, null, time() + 2 * 86400);
check($late['targets'] === 0, 'SM-4: 締切を過ぎたら催促しない');

// 終了した配信には送らない。
SurveyService::closeDelivery(1, $deliveryId);
try {
    SurveyMailer::sendInvitations(1, $deliveryId);
    throw new RuntimeException('FAIL: SM-5: 終了した配信へ送れた');
} catch (SurveyException $e) {
    check($e->httpCode === 409, 'SM-5: 終了した配信には案内しない');
}
putenv('TET2_SURVEY_MAIL_ENABLED');

echo "ALL TESTS PASSED\n";
