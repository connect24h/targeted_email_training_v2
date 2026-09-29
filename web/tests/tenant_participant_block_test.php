<?php
declare(strict_types=1);

// 停止中・削除済みのテナントでは、受講者の受講(edu_take)と回答者のアンケート(survey_take)を受け付けない。
require_once __DIR__ . '/helpers.php';

tet2_test_boot();
require_once __DIR__ . '/../lib/SurveyService.php';
require_once __DIR__ . '/../lib/TenantStatus.php';
load_api('edu_take');
load_api('survey_take');

$tenantId = (int) current_user()['tenant_id'];
$targetId = (int) Db::one('SELECT id FROM targets WHERE tenant_id = ? ORDER BY id LIMIT 1', [$tenantId])['id'];

$deliveryId = Db::insert(
    "INSERT INTO edu_deliveries (tenant_id, title, status, delivery_type, pass_score, target_type)
     VALUES (?, '停止の確認', 'running', 'awareness_quiz', 80, 'individual')",
    [$tenantId]
);
$eduToken = str_repeat('b', 32);
Db::run(
    "INSERT INTO edu_assignments (tenant_id, delivery_id, target_id, access_token, status) VALUES (?, ?, ?, ?, 'assigned')",
    [$tenantId, $deliveryId, $targetId, $eduToken]
);
$sid = SurveyService::createSurvey($tenantId, 1, ['title' => '停止の確認', 'questions' => [
    ['question_type' => 'single', 'title' => '設問', 'options' => ['はい', 'いいえ'], 'is_required' => true],
]]);
$d = SurveyService::createDelivery($tenantId, $sid, 1, '配信', date('Y-m-d H:i:s', time() + 3600), [$targetId]);
$surveyToken = (string) Db::one('SELECT access_token FROM survey_assignments WHERE delivery_id = ?', [$d['delivery_id']])['access_token'];

function take_resolve_code(string $token): array
{
    try {
        take_resolve($token);
        return [200, null];
    } catch (Tet2TestExit $e) {
        return [$e->httpCode, $e->payload['error'] ?? null];
    }
}

[$code] = take_resolve_code($eduToken);
check($code === 200, 'TP-1: 有効なテナントの受講リンクは開ける');
[$code] = stake_start($surveyToken);
check($code === 200, 'TP-1: 有効なテナントのアンケートは開ける');

// 終了・中止した配信の受講リンクは、期限後の受講を認める設定でも開けない(開始済みの配信だけ受け付ける)
Db::run("UPDATE edu_deliveries SET status = 'cancelled' WHERE id = ?", [$deliveryId]);
[$code] = take_resolve_code($eduToken);
check($code === 410, 'TP-1b: 中止した配信の受講リンクは 410');
Db::run("UPDATE edu_deliveries SET status = 'done', allow_after_deadline = 1 WHERE id = ?", [$deliveryId]);
[$code] = take_resolve_code($eduToken);
check($code === 410, 'TP-1b: 期限後の受講を認める配信でも、終了した配信は 410');
Db::run("UPDATE edu_deliveries SET status = 'running', allow_after_deadline = 0 WHERE id = ?", [$deliveryId]);

foreach (['suspended', 'deleted'] as $status) {
    Db::run('UPDATE tenants SET status = ? WHERE id = ?', [$status, $tenantId]);
    [$code, $message] = take_resolve_code($eduToken);
    check($code === 403 && $message === TenantStatus::PARTICIPANT_BLOCKED_MESSAGE, "TP-2: {$status} のテナントの受講は 403 と決まった文言");
    [$code, $data] = stake_start($surveyToken);
    check($code === 403 && $data['error'] === TenantStatus::PARTICIPANT_BLOCKED_MESSAGE, "TP-3: {$status} のテナントのアンケートは 403");
    [$code] = stake_submit(['token' => $surveyToken, 'answers' => []]);
    check($code === 403, "TP-3: {$status} のテナントのアンケートの回答も 403");
}
check(!preg_match('/訓練|停止|削除|テナント/u', TenantStatus::PARTICIPANT_BLOCKED_MESSAGE),
    'TP-4: 受講者向けの文言に訓練や運用の事情を含めない');

Db::run("UPDATE tenants SET status = 'active' WHERE id = ?", [$tenantId]);
[$code] = take_resolve_code($eduToken);
check($code === 200, 'TP-5: 有効に戻すと、また受講できる');

echo "tenant_participant_block_test: OK\n";
