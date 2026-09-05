<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

tet2_test_boot();
load_api('report');

function riskTarget(int $tenantId, string $email, string $status = 'active', int $isTest = 0, string $company = 'A社'): int
{
    return Db::insert(
        'INSERT INTO targets (tenant_id, email, name, company, status, is_test) VALUES (?, ?, ?, ?, ?, ?)',
        [$tenantId, $email, $email, $company, $status, $isTest]
    );
}

function riskScore(int $tenantId, int $targetId, string $date, float $score, string $band): void
{
    Db::run(
        'INSERT INTO human_risk_scores
         (tenant_id, target_id, score, band, phish_component, edu_component, report_credit, detail, computed_date)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$tenantId, $targetId, $score, $band, 12.5, 3.0, 2.0,
         json_encode(['open' => 1, 'click' => 2, 'auth' => 0, 'report' => 1, 'clean_campaigns' => 2,
                      'edu_assigned' => 3, 'edu_completed' => 2, 'edu_overdue' => 1], JSON_UNESCAPED_UNICODE), $date]
    );
}

$tenantId = (int) current_user()['tenant_id'];
$otherTenant = (int) Db::one('SELECT id FROM tenants WHERE id != ? ORDER BY id LIMIT 1', [$tenantId])['id'];
$old = riskTarget($tenantId, 'old@example.test');
$high = riskTarget($tenantId, 'high@example.test', 'active', 0, 'A社');
$medium = riskTarget($tenantId, 'medium@example.test', 'active', 0, 'B社');
$low = riskTarget($tenantId, 'low@example.test', 'active', 0, 'A社');
$inactive = riskTarget($tenantId, 'inactive@example.test', 'archived');
$test = riskTarget($tenantId, 'test@example.test', 'active', 1);
$other = riskTarget($otherTenant, 'other@example.test');

riskScore($tenantId, $old, '2026-08-30', 99.0, 'high');
riskScore($tenantId, $high, '2026-09-01', 85.0, 'high');
riskScore($tenantId, $medium, '2026-09-01', 50.0, 'medium');
riskScore($tenantId, $low, '2026-09-01', 20.0, 'low');
riskScore($tenantId, $inactive, '2026-09-01', 95.0, 'high');
riskScore($tenantId, $test, '2026-09-01', 90.0, 'high');
riskScore($otherTenant, $other, '2026-09-03', 100.0, 'high');
// 壊れた関連行があっても target の tenant と一致しなければ表示しない。
riskScore($tenantId, $other, '2026-09-01', 88.0, 'high');

$_GET = ['limit' => '2'];
$result = call_handler('report_handle_risk_individuals', [], 'viewer');
$payload = $result['payload'];
check($result['code'] === 200, 'viewer がリスク一覧を参照できる');
check($payload['computed_date'] === '2026-09-01', '現在テナントの最新計算日だけを使う');
check($payload['bands'] === ['high' => 1, 'medium' => 1, 'low' => 1], 'active の実対象者だけを帯別集計する');
check($payload['total'] === 3 && $payload['limit'] === 2, 'limit とフィルタ対象総数を明示する');
check(array_column($payload['individuals'], 'email') === ['high@example.test', 'medium@example.test'], '高リスク順で limit 件返す');
check(is_array($payload['individuals'][0]['detail']), '説明用 detail を配列で返す');

$_GET = ['band' => 'low', 'limit' => '500'];
$filtered = call_handler('report_handle_risk_individuals', [], 'viewer')['payload'];
check($filtered['total'] === 1 && count($filtered['individuals']) === 1, 'band filter の total は絞込後の件数');
check($filtered['individuals'][0]['email'] === 'low@example.test', 'low filter が一致する対象者だけを返す');

$_GET = ['band' => 'unknown'];
$invalidBand = call_handler('report_handle_risk_individuals', [], 'viewer')['payload'];
check($invalidBand['total'] === 3 && count($invalidBand['individuals']) === 3, '不正な band は filter なしとして一貫して扱う');

$_GET = ['limit' => '0'];
$minimumLimit = call_handler('report_handle_risk_individuals', [], 'viewer')['payload'];
check($minimumLimit['limit'] === 1 && count($minimumLimit['individuals']) === 1, 'limit の下限を1に制限する');
$_GET = ['limit' => '9999'];
$maximumLimit = call_handler('report_handle_risk_individuals', [], 'viewer')['payload'];
check($maximumLimit['limit'] === 500 && count($maximumLimit['individuals']) === 3, 'limit の上限を500に制限する');

$_GET = ['tenant_id' => (string) $otherTenant];
$forbidden = call_handler('report_handle_risk_individuals', [], 'viewer');
check($forbidden['code'] === 403, 'viewer は別 tenant を指定できない');
$selectedTenant = call_handler('report_handle_risk_individuals', [], 'superadmin')['payload'];
check($selectedTenant['computed_date'] === '2026-09-03' && $selectedTenant['total'] === 1, 'superadmin は選択 tenant のデータだけを参照する');

$_GET = [];
$companies = call_handler('report_handle_risk_by_company', [], 'viewer')['payload'];
check($companies['computed_date'] === '2026-09-01', '会社別も現在テナントの最新計算日を返す');
check(count($companies['companies']) === 2, '会社別集計から inactive/test/他テナントを除外する');
check((int) $companies['companies'][0]['count'] + (int) $companies['companies'][1]['count'] === 3, '会社別人数は実対象者だけになる');

Db::run('DELETE FROM human_risk_scores WHERE tenant_id = ?', [$tenantId]);
$_GET = [];
$empty = call_handler('report_handle_risk_individuals', [], 'viewer')['payload'];
check($empty['computed_date'] === null && $empty['individuals'] === [], 'snapshot がない場合は空状態を返す');
check($empty['bands'] === ['high' => 0, 'medium' => 0, 'low' => 0], 'snapshot なしでも bands の型を一定に保つ');
check($empty['total'] === 0 && $empty['limit'] === 100, 'snapshot なしでも pagination metadata を返す');

echo "ALL TESTS PASSED\n";
