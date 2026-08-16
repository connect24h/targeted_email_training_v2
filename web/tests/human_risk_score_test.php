<?php
declare(strict_types=1);

/**
 * 個人リスクスコア(Human Risk Score)の計算を固定する。
 *
 * このスコアは「誰に次の手を打つか」を決めるために使うので、値そのものより
 * 順序と除外規則が壊れないことが重要。特に次の3点はテストがないと必ず壊れる:
 *
 *   1. click_bot(176件の残骸データ)を click と同じ重みで数えないこと。
 *      event_type を LIKE や NOT IN で書くと混入し、176人分のスコアが不当に悪化する。
 *   2. テスト用キャンペーン・検証用ユーザを除外すること。
 *      運用の動作確認(PowerShell によるビーコン一括取得など)がスコアに乗ると
 *      全員が同じ値になり、指標として機能しなくなる。
 *   3. 訓練を一度も受けていない人を「安全(0点)」にしないこと。
 *      データがないことと安全であることは違う。
 */

require_once __DIR__ . '/helpers.php';

tet2_test_boot();
require_once __DIR__ . '/../lib/HumanRiskScore.php';

const HRS_TODAY = '2026-08-16';

function hrsTarget(string $email, array $overrides = []): int
{
    $data = array_merge(['status' => 'active', 'is_test' => 0, 'company' => 'テスト社'], $overrides);
    return Db::insert(
        'INSERT INTO targets (tenant_id, email, name, status, is_test, company) VALUES (?, ?, ?, ?, ?, ?)',
        [1, $email, '対象者', $data['status'], $data['is_test'], $data['company']]
    );
}

function hrsCampaign(array $overrides = []): int
{
    $data = array_merge(['is_test' => 0], $overrides);
    return Db::insert(
        'INSERT INTO campaigns (tenant_id, name, status, is_test, created_by) VALUES (?, ?, ?, ?, ?)',
        [1, 'HRSキャンペーン', 'done', $data['is_test'], 1]
    );
}

/** 対象者にイベントを1件作る。$daysAgo で時間減衰を検証する。 */
function hrsEvent(int $campaignId, int $targetId, string $trackingId, string $eventType, int $daysAgo = 0): void
{
    $exists = Db::one('SELECT 1 FROM campaign_targets WHERE tracking_id = ?', [$trackingId]);
    if ($exists === null) {
        Db::run(
            'INSERT INTO campaign_targets (campaign_id, target_id, tracking_id, koban) VALUES (?, ?, ?, ?)',
            [$campaignId, $targetId, $trackingId, $targetId]
        );
    }
    $occurred = date('Y-m-d H:i:s', strtotime(HRS_TODAY . ' 10:00:00') - $daysAgo * 86400);
    Db::run(
        'INSERT INTO events (tenant_id, campaign_id, tracking_id, event_type, occurred_at, source)
         VALUES (?, ?, ?, ?, ?, ?)',
        [1, $campaignId, $trackingId, $eventType, $occurred, 'apache_access']
    );
}

$campaignId = hrsCampaign();
$testCampaignId = hrsCampaign(['is_test' => 1]);

// --- 帯の境界(定数の意味を固定する) ---
check(HumanRiskScore::band(85.0) === 'high', 'スコア85はhigh');
check(HumanRiskScore::band(60.0) === 'high', '境界値60はhigh');
check(HumanRiskScore::band(59.9) === 'medium', '60未満はmedium');
check(HumanRiskScore::band(30.0) === 'medium', '境界値30はmedium');
check(HumanRiskScore::band(29.9) === 'low', '30未満はlow');

// --- 未参加者は基準点のまま。0(=安全)にしない ---
$noDataId = hrsTarget('nodata@example.test');
$noData = HumanRiskScore::computeForTarget($noDataId, 1, HRS_TODAY);
check($noData['score'] === 50.0, '訓練履歴のない人は基準点50(データがない=安全ではない)');
check($noData['band'] === 'medium', '未参加者はmedium帯として扱う');

