<?php
declare(strict_types=1);

/**
 * 訓練→教育の自動連携(EduAutoEnroll)の回帰テスト。
 *
 * 2026-08-16 の調査で、この経路は3段のバグで到達不能だった:
 *   1. api/edu_deliveries.php が triggered_by を 'manual' 固定にしていた
 *   2. 設問抽出が共有設問(tenant_id IS NULL)を拾わず、共有100問の環境で出題0件になった
 *   3. 割当を作るだけで受講案内メールを送らず、受講者にURLが届かなかった
 * どれも「動いているように見えて0件」なので、テストがないと必ず再発する。
 */

require_once __DIR__ . '/helpers.php';

tet2_test_boot();
require_once __DIR__ . '/../lib/EduQuestionPicker.php';
require_once __DIR__ . '/../lib/EduMailer.php';
require_once __DIR__ . '/../lib/EduAutoEnroll.php';

// テスト中は SMTP へ出さない(localhost:25 依存でテストが不安定になるのを防ぐ)。
putenv('TET2_EDU_MAIL_DISABLE=1');

/** 共有設問(tenant_id IS NULL)を作る。本番の設問100件は全てこの形。 */
function enrollSharedQuestion(int $categoryId, int $difficulty = 1): int
{
    return Db::insert(
        'INSERT INTO edu_questions
         (tenant_id, category_id, title, question_type, options, correct_answer, difficulty, is_active, is_shared)
         VALUES (NULL, ?, ?, ?, ?, ?, ?, 1, 1)',
        [$categoryId, '共有設問' . $difficulty, 'single_choice', '["A","B"]', '[0]', $difficulty]
    );
}

function enrollTenantQuestion(int $tenantId, int $categoryId, int $difficulty = 1): int
{
    return Db::insert(
        'INSERT INTO edu_questions
         (tenant_id, category_id, title, question_type, options, correct_answer, difficulty, is_active, is_shared)
         VALUES (?, ?, ?, ?, ?, ?, ?, 1, 0)',
        [$tenantId, $categoryId, 'テナント設問', 'single_choice', '["A","B"]', '[0]', $difficulty]
    );
}

function enrollDelivery(array $overrides = []): int
{
    $data = array_merge([
        'status' => 'running',
        'triggered_by' => 'phishing_failure',
        'phish_campaign_id' => null,
        'question_count' => 3,
        'created_at' => '2026-01-01 00:00:00',
        'send_invites' => 1,
    ], $overrides);
    return Db::insert(
        'INSERT INTO edu_deliveries
         (tenant_id, title, status, delivery_type, question_count, randomize,
          target_type, triggered_by, phish_campaign_id, created_by, created_at, send_invites)
         VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?)',
        [1, '訓練フォローアップ', $data['status'], 'awareness_quiz', $data['question_count'],
         'risk', $data['triggered_by'], $data['phish_campaign_id'], 1, $data['created_at'], $data['send_invites']]
    );
}

function enrollTarget(string $email, array $overrides = []): int
{
    $data = array_merge(['status' => 'active', 'is_test' => 0], $overrides);
    return Db::insert(
        'INSERT INTO targets (tenant_id, email, name, status, is_test) VALUES (?, ?, ?, ?, ?)',
        [1, $email, '受講者', $data['status'], $data['is_test']]
    );
}

function enrollCampaign(): int
{
    return Db::insert(
        'INSERT INTO campaigns (tenant_id, name, status, created_by) VALUES (?, ?, ?, ?)',
        [1, '訓練キャンペーン', 'done', 1]
    );
}

/** 対象者に訓練失敗(click)イベントを作る。occurred_at で鮮度を制御する。 */
function enrollFailure(int $campaignId, int $targetId, string $trackingId, string $occurredAt): void
{
    Db::run(
        'INSERT INTO campaign_targets (campaign_id, target_id, tracking_id, koban) VALUES (?, ?, ?, ?)',
        [$campaignId, $targetId, $trackingId, $targetId]
    );
    Db::run(
        'INSERT INTO events (tenant_id, campaign_id, tracking_id, event_type, occurred_at, source)
         VALUES (?, ?, ?, ?, ?, ?)',
        [1, $campaignId, $trackingId, 'click', $occurredAt, 'apache_access']
    );
}

