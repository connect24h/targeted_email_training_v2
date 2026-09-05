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

// 推奨用の履歴。実データを使わず合成DBだけで検証する。
function riskFailure(int $tenant, int $target, string $type, string $at, ?string $ext = null, int $isTest = 0, ?string $deleted = null): int
{
    $campaign = Db::insert('INSERT INTO campaigns (tenant_id, name, attachment_ext, is_test, deleted_at) VALUES (?, ?, ?, ?, ?)',
        [$tenant, '=campaign', $ext, $isTest, $deleted]);
    $tracking = 'risk-' . $campaign . '-' . $target;
    Db::run('INSERT INTO campaign_targets (campaign_id, target_id, tracking_id) VALUES (?, ?, ?)', [$campaign, $target, $tracking]);
    Db::run('INSERT INTO events (tenant_id, campaign_id, tracking_id, event_type, occurred_at) VALUES (?, ?, ?, ?, ?)',
        [$tenant, $campaign, $tracking, $type, $at]);
    return $campaign;
}
function riskEducation(int $tenant, int $target, string $status, ?string $completed, ?string $deadline): void
{
    $delivery = Db::insert('INSERT INTO edu_deliveries (tenant_id, title, deadline) VALUES (?, ?, ?)', [$tenant, '教育', $deadline]);
    Db::run('INSERT INTO edu_assignments (tenant_id, delivery_id, target_id, access_token, status, completed_at) VALUES (?, ?, ?, ?, ?, ?)',
        [$tenant, $delivery, $target, 'risk-edu-' . $delivery, $status, $completed]);
}
foreach (['phishing', 'password-management', 'malware', 'social-engineering'] as $slug) {
    Db::run('INSERT OR IGNORE INTO edu_categories (tenant_id, name, slug) VALUES (NULL, ?, ?)', [$slug, $slug]);
}
$customCategory = Db::insert('INSERT INTO edu_categories (tenant_id, name, slug) VALUES (?, ?, ?)', [$tenantId, '独自フィッシング', 'phishing']);
Db::run('INSERT INTO edu_categories (tenant_id, name, slug) VALUES (?, ?, ?)', [$otherTenant, '他社専用', 'malware']);
$authCampaign = riskFailure($tenantId, $high, 'auth', '2026-08-20 10:00:00');
riskFailure($tenantId, $high, 'click', '2026-09-02 00:00:00');
riskFailure($tenantId, $high, 'click', '2026-09-01 12:00:00', null, 1);
riskFailure($tenantId, $high, 'click', '2026-09-01 13:00:00', null, 0, '2026-09-01');
riskFailure($tenantId, $medium, 'click', '2026-08-21 10:00:00', 'html');
riskFailure($tenantId, $low, 'auth', '2026-08-22 10:00:00', null, 1);
riskFailure($tenantId, $low, 'click_bot', '2026-08-23 10:00:00');
riskFailure($tenantId, $test, 'auth', '2026-08-22 10:00:00');
riskEducation($tenantId, $high, 'completed', '2026-08-21 10:00:00', '2026-08-25');
riskEducation($tenantId, $medium, 'assigned', null, '2026-08-31');
riskEducation($tenantId, $medium, 'started', null, '2026-09-01');
riskEducation($tenantId, $medium, 'expired', null, '2026-08-30');
riskEducation($tenantId, $medium, 'completed', '2026-08-20 10:00:00', '2026-08-20');

// exit するCSV経路は独立プロセスで、実際のサニタイザと一緒に実行する。
if (($argv[1] ?? '') === '--csv') {
    $bootstrap = file_get_contents(__DIR__ . '/../lib/bootstrap.php');
    preg_match('/function tet2_csv_sanitize\(.*?\n\}/s', $bootstrap, $match);
    eval($match[0]);
    Db::run('UPDATE targets SET name = ?, email = ?, company = ?, position_category = ? WHERE id = ?',
        ['=name', '=email', '=company', '=position', $high]);
    $_GET = ['format' => 'csv', 'band' => 'high'];
    report_handle_risk_recommendations();
}

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

$_GET = [];
$recommendations = call_handler('report_handle_risk_recommendations', [], 'viewer')['payload'];
check($recommendations['computed_date'] === '2026-09-01' && $recommendations['limit'] === 20, '推奨の基準日と既定limit');
check($recommendations['total'] === 3 && array_column($recommendations['rows'], 'id') === [$high, $medium, $low], '推奨から旧snapshot・test・inactive・他tenantを除外');
[$authRow, $attachmentRow, $cleanRow] = $recommendations['rows'];
check($authRow['last_failure']['campaign_id'] === $authCampaign && $authRow['failure_kind'] === 'auth', '基準日後・test・削除キャンペーンの失敗を除外');
check(array_column($authRow['recommended_categories'], 'slug') === ['phishing', 'password-management'], 'authにはphishingとpassword-management');
check($authRow['recommended_categories'][0]['id'] === $customCategory, '同slugはテナント固有カテゴリ優先');
check($attachmentRow['failure_kind'] === 'click_attachment' && $attachmentRow['last_failure']['is_attachment'] === true
    && array_column($attachmentRow['recommended_categories'], 'slug') === ['malware'], '添付clickにはmalware');