// --- 行動の重み: auth > click > open ---
$openerId = hrsTarget('opener@example.test');
hrsEvent($campaignId, $openerId, '5000000001', 'open');
$clickerId = hrsTarget('clicker@example.test');
hrsEvent($campaignId, $clickerId, '5000000002', 'click');
$authId = hrsTarget('auth@example.test');
hrsEvent($campaignId, $authId, '5000000003', 'auth');

$open = HumanRiskScore::computeForTarget($openerId, 1, HRS_TODAY);
$click = HumanRiskScore::computeForTarget($clickerId, 1, HRS_TODAY);
$auth = HumanRiskScore::computeForTarget($authId, 1, HRS_TODAY);

check($open['score'] < $click['score'], '開封よりクリックの方が高リスク');
check($click['score'] < $auth['score'], 'クリックより認証情報入力の方が高リスク');
check($auth['band'] === 'high', '認証情報を入力した人はhigh帯になる');

// --- 最重要: click_bot を click として数えない ---
$botId = hrsTarget('bot@example.test');
hrsEvent($campaignId, $botId, '5000000004', 'click_bot');
$bot = HumanRiskScore::computeForTarget($botId, 1, HRS_TODAY);
check($bot['phish_component'] === 0.0, 'click_bot はリスクに加算しない(誤計上事故の残骸データ)');
check($bot['score'] < $click['score'], 'ボットのクリックは人間のクリックと区別する');
check(($bot['detail']['click'] ?? 0) === 0, 'click_bot を click として数えない');

// --- 報告は減点(正しい行動) ---
$reporterId = hrsTarget('reporter@example.test');
hrsEvent($campaignId, $reporterId, '5000000005', 'click');
hrsEvent($campaignId, $reporterId, '5000000005', 'report', 0);
$reporter = HumanRiskScore::computeForTarget($reporterId, 1, HRS_TODAY);
check($reporter['score'] < $click['score'], 'クリック後に報告した人はしなかった人より低リスク');
check($reporter['report_credit'] > 0, '報告が減点要素として記録される');

// 踏まずに報告した人が最も低い
$goodId = hrsTarget('good@example.test');
hrsEvent($campaignId, $goodId, '5000000006', 'report');
$good = HumanRiskScore::computeForTarget($goodId, 1, HRS_TODAY);
check($good['score'] < 50.0, '踏まずに報告した人は基準点より低リスクになる');
check($good['score'] < $reporter['score'], '踏まずに報告した人が最も低リスク');

// --- 訓練を受けて正しく無視した人を、未参加者と区別する ---
// これがないと「褒めるべき人」と「まだ訓練していない人」が同じ50点で並ぶ。
$ignoredId = hrsTarget('ignored@example.test');
Db::run(
    'INSERT INTO campaign_targets (campaign_id, target_id, tracking_id, koban) VALUES (?, ?, ?, ?)',
    [$campaignId, $ignoredId, '5400000001', $ignoredId]
);
$ignored = HumanRiskScore::computeForTarget($ignoredId, 1, HRS_TODAY);
check($ignored['score'] < 50.0, '訓練を受けて反応しなかった人は基準点より低リスク');
check($ignored['score'] < $noData['score'], '正しく無視した人と未参加者を区別する');
check(($ignored['detail']['clean_campaigns'] ?? 0) === 1, '無反応だったキャンペーン数を内訳に残す');

// 開封してしまった人より、無視した人の方が低リスク
check($ignored['score'] < $open['score'], '無視した人は開封した人より低リスク');

// --- 時間減衰: 古い失敗は軽くなる ---
$recentId = hrsTarget('recent@example.test');
hrsEvent($campaignId, $recentId, '5000000007', 'click', 0);
$oldId = hrsTarget('old@example.test');
hrsEvent($campaignId, $oldId, '5000000008', 'click', 180);

$recent = HumanRiskScore::computeForTarget($recentId, 1, HRS_TODAY);
$old = HumanRiskScore::computeForTarget($oldId, 1, HRS_TODAY);
check($old['score'] < $recent['score'], '半年前の失敗は直近の失敗より軽い');
check($old['score'] > 50.0, '古くても失敗は残る(ゼロにはしない)');

