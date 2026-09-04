<?php
declare(strict_types=1);

/**
 * report API の詳細レポート(P7, action=detail)回帰テスト。
 * 会社別/役職別/コンテンツ別/日別タイムラインの集計と、v1 準拠の率定義、
 * 期間フィルタ、IDOR 防御を固定する。
 */

require_once __DIR__ . '/helpers.php';
tet2_test_boot();
load_api('report');

$tenantId = current_user()['tenant_id'];

// 集計を決定論的にするため、専用キャンペーンとデータを投入する。
$campaignId = (int) Db::run("INSERT INTO campaigns (tenant_id, name, status) VALUES ({$tenantId}, 'REPORT_TEST', 'done')") >= 0
    ? (int) Db::one('SELECT id FROM campaigns WHERE tenant_id = ? AND name = ? ORDER BY id DESC LIMIT 1', [$tenantId, 'REPORT_TEST'])['id']
    : 0;
check($campaignId > 0, 'テストキャンペーン作成');

// targets 5人(会社A×3[役員/管理職/社員], 会社B×2[社員/不正役職])
$targetIds = [];
$defs = [
    ['ra@test', '会社A', '役員'], ['rb@test', '会社A', '管理職'], ['rc@test', '会社A', '社員'],
    ['rd@test', '会社B', '社員'], ['re@test', '会社B', '部長'],
];
foreach ($defs as $d) {
    Db::run('INSERT INTO targets (tenant_id, email, name, company, position_category) VALUES (?,?,?,?,?)',
        [$tenantId, $d[0], $d[0], $d[1], $d[2]]);
    $targetIds[] = (int) Db::one('SELECT id FROM targets WHERE tenant_id = ? AND email = ?', [$tenantId, $d[0]])['id'];
}
// campaign_targets: content_no 1,2,1,2,1、tracking_id は決定論的に付与
$trk = [];
foreach ($targetIds as $i => $tid) {
    $t = 'RTRK' . $i;
    $trk[$i] = $t;
    Db::run('INSERT INTO campaign_targets (campaign_id, target_id, tracking_id, koban, send_status, content_no) VALUES (?,?,?,?,?,?)',
        [$campaignId, $tid, $t, $i + 1, 'sent', ($i % 2) + 1]);
}
// events: trk0=open+click+auth(07-10), trk1=open(07-10), trk2=open×2+click(07-11), trk4=open(07-12)
$ev = [
    [$trk[0], 'open', '2026-07-10 09:00:00'], [$trk[0], 'click', '2026-07-10 09:01:00'], [$trk[0], 'auth', '2026-07-10 09:02:00'],
    [$trk[1], 'open', '2026-07-10 10:00:00'],
    [$trk[2], 'open', '2026-07-11 09:00:00'], [$trk[2], 'open', '2026-07-11 09:05:00'], [$trk[2], 'click', '2026-07-11 09:06:00'],
    [$trk[4], 'open', '2026-07-12 09:00:00'],
];
foreach ($ev as $e) {
    Db::run('INSERT INTO events (tenant_id, campaign_id, tracking_id, event_type, occurred_at) VALUES (?,?,?,?,?)',
        [$tenantId, $campaignId, $e[0], $e[1], $e[2]]);
}

// --- detail 全期間 ---
$_GET = ['action' => 'detail', 'campaign_id' => (string) $campaignId];
$r = call_handler('report_handle_detail', [], 'viewer');
check($r['code'] === 200, 'detail → 200(viewer可)');
$d = $r['payload'];

// summary: count5 / link2 / beacon4 / auth1 → beacon_rate 80, auth_rate 50(分母=link_clicked)
check($d['summary']['count'] === 5, 'summary count = 5');
check($d['summary']['beacon_opened'] === 4, 'summary beacon = 4(重複openはDISTINCT集約)');
check($d['summary']['auth_count'] === 1, 'summary auth = 1');
check($d['summary']['beacon_rate'] === 80.0, 'beacon_rate = 80(分母=count)');
check($d['summary']['auth_rate'] === 50.0, 'auth_rate = 50(1/2, 分母=link_clicked, v1定義)');

// 会社別: 会社A(count3,link2,beacon3,auth1), 会社B(count2,beacon1)
$byCompany = [];
foreach ($d['by_company'] as $row) { $byCompany[$row['company']] = $row; }
check($byCompany['会社A']['count'] === 3 && $byCompany['会社A']['beacon_opened'] === 3, '会社A count3/beacon3');
check($byCompany['会社A']['auth_rate'] === 50.0, '会社A auth_rate=50(1/2, 分母=link_clicked)');
check($byCompany['会社B']['beacon_rate'] === 50.0, '会社B beacon_rate=50(1/2)');

