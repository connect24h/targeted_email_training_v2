<?php
declare(strict_types=1);

/**
 * 教育配信の対象の指定: 役職区分(F3)と、訓練の結果の区分(F4)の回帰テスト。
 */

require_once __DIR__ . '/helpers.php';

tet2_test_boot();
require_once __DIR__ . '/../lib/EduDeliveryLauncher.php';
load_api('edu_deliveries');

$tenantId = 1;

function tgTarget(string $email, ?string $position, array $overrides = []): int
{
    $d = array_merge(['status' => 'active', 'is_test' => 0], $overrides);
    return Db::insert(
        'INSERT INTO targets (tenant_id, email, name, position_category, status, is_test) VALUES (1, ?, ?, ?, ?, ?)',
        [$email, $email, $position, $d['status'], $d['is_test']]
    );
}

function tgResolve(array $response): array
{
    $delivery = Db::one('SELECT * FROM edu_deliveries WHERE id = ?', [(int) $response['payload']['delivery']['id']]);
    return edu_d_resolve_targets($delivery, 1);
}

// ---- F3: 役職区分で対象を指定する ----
Db::run("UPDATE targets SET position_category = '管理職' WHERE tenant_id = 1");
$exec    = tgTarget('exec@example.test', '役員');
$execT   = tgTarget('exec-test@example.test', '役員', ['is_test' => 1]);
$execOld = tgTarget('exec-old@example.test', '役員', ['status' => 'archived']);
$staff   = tgTarget('staff@example.test', '一般従業員');

$position = ['title' => '役員向け', 'delivery_type' => 'awareness_quiz', 'target_type' => 'position'];
$r = call_handler('edu_d_handle_create', $position + ['target_positions' => ['役員']], 'operator');
check($r['code'] === 201, 'F3-1: 対象の種類 position で配信を作れる');
check(json_decode((string) $r['payload']['delivery']['target_positions'], true) === ['役員'], 'F3-1: 役職区分の一覧を JSON で保存する');
check(tgResolve($r) === [$exec], 'F3-2: 対象は active かつ is_test=0 の、その役職区分の対象者だけ');
$r = call_handler('edu_d_handle_create', $position + ['target_positions' => ['役員', '一般従業員']], 'operator');
check(tgResolve($r) === [$exec, $staff], 'F3-3: 複数の役職区分を選べる');
foreach ([null, [], ['部長'], '役員', ['役員', '役員']] as $bad) {
    $body = $position;
    if ($bad !== null) {
        $body['target_positions'] = $bad;
    }
    $r = call_handler('edu_d_handle_create', $body, 'operator');
    check($r['code'] === 400, 'F3-4: 不正な役職区分の指定を拒否する ' . json_encode($bad, JSON_UNESCAPED_UNICODE));
}
$r = call_handler('edu_d_handle_create', [
    'title' => '全員', 'delivery_type' => 'awareness_quiz', 'target_type' => 'all', 'target_positions' => ['役員'],
], 'operator');
check($r['code'] === 201 && $r['payload']['delivery']['target_positions'] === null, 'F3-5: position 以外では役職区分を保存しない');
$r = call_handler('edu_d_handle_series_create', $position + ['target_positions' => ['管理職'], 'day_of_month' => 1, 'time_of_day' => '09:00'], 'operator');
check($r['code'] === 201 && json_decode((string) $r['payload']['series']['settings'], true)['target_positions'] === '["管理職"]',
    'F3-6: 毎月の配信でも役職区分を指定できる');

// ---- F4: 訓練の結果の区分で対象を指定する ----
function tgCampaign(string $status): int
{
    return Db::insert("INSERT INTO campaigns (tenant_id, name, status, created_by) VALUES (1, '訓練', ?, 1)", [$status]);
}
function tgSent(int $campaignId, int $targetId, string $trackingId, array $events, string $sendStatus = 'sent'): void
{
    Db::run(
        'INSERT INTO campaign_targets (campaign_id, target_id, tracking_id, koban, send_status) VALUES (?, ?, ?, ?, ?)',
        [$campaignId, $targetId, $trackingId, $targetId, $sendStatus]
    );
    foreach ($events as $i => $type) {
        Db::run(
            "INSERT INTO events (tenant_id, campaign_id, tracking_id, event_type, occurred_at, source)
             VALUES (1, ?, ?, ?, ?, 'apache_access')",
            [$campaignId, $trackingId, $type, '2026-09-0' . ($i + 1) . ' 10:00:00']
        );
    }
}

