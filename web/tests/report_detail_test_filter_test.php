<?php
declare(strict_types=1);

/**
 * detail レポートの test_filter 対応(2026-08-12)の回帰テスト。
 *
 * 背景: 配信方式=all(全員に全コンテンツ)をテストパターンで送ると、開封するのは
 *   テスト対象者(targets.is_test=1)。しかし詳細レポートの軸集計は t.is_test=0 固定で
 *   テスト対象者を除外していたため、コンテンツ(ビーコン)別の開封が画面に出なかった。
 * 修正: report_detail_axis / report_compute_detail / report_handle_detail に
 *   test_filter(prod/test/all) を通し、test/all でテスト対象者の開封をビーコン別に見せる。
 *
 * 集計は元々 tracking_id(=ビーコン=コンテンツ)単位。フィルタで対象者を絞るだけで
 * コンテンツ別の開封が正しく数えられることを固定する。
 */

require_once __DIR__ . '/helpers.php';
tet2_test_boot();
load_api('report');

$tenantId = current_user()['tenant_id'];

$campaignId = (int) Db::run("INSERT INTO campaigns (tenant_id, name, status) VALUES ({$tenantId}, 'RTF_TEST', 'done')") >= 0
    ? (int) Db::one('SELECT id FROM campaigns WHERE tenant_id = ? AND name = ? ORDER BY id DESC LIMIT 1', [$tenantId, 'RTF_TEST'])['id']
    : 0;
check($campaignId > 0, 'テストキャンペーン作成');

// 5対象者。content_no = ($i%2)+1 → content1={t0,t2,t4}, content2={t1,t3}。
$defs = [['ta@t', 'A'], ['tb@t', 'A'], ['tc@t', 'A'], ['td@t', 'B'], ['te@t', 'B']];
$tids = [];
foreach ($defs as $d) {
    Db::run('INSERT INTO targets (tenant_id, email, name, company) VALUES (?,?,?,?)', [$tenantId, $d[0], $d[0], $d[1]]);
    $tids[] = (int) Db::one('SELECT id FROM targets WHERE tenant_id = ? AND email = ?', [$tenantId, $d[0]])['id'];
}
foreach ($tids as $i => $tid) {
    Db::run('INSERT INTO campaign_targets (campaign_id, target_id, tracking_id, koban, send_status, content_no) VALUES (?,?,?,?,?,?)',
        [$campaignId, $tid, 'RTF' . $i, $i + 1, 'sent', ($i % 2) + 1]);
}
// events: RTF0=open+click+auth, RTF2=open×2+click, RTF4=open。content1 の開封は RTF0,RTF2,RTF4 の3。
$evs = [
    ['RTF0', 'open', '01'], ['RTF0', 'click', '02'], ['RTF0', 'auth', '03'],
    ['RTF2', 'open', '05'], ['RTF2', 'open', '06'], ['RTF2', 'click', '07'],
    ['RTF4', 'open', '08'],
];
foreach ($evs as $e) {
    Db::run('INSERT INTO events (tenant_id, campaign_id, tracking_id, event_type, occurred_at) VALUES (?,?,?,?,?)',
        [$tenantId, $campaignId, $e[0], $e[1], "2026-07-10 09:{$e[2]}:00"]);
}

// RTF0(content1) をテスト対象者にする(=all配信でテストパターン送付した状況を模す)。
Db::run('UPDATE targets SET is_test = 1 WHERE id = ?', [$tids[0]]);

function content_map(array $payload): array
{
    $m = [];
    foreach ($payload['by_content'] as $row) { $m[$row['content_no']] = $row; }
    return $m;
}

// prod(既定): RTF0 が除外され content1 は母数 2(RTF2,RTF4)・開封 2。
$_GET = ['action' => 'detail', 'campaign_id' => (string) $campaignId];
$r = call_handler('report_handle_detail', [], 'viewer');
check($r['code'] === 200, 'prod detail → 200');
$prod = content_map($r['payload']);
check(($prod['1']['count'] ?? 0) === 2, 'prod: content1 母数からテスト対象者が抜ける(3→2)');
check(($prod['1']['beacon_opened'] ?? -1) === 2, 'prod: content1 開封は本番のみ(RTF2,RTF4)');

// test: テスト対象者のみ → content1 は RTF0 の 1件。開封・認証が見える。
$_GET = ['action' => 'detail', 'campaign_id' => (string) $campaignId, 'test_filter' => 'test'];
$r = call_handler('report_handle_detail', [], 'viewer');
check($r['code'] === 200, 'test detail → 200');
$test = content_map($r['payload']);
check(($test['1']['count'] ?? 0) === 1, 'test: content1 母数はテスト対象者のみ(RTF0)');
check(($test['1']['beacon_opened'] ?? 0) === 1, 'test: content1 でテスト対象者の開封がビーコン別に見える');
check(($test['1']['auth_count'] ?? 0) === 1, 'test: content1 でテスト対象者の認証も見える');

// all: 本番+テスト → content1 開封は RTF0,RTF2,RTF4 の 3件(ビーコン別に合算)。
$_GET = ['action' => 'detail', 'campaign_id' => (string) $campaignId, 'test_filter' => 'all'];
$r = call_handler('report_handle_detail', [], 'viewer');
check($r['code'] === 200, 'all detail → 200');
$all = content_map($r['payload']);
check(($all['1']['count'] ?? 0) === 3, 'all: content1 母数は本番+テストで3');
check(($all['1']['beacon_opened'] ?? 0) === 3, 'all: content1 開封は本番+テストで3件(ビーコン別合算)');

// 不正な test_filter は prod にフォールバック。
$_GET = ['action' => 'detail', 'campaign_id' => (string) $campaignId, 'test_filter' => 'bogus'];
$r = call_handler('report_handle_detail', [], 'viewer');
$bogus = content_map($r['payload']);
check(($bogus['1']['beacon_opened'] ?? -1) === 2, '不正な test_filter は prod にフォールバック');

$_GET = [];
echo "ALL TESTS PASSED\n";