// 役職別: 不正役職(部長)は「その他」
$positions = array_column($d['by_position'], 'position');
check(in_array('その他', $positions, true), '不正役職は「その他」に分類');

// コンテンツ別: content1=3件, content2=2件
$byContent = [];
foreach ($d['by_content'] as $row) { $byContent[$row['content_no']] = $row; }
check($byContent['1']['count'] === 3 && $byContent['2']['count'] === 2, 'コンテンツ別 content1=3/content2=2');

// タイムライン累積サイト表示(click): 1→2
$cum = array_column($d['timeline'], 'cum_beacon');
check($cum === [1, 2], 'タイムライン累積サイト表示(click) = [1,2]');

// --- 期間フィルタ: 07-11以降 ---
$_GET = ['action' => 'detail', 'campaign_id' => (string) $campaignId, 'start_date' => '2026-07-11 00:00:00'];
$r = call_handler('report_handle_detail', [], 'viewer');
$d2 = $r['payload'];
check($d2['summary']['count'] === 5, '期間フィルタでも母数count=5維持(campaign_targetsは期間対象外)');
check($d2['summary']['beacon_opened'] === 2, '期間07-11以降 beacon=2(07-10分が除外)');
check(count($d2['timeline']) === 1, '期間フィルタでtimelineがclickのある1日に絞られる');

// --- IDOR: 他テナントの campaign_id ---
$other = Db::one('SELECT id FROM campaigns WHERE tenant_id != ? LIMIT 1', [$tenantId]);
if ($other !== null) {
    $_GET = ['action' => 'detail', 'campaign_id' => (string) $other['id']];
    $r = call_handler('report_handle_detail', [], 'viewer');
    check($r['code'] === 404, '他テナントの campaign detail → 404');
} else {
    echo "SKIP: 他テナント campaign なし\n";
}

// ============ レポート確定(コミット)による値固定 ============

// コミット前は is_committed=false かつリアルタイム集計
$_GET = ['action' => 'detail', 'campaign_id' => (string) $campaignId];
$r = call_handler('report_handle_detail', [], 'viewer');
check(($r['payload']['is_committed'] ?? null) === false, 'コミット前は is_committed=false');
$beaconBeforeCommit = $r['payload']['summary']['beacon_opened'];

// コミット(確定)。campaign_id は $_GET で渡す(report_json_body 静的キャッシュ回避)。
$_GET = ['action' => 'commit', 'campaign_id' => (string) $campaignId];
$r = call_handler('report_handle_commit', [], 'operator');
check($r['code'] === 200 && ($r['payload']['is_committed'] ?? null) === true, 'commit → 200/is_committed=true');

// コミット後、detail は is_committed=true で固定値を返す
$_GET = ['action' => 'detail', 'campaign_id' => (string) $campaignId];
$r = call_handler('report_handle_detail', [], 'viewer');
check(($r['payload']['is_committed'] ?? null) === true, 'コミット後 detail は is_committed=true');
check($r['payload']['summary']['beacon_opened'] === $beaconBeforeCommit, 'コミット直後の値はコミット前と一致');

// ★核心: コミット後に新イベントを追加しても、レポート値が変わらない
Db::run('INSERT INTO events (tenant_id, campaign_id, tracking_id, event_type, occurred_at) VALUES (?,?,?,?,?)',
    [$tenantId, $campaignId, $trk[3], 'open', '2026-07-13 09:00:00']); // trk3(未openだった)を新規open
$_GET = ['action' => 'detail', 'campaign_id' => (string) $campaignId];
$r = call_handler('report_handle_detail', [], 'viewer');
check($r['payload']['summary']['beacon_opened'] === $beaconBeforeCommit,
    'コミット後にイベント追加しても beacon_opened が固定される(★値凍結)');

// ★核心: コミット後に対象者/ユーザを削除しても値が変わらない(統計不変)
Db::run('DELETE FROM campaign_targets WHERE campaign_id = ? AND tracking_id = ?', [$campaignId, $trk[4]]);
$_GET = ['action' => 'detail', 'campaign_id' => (string) $campaignId];
$r = call_handler('report_handle_detail', [], 'viewer');
check($r['payload']['summary']['count'] === 5, 'コミット後に対象者を削除しても母数countが固定される(★統計不変)');

// 再コミットは 409(値固定の担保)
$_GET = ['action' => 'commit', 'campaign_id' => (string) $campaignId];
$r = call_handler('report_handle_commit', [], 'operator');
check($r['code'] === 409, '再コミットは 409(既に確定済み)');