$categoryId = Db::insert(
    'INSERT INTO edu_categories (tenant_id, name, slug, sort_order, is_active, is_shared)
     VALUES (NULL, ?, ?, 1, 1, 1)',
    ['フィッシング', 'phishing']
);

// 本番と同じ構成: 設問は全て共有(tenant_id IS NULL)
enrollSharedQuestion($categoryId, 1);
enrollSharedQuestion($categoryId, 2);
enrollSharedQuestion($categoryId, 3);

$campaignId = enrollCampaign();

// --- 対象者を4種類用意して、除外規則を検証する ---
$normalId   = enrollTarget('normal@example.test');
$testUserId = enrollTarget('tester@example.test', ['is_test' => 1]);
$archivedId = enrollTarget('archived@example.test', ['status' => 'archived']);
$staleId    = enrollTarget('stale@example.test');

$deliveryId = enrollDelivery(['created_at' => '2026-06-01 00:00:00']);

enrollFailure($campaignId, $normalId,   '1000000001', '2026-07-01 10:00:00'); // 配信作成後 → 対象
enrollFailure($campaignId, $testUserId, '1000000002', '2026-07-01 10:00:00'); // is_test → 除外
enrollFailure($campaignId, $archivedId, '1000000003', '2026-07-01 10:00:00'); // 退職者 → 除外
enrollFailure($campaignId, $staleId,    '1000000004', '2026-01-15 10:00:00'); // 配信作成前 → 除外

$result = EduAutoEnroll::run();

check($result['deliveries'] === 1, 'phishing_failure かつ running の配信を拾う');
check($result['assigned'] === 1, '除外規則の適用後、対象は1名だけになる');

$assignedIds = [];
foreach (Db::all('SELECT target_id FROM edu_assignments WHERE delivery_id = ?', [$deliveryId]) as $r) {
    $assignedIds[] = (int) $r['target_id'];
}
check($assignedIds === [$normalId], '通常の失敗者だけが割り当てられる');
check(!in_array($testUserId, $assignedIds, true), 'is_test=1 の検証用ユーザへは配信しない');
check(!in_array($archivedId, $assignedIds, true), 'archived の退職者へは配信しない');
check(!in_array($staleId, $assignedIds, true), '配信作成日時より前の失敗は対象にしない');

// --- 今回のバグ本体。共有設問しかない環境で出題が確定すること ---
$questionCount = (int) Db::one(
    'SELECT COUNT(*) AS c FROM edu_delivery_questions WHERE delivery_id = ?',
    [$deliveryId]
)['c'];
check($questionCount > 0, '共有設問(tenant_id IS NULL)のみでも出題が確定する');
check($questionCount === 3, 'question_count の指定どおり3問が積まれる');

// --- 受講案内が送られたことの記録 ---
$assignment = Db::one(
    'SELECT access_token, last_reminded_at FROM edu_assignments WHERE delivery_id = ? AND target_id = ?',
    [$deliveryId, $normalId]
);
check(preg_match('/^[0-9a-f]{32}$/', (string) $assignment['access_token']) === 1, '32桁hexのトークンを発行する');
check($assignment['last_reminded_at'] !== null, '受講案内の送信時刻を記録する(直後の催促メール二重送信を防ぐ)');

// --- 冪等性: 2回流しても増えない ---
$second = EduAutoEnroll::run();
check($second['assigned'] === 0, '2回目の実行では新規割当が発生しない');
$total = (int) Db::one('SELECT COUNT(*) AS c FROM edu_assignments WHERE delivery_id = ?', [$deliveryId])['c'];
check($total === 1, '再実行しても割当は重複しない');

// --- triggered_by が manual の配信は拾わない ---
$manualDeliveryId = enrollDelivery(['triggered_by' => 'manual']);
$manualResult = EduAutoEnroll::run();
check($manualResult['deliveries'] === 1, 'manual トリガーの配信は自動連携の対象外');