check($cleanRow['last_failure'] === null && $cleanRow['recommended_categories'] === [], '失敗なし・testキャンペーンのみは推奨空');
check($authRow['edu']['completed_after_failure'] === true && $authRow['edu']['last_completed_at'] === '2026-08-21 10:00:00', '失敗後の完了を検出');
check($attachmentRow['edu'] === ['assigned_count' => 2, 'completed_count' => 1, 'last_completed_at' => '2026-08-20 10:00:00', 'overdue_count' => 2, 'completed_after_failure' => false], '教育集計はexpiredを割当から除き期限超過には含む・当日期限を除く');
check(str_contains($attachmentRow['reason'], '期限超過') && !str_contains($authRow['reason'], '=campaign') && is_array($authRow['detail']), '固定理由とdetailを返す');
$_GET = ['tenant_id' => (string) $otherTenant];
check(call_handler('report_handle_risk_recommendations', [], 'operator')['code'] === 403, 'operatorの他テナント指定は403');
$_GET = ['band' => 'medium', 'limit' => '999'];
$filtered = call_handler('report_handle_risk_recommendations')['payload'];
check($filtered['total'] === 1 && $filtered['limit'] === 500 && $filtered['rows'][0]['id'] === $medium, '推奨の帯と上限');
$_GET = ['limit' => '0'];
check(call_handler('report_handle_risk_recommendations')['payload']['limit'] === 1, '推奨の下限');
$proc = proc_open([PHP_BINARY, __FILE__, '--csv'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
$csv = stream_get_contents($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[1]); fclose($pipes[2]);
check(proc_close($proc) === 0 && $stderr === '', 'CSVハンドラ独立実行');
check(str_starts_with($csv, "\xEF\xBB\xBF氏名,メール,会社,役職カテゴリ"), 'CSVはUTF-8 BOMと指定ヘッダ');
$csvLines = explode("\n", trim(substr($csv, 3)));
$csvRow = str_getcsv($csvLines[1], ',', '"', '');
check(array_slice($csvRow, 0, 4) === ["'=name", "'=email", "'=company", "'=position"] && $csvRow[8] === "'=campaign", 'CSVの対象者・キャンペーン由来の数式を無害化');
check(count($csvRow) === 14 && count($csvLines) === 2, 'CSVも帯filterと14列を維持');
// 基準日末尾の通常clickは含め、空の添付拡張子を添付型と判定しない。
riskFailure($tenantId, $low, 'click', '2026-09-01 23:59:59', '');
$_GET = ['band' => 'low'];
$clickRow = call_handler('report_handle_risk_recommendations')['payload']['rows'][0];
check($clickRow['failure_kind'] === 'click' && $clickRow['last_failure']['is_attachment'] === false
    && array_column($clickRow['recommended_categories'], 'slug') === ['phishing', 'social-engineering'], '基準日末尾の通常clickにはphishingとsocial-engineering');
// 他テナントのキャンペーンに誤って対象者が紐付いていても履歴へ混ぜない。
riskFailure($otherTenant, $high, 'click', '2026-09-01 23:59:59');
riskEducation($otherTenant, $high, 'assigned', null, '2026-08-01');
$_GET = ['band' => 'high'];
$isolated = call_handler('report_handle_risk_recommendations')['payload']['rows'][0];
check($isolated['last_failure']['campaign_id'] === $authCampaign && $isolated['edu']['assigned_count'] === 0, '失敗と教育の他tenant関連行を除外');
$_GET = ['format' => 'xlsx'];
check(call_handler('report_handle_risk_recommendations')['code'] === 400, '未対応formatを拒否');
// 同点時は失敗日時降順でlimitより前に並べる。同日時イベントはid降順。
Db::run('UPDATE human_risk_scores SET score = 85 WHERE target_id = ?', [$medium]);
riskFailure($tenantId, $medium, 'auth', '2026-08-21 10:00:00');
$_GET = ['limit' => '1'];
$tied = call_handler('report_handle_risk_recommendations')['payload'];
check($tied['rows'][0]['id'] === $medium && $tied['rows'][0]['failure_kind'] === 'auth' && $tied['total'] === 3, '同点は失敗日時・同日時イベントはid降順');
Db::run("DELETE FROM edu_categories WHERE slug IN ('phishing', 'password-management')");
check(call_handler('report_handle_risk_recommendations')['payload']['rows'][0]['recommended_categories'] === [], '未定義slugは推奨空');

Db::run('DELETE FROM human_risk_scores WHERE tenant_id = ?', [$tenantId]);
$_GET = [];
$empty = call_handler('report_handle_risk_individuals', [], 'viewer')['payload'];
check($empty['computed_date'] === null && $empty['individuals'] === [], 'snapshot がない場合は空状態を返す');
check($empty['bands'] === ['high' => 0, 'medium' => 0, 'low' => 0], 'snapshot なしでも bands の型を一定に保つ');
check($empty['total'] === 0 && $empty['limit'] === 100, 'snapshot なしでも pagination metadata を返す');

$_GET = [];
$emptyRecommendations = call_handler('report_handle_risk_recommendations')['payload'];
check($emptyRecommendations['computed_date'] === null && $emptyRecommendations['rows'] === [] && $emptyRecommendations['total'] === 0, '推奨snapshotなし');

echo "ALL TESTS PASSED\n";