$done = tgCampaign('done');
$clicked   = tgTarget('clicked@example.test', null);
$submitted = tgTarget('submitted@example.test', null);
$reporter  = tgTarget('reporter@example.test', null);
$ignored   = tgTarget('ignored@example.test', null);
$beacon    = tgTarget('beacon@example.test', null);
$pending   = tgTarget('pending@example.test', null);
$tester    = tgTarget('tester@example.test', null, ['is_test' => 1]);
tgSent($done, $clicked, '5000000001', ['click']);
tgSent($done, $submitted, '5000000002', ['click', 'auth']);
tgSent($done, $reporter, '5000000003', ['report']);
tgSent($done, $ignored, '5000000004', []);
tgSent($done, $beacon, '5000000005', ['open']);
tgSent($done, $pending, '5000000006', [], 'pending');
tgSent($done, $tester, '5000000007', ['click']);

$risk = ['title' => '訓練の後', 'delivery_type' => 'awareness_quiz', 'target_type' => 'risk'];
$cases = [
    [['opened'], [$clicked, $submitted, $beacon], '開いた(リンクのクリックか偽サイトのビーコン)'],
    [['submitted'], [$submitted], '入力した(auth)'],
    [['reported'], [$reporter], '報告した(report)'],
    [['not_opened'], [$reporter, $ignored], '開かなかった(送信済みで、開いた・入力したの記録がない)'],
    [['reported', 'not_opened'], [$reporter, $ignored], '複数の区分は和集合'],
];
foreach ($cases as [$results, $expected, $label]) {
    $r = call_handler('edu_d_handle_create', $risk + ['phish_campaign_id' => $done, 'risk_results' => $results], 'operator');
    check($r['code'] === 201, 'F4-1: 訓練の結果の区分で作れる ' . implode(',', $results));
    check(tgResolve($r) === $expected, 'F4-2: ' . $label);
}
$r = call_handler('edu_d_handle_create', $risk + ['phish_campaign_id' => $done, 'risk_results' => ['opened']], 'operator');
check(json_decode((string) $r['payload']['delivery']['risk_results'], true) === ['opened'], 'F4-3: 結果の区分を JSON で保存する');

$r = call_handler('edu_d_handle_update', ['id' => (int) $r['payload']['delivery']['id'], 'triggered_by' => 'phishing_failure'], 'operator');
check($r['code'] === 400, 'F4-3: 結果の区分を持つ配信を、編集で自動の投入に切り替えられない');

$r = call_handler('edu_d_handle_create', $risk + ['risk_results' => ['opened']], 'operator');
check($r['code'] === 400, 'F4-4: 結果の区分を選ぶときはキャンペーンの指定が必須');
foreach (['running', 'scheduled', 'paused', 'draft'] as $status) {
    $r = call_handler('edu_d_handle_create', $risk + ['phish_campaign_id' => tgCampaign($status), 'risk_results' => ['not_opened']], 'operator');
    check($r['code'] === 409, 'F4-5: 終わっていないキャンペーン(' . $status . ')では作成を拒否する');
}
foreach ([[], ['clicked'], 'opened'] as $bad) {
    $r = call_handler('edu_d_handle_create', $risk + ['phish_campaign_id' => $done, 'risk_results' => $bad], 'operator');
    check($r['code'] === 400, 'F4-6: 不正な結果の区分を拒否する ' . json_encode($bad));
}
$r = call_handler('edu_d_handle_create', $risk + ['phish_campaign_id' => $done, 'risk_results' => ['opened'], 'triggered_by' => 'phishing_failure'], 'operator');
check($r['code'] === 400, 'F4-7: 自動の投入(phishing_failure)とは組み合わせない');
$r = call_handler('edu_d_handle_create', [
    'title' => '全員', 'delivery_type' => 'awareness_quiz', 'target_type' => 'all', 'risk_results' => ['opened'],
], 'operator');
check($r['code'] === 400, 'F4-8: risk 以外では結果の区分を指定できない');
$cancelled = tgCampaign('cancelled');
$r = call_handler('edu_d_handle_create', $risk + ['phish_campaign_id' => $cancelled, 'risk_results' => ['opened']], 'operator');
check($r['code'] === 201, 'F4-9: 中止したキャンペーンは終わったものとして扱う');

// 区分を選ばない risk は、これまでどおり失敗者(auth か click)が対象
$r = call_handler('edu_d_handle_create', $risk + ['phish_campaign_id' => $done], 'operator');
check($r['code'] === 201 && tgResolve($r) === [$clicked, $submitted], 'F4-10: 区分なしの risk は従来どおり失敗者が対象');

echo "ALL TESTS PASSED\n";