// --- draft の配信は拾わない ---
$draftDeliveryId = enrollDelivery(['status' => 'draft']);
$draftResult = EduAutoEnroll::run();
check($draftResult['deliveries'] === 1, 'draft の配信は running になるまで対象外');

// --- 案内メールは send_invites=1 の配信だけ送る(既定は送らない) ---
$mailCalls = 0;
EduMailer::useTransport(static function () use (&$mailCalls): bool {
    $mailCalls++;
    return true;
});
// 先の配信(全キャンペーンの失敗者が対象)は終えておき、この配信だけを動かす
Db::run("UPDATE edu_deliveries SET status = 'done' WHERE id = ?", [$deliveryId]);
$quietCampaignId = enrollCampaign();
$quietDeliveryId = enrollDelivery(['send_invites' => 0, 'phish_campaign_id' => $quietCampaignId]);
$quietTargetId = enrollTarget('quiet@example.test');
enrollFailure($quietCampaignId, $quietTargetId, '1000000009', '2026-07-02 10:00:00');
EduAutoEnroll::run();
$quiet = Db::one('SELECT last_reminded_at FROM edu_assignments WHERE delivery_id = ? AND target_id = ?', [$quietDeliveryId, $quietTargetId]);
check($quiet !== null, 'send_invites=0 の配信にも失敗者を割り当てる');
check($mailCalls === 0 && $quiet['last_reminded_at'] === null, 'send_invites=0 の配信では EduMailer を呼ばない');
EduMailer::useTransport(null);

// --- 訓練の結果の区分(risk_results)を持つ自動の配信は、手動の開始と同じ区分で対象を選ぶ(段0 の G48) ---
Db::run("UPDATE edu_deliveries SET status = 'done' WHERE id = ?", [$quietDeliveryId]);
$riskCampaignId = enrollCampaign();
$riskDeliveryId = enrollDelivery(['send_invites' => 0, 'phish_campaign_id' => $riskCampaignId]);
Db::run("UPDATE edu_deliveries SET risk_results = '[\"reported\"]' WHERE id = ?", [$riskDeliveryId]);
$clickerId = enrollTarget('clicker@example.test');
$reporterId = enrollTarget('reporter@example.test');
enrollFailure($riskCampaignId, $clickerId, '1000000021', '2026-07-03 10:00:00');
Db::run("INSERT INTO campaign_targets (campaign_id, target_id, tracking_id, koban, send_status) VALUES (?, ?, '1000000022', ?, 'sent')",
    [$riskCampaignId, $reporterId, $reporterId]);
Db::run("INSERT INTO events (tenant_id, campaign_id, tracking_id, event_type, occurred_at, source)
         VALUES (1, ?, '1000000022', 'report', '2026-07-03 11:00:00', 'report_mail')", [$riskCampaignId]);
EduAutoEnroll::run();
$riskAssigned = array_map(static fn(array $r): int => (int) $r['target_id'],
    Db::all('SELECT target_id FROM edu_assignments WHERE delivery_id = ? ORDER BY target_id', [$riskDeliveryId]));
check($riskAssigned === [$reporterId], 'risk_results=報告した の自動の配信は、報告した人だけを割り当てる(クリックした人は入れない)');
Db::run("UPDATE edu_deliveries SET status = 'done' WHERE id = ?", [$riskDeliveryId]);

// --- EduQuestionPicker: テナント固有設問を共有より優先する ---
$tenantQuestionId = enrollTenantQuestion(1, $categoryId, 1);
$pickerDelivery = [
    'id' => 0,
    'category_ids' => null,
    'difficulty_range' => null,
    'question_count' => 1,
    'randomize' => 0,
];
$picked = EduQuestionPicker::pick($pickerDelivery, 1);
check($picked === [$tenantQuestionId], 'テナント固有設問を共有設問より優先して出題する');

$pickerDelivery['question_count'] = 10;
$pickedAll = EduQuestionPicker::pick($pickerDelivery, 1);
check(count($pickedAll) === 4, '共有設問とテナント設問の両方が候補になる');

echo "ALL TESTS PASSED\n";
