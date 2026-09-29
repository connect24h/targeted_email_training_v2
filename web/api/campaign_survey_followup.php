<?php declare(strict_types=1); require __DIR__."/../lib/bootstrap.php";
require_once __DIR__ . '/../lib/CampaignSurveyFollowup.php';

/**
 * 訓練後のアンケートの自動配信の設定(段D の D6、G64)。既定は切(設定の行がない)。オペレータ以上。
 *
 *   GET  ?action=get&campaign_id=  今の設定、選べるアンケート、アンケートのメール送信が有効か、閉じた時の結果
 *   POST ?action=save              {campaign_id, enabled, survey_id, audience(failed|all), deadline_days} を保存(CSRF)
 *
 * 配るのは訓練を閉じた時(api/report.php の close)だけで、この API はメールを送らない。
 */

function csf_campaign_id(mixed $value): int
{
    $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false || $id === null) {
        json_error('campaign_id が不正です', 400);
    }
    return (int) $id;
}

function csf_query_tenant(): ?int
{
    return isset($_GET['tenant_id']) && $_GET['tenant_id'] !== '' ? (int) $_GET['tenant_id'] : null;
}

function csf_handle_get(): never
{
    $actor = require_role('operator');
    $tenantId = effective_tenant_id($actor, csf_query_tenant());
    $campaignId = csf_campaign_id($_GET['campaign_id'] ?? null);
    $campaign = assert_campaign_owned($campaignId, $tenantId);
    $surveys = Db::all(
        "SELECT s.id, s.title, s.is_anonymous FROM surveys s
         WHERE s.tenant_id = ? AND s.status <> 'closed' AND EXISTS (SELECT 1 FROM survey_questions q WHERE q.survey_id = s.id)
         ORDER BY s.id DESC",
        [$tenantId]
    );
    json_out(['success' => true, 'setting' => CampaignSurveyFollowup::get($tenantId, $campaignId), 'surveys' => $surveys,
        'mail_enabled' => SurveyMailer::enabled(), 'closed' => $campaign['closed_at'] !== null, 'is_test' => (int) $campaign['is_test'] === 1,
        'max_deadline_days' => CampaignSurveyFollowup::MAX_DEADLINE_DAYS]);
}

function csf_handle_save(): never
{
    $actor = require_role('operator');
    tet2_require_csrf();
    $body = json_body();
    $tenantId = effective_tenant_id($actor, csf_query_tenant());
    $campaignId = csf_campaign_id($body['campaign_id'] ?? null);
    assert_campaign_owned($campaignId, $tenantId);
    try {
        $setting = CampaignSurveyFollowup::save($tenantId, $campaignId, $body, (string) ($actor['email'] ?? ''));
    } catch (CampaignSurveyFollowupException $e) {
        json_error($e->getMessage(), $e->httpCode);
    }
    audit('campaign.survey_followup', 'campaign_id=' . $campaignId . ',enabled=' . ($setting['enabled'] ? 1 : 0)
        . ',survey_id=' . ($setting['survey_id'] ?? '') . ',audience=' . $setting['audience']);
    json_out(['success' => true, 'setting' => $setting]);
}

try {
    $action = $_GET['action'] ?? '';
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($action === 'get' && $method === 'GET') {
        csf_handle_get();
    }
    if ($action === 'save' && $method === 'POST') {
        csf_handle_save();
    }
    json_error('不正なアクションです', 400);
} catch (Throwable $e) {
    error_log($e->getMessage());
    json_error('サーバエラー', 500);
}