// --- テスト用キャンペーンは除外する ---
$testCampTargetId = hrsTarget('testcamp@example.test');
hrsEvent($testCampaignId, $testCampTargetId, '5000000009', 'auth');
$testCamp = HumanRiskScore::computeForTarget($testCampTargetId, 1, HRS_TODAY);
check($testCamp['score'] === 50.0, 'is_test のキャンペーンでの反応はスコアに入れない');

// --- 上限・下限のclamp ---
$manyId = hrsTarget('many@example.test');
for ($i = 0; $i < 30; $i++) {
    hrsEvent($campaignId, $manyId, '51000000' . str_pad((string) $i, 2, '0', STR_PAD_LEFT), 'auth', $i);
}
$many = HumanRiskScore::computeForTarget($manyId, 1, HRS_TODAY);
check($many['score'] <= 100.0, 'スコアは100を超えない');
check($many['band'] === 'high', '繰り返し失敗した人はhigh');

$manyGoodId = hrsTarget('manygood@example.test');
for ($i = 0; $i < 30; $i++) {
    hrsEvent($campaignId, $manyGoodId, '52000000' . str_pad((string) $i, 2, '0', STR_PAD_LEFT), 'report', $i);
}
$manyGood = HumanRiskScore::computeForTarget($manyGoodId, 1, HRS_TODAY);
check($manyGood['score'] >= 0.0, 'スコアは0を下回らない');

// --- 内訳が説明可能な形で残る ---
check(isset($auth['detail']) && is_array($auth['detail']), '内訳(detail)が配列で返る');
check(($auth['detail']['auth'] ?? 0) === 1, '素の件数が内訳に残る(なぜこの点数かを説明できる)');
check($auth['phish_component'] > 0, '訓練行動の寄与が分離して記録される');

// --- バッチ実行: 冪等・除外規則 ---
$archivedId = hrsTarget('archived@example.test', ['status' => 'archived']);
hrsEvent($campaignId, $archivedId, '5300000001', 'auth');
$testUserId = hrsTarget('tester@example.test', ['is_test' => 1]);
hrsEvent($campaignId, $testUserId, '5300000002', 'auth');

$result = HumanRiskScore::run(HRS_TODAY);
check($result['scored'] > 0, 'バッチ実行で対象者のスコアを保存する');

$saved = (int) Db::one('SELECT COUNT(*) AS c FROM human_risk_scores WHERE computed_date = ?', [HRS_TODAY])['c'];
check($saved === $result['scored'], '保存件数と戻り値が一致する');

check(
    Db::one('SELECT 1 FROM human_risk_scores WHERE target_id = ?', [$archivedId]) === null,
    '退職者(archived)のスコアは作らない'
);
check(
    Db::one('SELECT 1 FROM human_risk_scores WHERE target_id = ?', [$testUserId]) === null,
    '検証用ユーザ(is_test)のスコアは作らない'
);

// 2回流しても行が増えない(日次timerが再実行されても安全)
$second = HumanRiskScore::run(HRS_TODAY);
$afterSecond = (int) Db::one('SELECT COUNT(*) AS c FROM human_risk_scores WHERE computed_date = ?', [HRS_TODAY])['c'];
check($afterSecond === $saved, '同じ日に再実行しても行が増えない(冪等)');
check($second['scored'] === $result['scored'], '再実行しても対象件数は変わらない');

// 保存された値の妥当性
$outOfRange = (int) Db::one(
    'SELECT COUNT(*) AS c FROM human_risk_scores WHERE score < 0 OR score > 100'
)['c'];
check($outOfRange === 0, '保存されたスコアが全て0-100の範囲に収まる');

$badBand = (int) Db::one(
    "SELECT COUNT(*) AS c FROM human_risk_scores WHERE band NOT IN ('low','medium','high')"
)['c'];
check($badBand === 0, '帯は low/medium/high のいずれか');

echo "ALL TESTS PASSED\n";