// uncommit で確定解除 → 再びリアルタイムに戻る
$_GET = ['action' => 'uncommit', 'campaign_id' => (string) $campaignId];
$r = call_handler('report_handle_uncommit', [], 'tenant_admin');
check($r['code'] === 200, 'uncommit → 200');
$_GET = ['action' => 'detail', 'campaign_id' => (string) $campaignId];
$r = call_handler('report_handle_detail', [], 'viewer');
check(($r['payload']['is_committed'] ?? null) === false, 'uncommit 後は is_committed=false(リアルタイムに戻る)');
// uncommit後はリアルタイム集計に戻る。コミット中に対象者 trk4 を削除したので母数countは
// スナップショットの5ではなく現DBの4になる(=固定が解けて現状を反映)。
check($r['payload']['summary']['count'] === 4, 'uncommit後はリアルタイム集計(対象者削除が母数countに反映され4)');

// ============ 個人別統計(全キャンペーン横断, アーカイブ含む) ============
// このテストの targetIds[0](役員, RTRK0)は open+click+auth 全てあり。individuals に出るはず。
$_GET = ['action' => 'individuals'];
$r = call_handler('report_handle_individuals', [], 'viewer');
check($r['code'] === 200, 'individuals → 200(viewer可)');
$byId = [];
foreach ($r['payload']['individuals'] as $ind) { $byId[$ind['target_id']] = $ind; }
$topTarget = $byId[$targetIds[0]] ?? null;
check($topTarget !== null, '開封した対象者が個人別統計に出る');
check($topTarget['opens'] === 1 && $topTarget['auths'] === 1, '個人別: 参加1キャンペーンで open1/auth1');
check($topTarget['open_rate'] === 100.0, '個人別 open_rate=100(1キャンペーン参加で1開封)');

// アーカイブした対象者も個人別統計に残る(退職者の履歴分析)
load_api('targets'); // targets_handle_delete を使う
$_GET = [];
call_handler('targets_handle_delete', ['id' => $targetIds[0]], 'operator'); // 役員をアーカイブ
$_GET = ['action' => 'individuals'];
$r = call_handler('report_handle_individuals', [], 'viewer');
$byId2 = [];
foreach ($r['payload']['individuals'] as $ind) { $byId2[$ind['target_id']] = $ind; }
check(isset($byId2[$targetIds[0]]), 'アーカイブ済み対象者も個人別統計に残る(履歴分析)');
check(($byId2[$targetIds[0]]['status'] ?? '') === 'archived', 'アーカイブ済みは status=archived で識別できる');

// ============ テストユーザ(is_test)はレポート集計から除外される(2026-08-09) ============
// 集計に出ている対象者をテストユーザに変えると、既定の統計から消える。
$_GET = [];
$before = null;
$_GET = ['action' => 'individuals'];
$r = call_handler('report_handle_individuals', [], 'viewer');
$before = count($r['payload']['individuals']);

Db::run('UPDATE targets SET is_test = 1 WHERE id = ?', [$targetIds[0]]);

$_GET = ['action' => 'individuals'];
$r = call_handler('report_handle_individuals', [], 'viewer');
$idsAfter = array_column($r['payload']['individuals'], 'target_id');
check(!in_array($targetIds[0], $idsAfter, true), 'テストユーザは個人別統計(既定)から除外される');
check(count($r['payload']['individuals']) === $before - 1, 'テストユーザの分だけ個人別統計の件数が減る');

// include_test=1 なら検証用に含められる
$_GET = ['action' => 'individuals', 'include_test' => '1'];
$r = call_handler('report_handle_individuals', [], 'viewer');
$idsWithTest = array_column($r['payload']['individuals'], 'target_id');
check(in_array($targetIds[0], $idsWithTest, true), 'include_test=1 ならテストユーザも個人別統計に出る');
$testRow = null;
foreach ($r['payload']['individuals'] as $ind) { if ($ind['target_id'] === $targetIds[0]) { $testRow = $ind; } }
check((int) ($testRow['is_test'] ?? 0) === 1, '個人別統計が is_test を返す(画面の TEST バッジ用)');

// サマリー集計の母数からもテストユーザが抜ける
$_GET = ['action' => 'summary', 'campaign_id' => $campaignId];
$r = call_handler('report_handle_summary', [], 'viewer');
$summaryWithTestExcluded = (int) $r['payload']['summary']['target_count'];
Db::run('UPDATE targets SET is_test = 0 WHERE id = ?', [$targetIds[0]]);
$_GET = ['action' => 'summary', 'campaign_id' => $campaignId];
$r = call_handler('report_handle_summary', [], 'viewer');
$summaryAll = (int) $r['payload']['summary']['target_count'];
check($summaryWithTestExcluded === $summaryAll - 1, 'サマリーの母数からテストユーザが除外される');
$_GET = [];

echo "ALL TESTS PASSED\n";
