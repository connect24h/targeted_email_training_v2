<?php
declare(strict_types=1);

/**
 * ビーコン(tracking_id)単位の開封明細 action=beacons の回帰テスト(2026-08-12)。
 *
 * 背景: 配信方式=all は 1人×N コンテンツを別 tracking_id(ビーコン)で送る。
 *   人物単位の集計だと「1人が何パターン開いたか」が潰れて見えない。
 *   ビーコン単位で1行ずつ、どのコンテンツのビーコンが開封されたかを返す。
 *
 * 検証:
 *  - 1人×4コンテンツ = 4 ビーコン行が出る(人物単位に潰れない)
 *  - コンテンツごとに開封/クリック/認証の有無が個別に出る
 *  - テスト対象者(is_test=1)のビーコンも除外されず出る(テスト全パターン確認が目的)
 *  - summary がビーコン単位の総数/開封数を返す
 */

require_once __DIR__ . '/helpers.php';
tet2_test_boot();
load_api('report');

$tenantId = current_user()['tenant_id'];

$campaignId = (int) Db::run("INSERT INTO campaigns (tenant_id, name, status, content_delivery, is_test) VALUES ({$tenantId}, 'BEACON_TEST', 'done', 'all', 1)") >= 0
    ? (int) Db::one('SELECT id FROM campaigns WHERE tenant_id = ? AND name = ? ORDER BY id DESC LIMIT 1', [$tenantId, 'BEACON_TEST'])['id']
    : 0;
check($campaignId > 0, 'テストキャンペーン作成');

// 2人 × 4コンテンツ = 8 ビーコン。person0 はテスト対象者(is_test=1)。
$people = [['p0@t', 'テスト太郎', 1], ['p1@t', '本番花子', 0]];
$pid = [];
foreach ($people as $p) {
    Db::run('INSERT INTO targets (tenant_id, email, name, company, is_test) VALUES (?,?,?,?,?)', [$tenantId, $p[0], $p[1], 'A社', $p[2]]);
    $pid[] = (int) Db::one('SELECT id FROM targets WHERE tenant_id = ? AND email = ?', [$tenantId, $p[0]])['id'];
}
// tracking_id を決定論的に: P{person}C{content}
$koban = 1;
foreach ($pid as $pi => $tid) {
    for ($cn = 1; $cn <= 4; $cn++) {
        Db::run('INSERT INTO campaign_targets (campaign_id, target_id, tracking_id, koban, send_status, content_no) VALUES (?,?,?,?,?,?)',
            [$campaignId, $tid, "P{$pi}C{$cn}", $koban++, 'sent', $cn]);
    }
}
// person0(テスト太郎): content1=open, content3=open+click+auth。content2,4 は未開封。
// person1(本番花子): content2=open。
$evs = [
    ['P0C1', 'open', '01'],
    ['P0C3', 'open', '02'], ['P0C3', 'click', '03'], ['P0C3', 'auth', '04'],
    ['P1C2', 'open', '05'],
];
foreach ($evs as $e) {
    Db::run('INSERT INTO events (tenant_id, campaign_id, tracking_id, event_type, occurred_at) VALUES (?,?,?,?,?)',
        [$tenantId, $campaignId, $e[0], $e[1], "2026-07-10 09:{$e[2]}:00"]);
}

$_GET = ['action' => 'beacons', 'campaign_id' => (string) $campaignId];
$r = call_handler('report_handle_beacons', [], 'viewer');
check($r['code'] === 200, 'beacons → 200(viewer可)');
$beacons = $r['payload']['beacons'];

// ビーコン単位で8行(人物単位に潰れない)
check(count($beacons) === 8, 'ビーコン単位で 2人×4コンテンツ=8行が出る(人物単位に潰れない)');

// tracking_id をキーに引けるように
$byTrk = [];
foreach ($beacons as $b) { $byTrk[$b['tracking_id']] = $b; }

// テスト太郎の各ビーコンが個別に見える
check($byTrk['P0C1']['opened'] === true, 'P0C1(テスト太郎/content1)は開封');
check($byTrk['P0C1']['clicked'] === false, 'P0C1 はクリックなし');
check($byTrk['P0C2']['opened'] === false, 'P0C2(content2)は未開封');
check($byTrk['P0C3']['opened'] === true && $byTrk['P0C3']['clicked'] === true && $byTrk['P0C3']['authed'] === true,
    'P0C3(content3)は開封+クリック+認証すべて個別に記録');
check($byTrk['P0C4']['opened'] === false, 'P0C4(content4)は未開封');

// テスト対象者のビーコンも除外されず出る(is_test を返す)
check($byTrk['P0C1']['is_test'] === 1, 'テスト対象者のビーコンも出る(is_test=1 を返す)');
check($byTrk['P0C1']['content_no'] === 1, 'content_no がビーコン行に含まれる');

// 本番花子の content2 開封
check($byTrk['P1C2']['opened'] === true, 'P1C2(本番花子/content2)は開封');
check($byTrk['P1C1']['opened'] === false, 'P1C1 は未開封');

// 開封日時が入る
check($byTrk['P0C1']['opened_at'] === '2026-07-10 09:01:00', '開封日時がビーコン行に入る');

// summary はビーコン単位: 全8, 開封3(P0C1,P0C3,P1C2), クリック1(P0C3), 認証1(P0C3)
$sum = $r['payload']['summary'];
check($sum['total'] === 8, 'summary.total = 8(全ビーコン)');
check($sum['opened'] === 3, 'summary.opened = 3(開封されたビーコン数)');
check($sum['clicked'] === 1, 'summary.clicked = 1');
check($sum['authed'] === 1, 'summary.authed = 1');

$_GET = [];
echo "ALL TESTS PASSED\n";
