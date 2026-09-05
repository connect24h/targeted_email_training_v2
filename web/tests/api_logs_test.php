<?php
declare(strict_types=1);

/**
 * logs API(P8)の回帰テスト。
 * delivery/events/replies/schedule/audit の各ビューアが、テナント分離・
 * IDOR 防御・ロールゲート・入力検証を守ることを固定する。
 */

require_once __DIR__ . '/helpers.php';
tet2_test_boot();
// weblog_ip_info 等は lib/GeoIpCache.php に集約。load_api は require 行を剥がすため先読みする。
// キャッシュ書き込みが本番/正本の data/ を汚さないよう、テスト専用の一時ファイルに向ける。
$ipCacheTmp = tempnam(sys_get_temp_dir(), 'iplogtest_') ?: (sys_get_temp_dir() . '/iplogtest.json');
@unlink($ipCacheTmp);
putenv('TET2_IP_CACHE=' . $ipCacheTmp);
require_once __DIR__ . '/../lib/GeoIpCache.php';
// 訓練結果/明細の行生成と logs_campaign_filter() は lib/TrainingLogRows.php に集約
// (レポートExcelと共有するため)。これも load_api が require を剥がすので先読みする。
require_once __DIR__ . '/../lib/TrainingLogRows.php';
// 返信者(Maildir パース)一式も lib/ReplyMaildir.php に移設済み(レポートExcelと共有のため)。
require_once __DIR__ . '/../lib/ReplyMaildir.php';
register_shutdown_function(static function () use ($ipCacheTmp) { @unlink($ipCacheTmp); });
load_api('logs');

$tenantId = current_user()['tenant_id'];

// 全テナントのWeb access logを書き換えるGeoIP更新はsuperadmin限定。
check(logs_min_role_for_action('webaccess_geoip') === 'superadmin',
    'webaccess_geoip → superadmin限定');
check(logs_min_role_for_action('audit') === 'operator',
    'audit → operator以上');
check(logs_min_role_for_action('delivery') === 'viewer',
    'delivery → viewer以上');

// 他テナントを1つ確保(越境検証用)。
$otherTenant = Db::one('SELECT id FROM tenants WHERE id != ? LIMIT 1', [$tenantId]);
$otherTid = $otherTenant ? (int) $otherTenant['id'] : null;

// テストデータ: 自テナントと他テナントの campaign + delivery/events/schedule/audit
Db::run("INSERT INTO campaigns (tenant_id, name, status) VALUES ({$tenantId}, 'LOGS_TEST', 'done')");
$cid = (int) Db::one('SELECT id FROM campaigns WHERE tenant_id = ? AND name = ? ORDER BY id DESC LIMIT 1', [$tenantId, 'LOGS_TEST'])['id'];
Db::run('INSERT INTO delivery_log (campaign_id, tracking_id, to_email, result, occurred_at) VALUES (?,?,?,?,?)',
    [$cid, 'LTRK1', 'lt@test', 'sent', '2026-07-10 09:00:00']);
Db::run('INSERT INTO send_schedule (campaign_id, batch_no, scheduled_at, status) VALUES (?,?,?,?)',
    [$cid, 1, '2026-07-10 09:00:00', 'done']);
Db::run('INSERT INTO events (tenant_id, campaign_id, tracking_id, event_type, occurred_at, raw) VALUES (?,?,?,?,?,?)',
    [$tenantId, $cid, 'LTRK1', 'reply', '2026-07-11 14:00:00', '件名: テスト返信']);
Db::run('INSERT INTO audit_log (tenant_id, user_id, action, ip) VALUES (?,?,?,?)',
    [$tenantId, 1, 'logs.test.action', '1.2.3.4']);

if ($otherTid !== null) {
    Db::run("INSERT INTO campaigns (tenant_id, name, status) VALUES ({$otherTid}, 'LOGS_OTHER', 'done')");
    $ocid = (int) Db::one('SELECT id FROM campaigns WHERE tenant_id = ? AND name = ? ORDER BY id DESC LIMIT 1', [$otherTid, 'LOGS_OTHER'])['id'];
    Db::run('INSERT INTO delivery_log (campaign_id, tracking_id, to_email, result) VALUES (?,?,?,?)', [$ocid, 'OTRK', 'other@test', 'sent']);
    Db::run('INSERT INTO audit_log (tenant_id, user_id, action, ip) VALUES (?,?,?,?)', [$otherTid, 1, 'other.action', '9.9.9.9']);
}

// helper: rows から特定列の集合を得る
$colOf = fn(array $rows, string $k) => array_map(fn($r) => $r[$k] ?? null, $rows);

// --- delivery: 自テナントのみ、他テナントの to_email が混ざらない ---
$_GET = ['action' => 'delivery'];
$r = call_handler('logs_handle_delivery', [], 'viewer', [$tenantId]);
check($r['code'] === 200, 'delivery → 200(viewer可)');
$emails = $colOf($r['payload']['rows'], 'to_email');
check(in_array('lt@test', $emails, true), 'delivery: 自テナントの送信が見える');
check(!in_array('other@test', $emails, true), 'delivery: 他テナントの送信は見えない(分離)');

// --- replies: reply のみ、対象者情報の突合(raw本文) ---
$_GET = ['action' => 'replies'];
$r = call_handler('logs_handle_replies', [], 'viewer', [$tenantId]);
$raws = $colOf($r['payload']['rows'], 'raw');
check(in_array('件名: テスト返信', $raws, true), 'replies: reply の raw 本文が取れる');

// --- schedule: 自テナントのみ ---
$_GET = ['action' => 'schedule'];
$r = call_handler('logs_handle_schedule', [], 'viewer', [$tenantId]);
check($r['code'] === 200 && count($r['payload']['rows']) >= 1, 'schedule → 200 かつ1件以上');

// --- audit: operator以上。viewerは(ディスパッチャで)403、直接ハンドラは動くのでロールはディスパッチャ責務 ---
$_GET = ['action' => 'audit'];
$r = call_handler('logs_handle_audit', [], 'operator', [$tenantId]);
$actions = $colOf($r['payload']['rows'], 'action');
check(in_array('logs.test.action', $actions, true), 'audit: 自テナントの操作ログが見える');
if ($otherTid !== null) {
    check(!in_array('other.action', $actions, true), 'audit: 他テナントの操作ログは見えない(分離)');
}

// --- IDOR: 他テナントの campaign_id 指定 → 404 ---
if ($otherTid !== null) {
    $_GET = ['action' => 'delivery', 'campaign_id' => (string) $ocid];
    $r = call_handler('logs_handle_delivery', [], 'viewer', [$tenantId]);
    check($r['code'] === 404, 'delivery: 他テナント campaign_id → 404(IDOR)');
}

// --- event_type 不正値 → 400 ---
$_GET = ['action' => 'events', 'event_type' => 'hacker'];
$r = call_handler('logs_handle_events', [], 'viewer', [$tenantId]);
check($r['code'] === 400, 'events: 不正な event_type → 400');

// --- limit クランプ(500超は500に丸め) ---
$_GET = ['action' => 'delivery', 'limit' => '9999'];
$r = call_handler('logs_handle_delivery', [], 'viewer', [$tenantId]);
check($r['payload']['limit'] === 500, 'limit は500にクランプされる');

// ============================================================
// 訓練結果ログ(メール一覧・明細) — events.raw パース + 対象者結合。
// ============================================================

// テスト用対象者 + campaign_targets(乱数)を用意。
Db::run("INSERT INTO targets (tenant_id, email, name, company, title, position_category) VALUES (?,?,?,?,?,?)",
    [$tenantId, 'tlog@example.com', '訓練 太郎', 'テスト社', '部長', '管理職']);
$tgtId = (int) Db::one("SELECT id FROM targets WHERE email='tlog@example.com'")['id'];
$rand = '9900112233';
Db::run("INSERT INTO campaign_targets (campaign_id, target_id, tracking_id, koban, send_status) VALUES (?,?,?,?,?)",
    [$cid, $tgtId, $rand, 1, 'sent']);

// auth イベント(v1 training_log 形式の raw: GeoIP 埋込)。
$authRaw = "[2026-07-15 10:00:00] Random: {$rand} | Type: box | Email: input@ex.com | Password: pw123 | "
         . "IP: 203.0.113.5 | Country: Japan (JP) | Location: Tokyo, Tokyo, Japan | ISP: TestISP | "
         . "Org: TestOrg | AS: AS12345 Test | Hostname: host.example.jp | UserAgent: Mozilla/5.0 Test";
Db::run("INSERT INTO events (tenant_id, campaign_id, tracking_id, event_type, auth_variant, occurred_at, source, raw) VALUES (?,?,?,?,?,?,?,?)",
    [$tenantId, $cid, $rand, 'auth', 'box', '2026-07-15 10:00:00', 'text_log', $authRaw]);
// click イベント(Apache 行, GeoIP なし)を2件(重複判定用)。
Db::run("INSERT INTO events (tenant_id, campaign_id, tracking_id, event_type, occurred_at, source, raw) VALUES (?,?,?,?,?,?,?)",
    [$tenantId, $cid, $rand, 'click', '2026-07-15 09:59:00', 'apache_access', 'GET /link-' . $rand . '.html']);
Db::run("INSERT INTO events (tenant_id, campaign_id, tracking_id, event_type, occurred_at, source, raw) VALUES (?,?,?,?,?,?,?)",
    [$tenantId, $cid, $rand, 'click', '2026-07-15 09:58:00', 'apache_access', 'GET /link-' . $rand . '.html']);

// raw パース関数の単体。
$parsed = training_log_parse_raw($authRaw);
check($parsed['email'] === 'input@ex.com', 'training_log_parse_raw: 入力Email');
check($parsed['password'] === 'pw123', 'training_log_parse_raw: Password');
check($parsed['ip'] === '203.0.113.5', 'training_log_parse_raw: IP');
check($parsed['country'] === 'Japan (JP)', 'training_log_parse_raw: 国');
check($parsed['isp'] === 'TestISP', 'training_log_parse_raw: ISP');
check($parsed['as'] === 'AS12345 Test', 'training_log_parse_raw: AS');
check(strpos($parsed['useragent'], 'Mozilla/5.0 Test') !== false, 'training_log_parse_raw: UserAgent(末尾)');

// click(apache_access 生ログ)の国/ISP は raw にラベルがないので ip_cache.json から補完する。
// テスト用にキャッシュへ既知IPを注入し、Apache combined 形式の raw で補完を固定する。
$cacheForTest = weblog_load_ip_cache();
$cacheForTest['198.51.100.7'] = ['country' => 'Japan (JP)', 'location' => 'Chiyoda, Tokyo, Japan',
    'isp' => 'ClickISP', 'org' => 'ClickOrg', 'as' => 'AS64500 Click', 'hostname' => 'click.example.jp'];
weblog_save_ip_cache($cacheForTest);
$clickRaw = '198.51.100.7 - - [15/Jul/2026:09:59:00 +0900] "GET /link-1234567890.html HTTP/1.1" 200 '
    . '6900 "https://filesend.cojp.online/link-1234567890.html" "Mozilla/5.0 (iPhone) ClickUA"';
$pc = training_log_parse_raw($clickRaw);
check($pc['ip'] === '198.51.100.7', 'click補完: IPを生ログから抽出');
check($pc['country'] === 'Japan (JP)', 'click補完: 国をキャッシュから補完');
check($pc['isp'] === 'ClickISP', 'click補完: ISPをキャッシュから補完');
check($pc['as'] === 'AS64500 Click', 'click補完: ASをキャッシュから補完');
check($pc['hostname'] === 'click.example.jp', 'click補完: ホスト名をキャッシュから補完');
check(strpos($pc['useragent'], 'ClickUA') !== false, 'click補完: UAを生ログ末尾から抽出');
// キャッシュに無い IP は空のまま(機械除外や再API通信はしない)。
$clickRawUnknown = '203.0.113.222 - - [15/Jul/2026:09:59:00 +0900] "GET /link-1234567890.html HTTP/1.1" 200 6900 "-" "UA-x"';
$pu = training_log_parse_raw($clickRawUnknown);
check($pu['ip'] === '203.0.113.222' && $pu['country'] === '', 'click補完: 未キャッシュIPは国が空のまま');

// 明細行の生成(キャンペーン別)。
$_GET = ['action' => 'training_log_detail', 'campaign_id' => (string) $cid];
$rows = training_log_detail_rows($tenantId);
check(count($rows) === 3, '訓練結果ログ明細: auth1 + click2 = 3行');
$authRow = null;
foreach ($rows as $rr) { if ($rr['type'] === 'box') { $authRow = $rr; break; } }
check($authRow !== null, '明細: box(認証)行が存在');
check($authRow['email'] === 'input@ex.com' && $authRow['ip'] === '203.0.113.5', '明細: 認証行に入力Email/IP');
check($authRow['fullname'] === '訓練 太郎' && $authRow['company'] === 'テスト社', '明細: 乱数→対象者情報(氏名/会社)を結合');
check($authRow['duplicate'] === true && $authRow['duplicate_count'] === 3, '明細: 同一乱数3件で重複フラグ');

// タイプフィルタ(link_click のみ)。
$_GET = ['action' => 'training_log_detail', 'campaign_id' => (string) $cid, 'type' => 'link_click'];
$clickRows = training_log_detail_rows($tenantId);
check(count($clickRows) === 2, '明細タイプフィルタ: link_click = 2行');

// 期間フィルタ(09:58:30 以降 → click1件 + auth1件 = 2行、09:58:00のclickは除外)。
$_GET = ['action' => 'training_log_detail', 'campaign_id' => (string) $cid, 'start_date' => '2026-07-15 09:58:30'];
$periodRows = training_log_detail_rows($tenantId);
check(count($periodRows) === 2, '明細期間フィルタ: start以降で2行');

// 他テナントのキャンペーンでは 0 件(テナント分離)。
if ($otherTid !== null) {
    $_GET = ['action' => 'training_log_detail'];
    $otherRows = training_log_detail_rows($otherTid);
    $leak = array_filter($otherRows, fn ($x) => $x['random'] === $rand);
    check(count($leak) === 0, '明細: 他テナントに乱数が漏れない(分離)');
}

// 明細のシステム開封除外と人間の n/m 回目。ip_cache にクラウド/実回線IPを注入して固定。
$cacheSys = weblog_load_ip_cache();
$cacheSys['203.0.113.50'] = ['country' => 'United States (US)', 'isp' => 'Microsoft Corporation', 'org' => 'Azure', 'as' => 'AS8075'];
$cacheSys['198.51.100.60'] = ['country' => 'Japan (JP)', 'isp' => 'NTT Docomo', 'org' => 'OCN', 'as' => 'AS4713'];
weblog_save_ip_cache($cacheSys);
$sysCid = 640;
Db::run("INSERT INTO campaigns (id, tenant_id, name, status) VALUES (?,?,?,?)", [$sysCid, $tenantId, 'System Filter Fixture', 'done']);
Db::run("INSERT INTO targets (id, tenant_id, email, name, status) VALUES (?,?,?,?,?)", [64001, $tenantId, 'sysfix@example.test', 'システム試験', 'active']);
Db::run("INSERT INTO campaign_targets (campaign_id, target_id, tracking_id, send_status) VALUES (?,?,?,?)", [$sysCid, 64001, '6400000001', 'sent']);
// 人間の click 2回(時間差) + システム(Azure)の click 1回。
$mk = fn ($ip, $t) => "$ip - - [$t] \"GET /link-6400000001.html HTTP/1.1\" 200 100 \"https://filesend.cojp.online/link-6400000001.html\" \"UA\"";
Db::run("INSERT INTO events (tenant_id, campaign_id, tracking_id, event_type, occurred_at, source, raw) VALUES (?,?,?,?,?,?,?)",
    [$tenantId, $sysCid, '6400000001', 'click', '2026-07-15 10:00:00', 'apache_access', $mk('198.51.100.60', '15/Jul/2026:10:00:00 +0900')]);
Db::run("INSERT INTO events (tenant_id, campaign_id, tracking_id, event_type, occurred_at, source, raw) VALUES (?,?,?,?,?,?,?)",
    [$tenantId, $sysCid, '6400000001', 'click', '2026-07-15 14:00:00', 'apache_access', $mk('198.51.100.60', '15/Jul/2026:14:00:00 +0900')]);
Db::run("INSERT INTO events (tenant_id, campaign_id, tracking_id, event_type, occurred_at, source, raw) VALUES (?,?,?,?,?,?,?)",
    [$tenantId, $sysCid, '6400000001', 'click', '2026-07-15 10:00:05', 'apache_access', $mk('203.0.113.50', '15/Jul/2026:10:00:05 +0900')]);

$_GET = ['action' => 'training_log_detail', 'campaign_id' => (string) $sysCid];
$sysDefault = training_log_detail_rows($tenantId);
check(count($sysDefault) === 2, '明細: 既定でシステム行を除外(人間click 2行のみ)');
check(count(array_filter($sysDefault, fn ($r) => $r['is_system'])) === 0, '明細: 既定の結果にシステム行が無い');
// 時刻昇順で 1/2, 2/2 回目が付く。
$byTime = $sysDefault;
usort($byTime, fn ($a, $b) => strcmp($a['timestamp'], $b['timestamp']));
check($byTime[0]['human_seq'] === 1 && $byTime[0]['human_total'] === 2, '明細: 人間の1回目/全2回');
check($byTime[1]['human_seq'] === 2 && $byTime[1]['human_total'] === 2, '明細: 人間の2回目/全2回');

$_GET = ['action' => 'training_log_detail', 'campaign_id' => (string) $sysCid, 'exclude_system' => '0'];
$sysAll = training_log_detail_rows($tenantId);
check(count($sysAll) === 3, '明細: exclude_system=0 で全3行(システム含む)');
check(count(array_filter($sysAll, fn ($r) => $r['is_system'])) === 1, '明細: システム行(Azure)が1行判定される');
// 開封回数の分母は人間のみ。システム1回が混在しても human_total は人間2回のまま
// (人間なのに 5/10 のようにシステム込みの分母にならないことを固定)。
foreach ($sysAll as $r) {
    if ($r['is_system']) {
        check($r['human_total'] === 0 && $r['human_seq'] === 0, '明細: システム行は開封回数を持たない(ht=0,seq=0)');
    } else {
        check($r['human_total'] === 2, '明細: 人間行の分母はシステムを含まず人間2回(1回のシステムを数えない)');
        check($r['human_seq'] >= 1 && $r['human_seq'] <= 2, '明細: 人間の通し番号は1..2(分母超えない)');
    }
}

// ケース: システムのみ(人間0回)。開封回数を付けず、既定表示では行ごと消える。
Db::run("INSERT INTO targets (id, tenant_id, email, name, status) VALUES (?,?,?,?,?)", [64002, $tenantId, 'sysonly@example.test', 'システムのみ', 'active']);
Db::run("INSERT INTO campaign_targets (campaign_id, target_id, tracking_id, send_status) VALUES (?,?,?,?)", [$sysCid, 64002, '6400000002', 'sent']);
Db::run("INSERT INTO events (tenant_id, campaign_id, tracking_id, event_type, occurred_at, source, raw) VALUES (?,?,?,?,?,?,?)",
    [$tenantId, $sysCid, '6400000002', 'click', '2026-07-15 09:00:00', 'apache_access', $mk('203.0.113.50', '15/Jul/2026:09:00:00 +0900')]);
Db::run("INSERT INTO events (tenant_id, campaign_id, tracking_id, event_type, occurred_at, source, raw) VALUES (?,?,?,?,?,?,?)",
    [$tenantId, $sysCid, '6400000002', 'click', '2026-07-15 09:00:05', 'apache_access', $mk('203.0.113.50', '15/Jul/2026:09:00:05 +0900')]);
// ケース: 人間3回。分母が3になり通し番号1..3。
Db::run("INSERT INTO targets (id, tenant_id, email, name, status) VALUES (?,?,?,?,?)", [64003, $tenantId, 'human3@example.test', '人間3回', 'active']);
Db::run("INSERT INTO campaign_targets (campaign_id, target_id, tracking_id, send_status) VALUES (?,?,?,?)", [$sysCid, 64003, '6400000003', 'sent']);
foreach (['08:00:00', '09:30:00', '11:00:00'] as $hhmmss) {
    Db::run("INSERT INTO events (tenant_id, campaign_id, tracking_id, event_type, occurred_at, source, raw) VALUES (?,?,?,?,?,?,?)",
        [$tenantId, $sysCid, '6400000003', 'click', "2026-07-15 {$hhmmss}", 'apache_access', $mk('198.51.100.60', "15/Jul/2026:{$hhmmss} +0900")]);
}

$_GET = ['action' => 'training_log_detail', 'campaign_id' => (string) $sysCid];
$sysDefault2 = training_log_detail_rows($tenantId);
$onlyRows = array_filter($sysDefault2, fn ($r) => $r['random'] === '6400000002');
check(count($onlyRows) === 0, '明細: システムのみの対象者は既定表示で0行(開封回数なし)');
$h3 = array_values(array_filter($sysDefault2, fn ($r) => $r['random'] === '6400000003'));
check(count($h3) === 3, '明細: 人間3回は3行');
foreach ($h3 as $r) {
    check($r['human_total'] === 3, '明細: 人間3回の分母は3');
}
$seqs = array_map(fn ($r) => $r['human_seq'], $h3);
sort($seqs);
check($seqs === [1, 2, 3], '明細: 人間3回の通し番号は1,2,3(重複なく連番)');

// 注: 同一 tracking_id・同一 event_type・同一秒の多重行は events の
// UNIQUE(tracking_id,event_type,occurred_at) 制約で発生し得ないため、
// human_seq の同一秒順序ズレは構造的に起きない(テスト不要)。

// weblog_ip_is_system 単体: needles(クラウド)は true、WARP/Relay/実回線は false、未解決は false。
check(weblog_ip_is_system('x', ['isp' => 'Microsoft Corporation', 'org' => 'Azure', 'as' => 'AS8075']) === true, 'is_system: Azure/Microsoft は true');
check(weblog_ip_is_system('x', ['isp' => 'Amazon.com, Inc.', 'org' => 'AWS', 'as' => 'AS16509']) === true, 'is_system: Amazon/AWS は true');
check(weblog_ip_is_system('x', ['isp' => 'NTT Docomo', 'org' => 'OCN', 'as' => 'AS4713']) === false, 'is_system: 実回線(NTT) は false');
check(weblog_ip_is_system('x', ['isp' => 'Cloudflare, Inc.', 'org' => 'Cloudflare WARP', 'as' => 'AS13335']) === false, 'is_system: Cloudflare WARP は人間(false)');
check(weblog_ip_is_system('x', ['isp' => 'Akamai', 'org' => 'iCloud Private Relay', 'as' => 'AS36183']) === false, 'is_system: iCloud Private Relay は人間(false)');
check(weblog_ip_is_system('x', null) === false, 'is_system: 未解決(info=null)は false(人間扱い)');
$_GET = [];

// ============================================================
// WebAccessLog — ログ行パース/フィルタ(実ファイル非依存)。
// ============================================================
$line = '203.0.113.9 - - [12/Apr/2026:16:31:05 +0900] "GET /tet2/api/x.php?a=1 HTTP/1.1" 200 12008 "https://ex/" "Mozilla/5.0 UA"';
$e = weblog_parse_line($line, null, null, null, null, 'access.log');
check($e !== null, 'weblog_parse_line: 正常行をパース');
check($e['ip'] === '203.0.113.9' && $e['method'] === 'GET' && $e['status'] === '200', 'weblog: IP/Method/Status 抽出');
check($e['path'] === '/tet2/api/x.php?a=1' && $e['size'] === '12008', 'weblog: Path/Size 抽出');
// Path フィルタ(不一致 → null)。
check(weblog_parse_line($line, null, null, '/other', null, 'access.log') === null, 'weblog: Path フィルタ不一致で除外');
check(weblog_parse_line($line, null, null, '/tet2', null, 'access.log') !== null, 'weblog: Path フィルタ一致で通過');
// 期間フィルタ(開始日時より前 → null)。
check(weblog_parse_line($line, '2026-04-13 00:00:00', null, null, null, 'access.log') === null, 'weblog: start_date より前は除外');
check(weblog_parse_line($line, '2026-04-01 00:00:00', '2026-04-30 00:00:00', null, null, 'access.log') !== null, 'weblog: 期間内は通過');
// 検索フィルタ(status/path/ip/method のいずれか)。
check(weblog_parse_line($line, null, null, null, 'zzznotfound', 'access.log') === null, 'weblog: 検索不一致で除外');
check(weblog_parse_line($line, null, null, null, '200', 'access.log') !== null, 'weblog: 検索(status)一致で通過');
// 不正行 → null。
check(weblog_parse_line('this is not a log line', null, null, null, null, 'x') === null, 'weblog: 非ログ行は null');
// プライベートIPの GeoIP(外部API不要)。
$priv = weblog_ip_info('192.168.1.1');
check($priv['country'] === 'Private/Local', 'weblog_ip_info: プライベートIPは Private/Local');
// バイト整形。
check(logs_format_bytes(0) === '0 B', 'logs_format_bytes: 0');
check(logs_format_bytes(1536) === '1.5 KB', 'logs_format_bytes: 1.5KB');
// 日付形式検証。
$dateOk = true;
try { weblog_validate_date('2026-04-12 16:31:05', 'x'); } catch (Tet2TestExit $ex) { $dateOk = false; }
check($dateOk, 'weblog_validate_date: 正常形式は通過');
$dateNg = false;
try { weblog_validate_date('bad', 'x'); } catch (Tet2TestExit $ex) { $dateNg = ($ex->httpCode === 400); }
check($dateNg, 'weblog_validate_date: 不正形式は 400');

// ハンドラ存在。
check(function_exists('weblog_handle_list') && function_exists('weblog_handle_csv'), 'WebAccessLog ハンドラが定義されている');
check(function_exists('logs_handle_training_log_geoip'), '訓練結果ログGeoIP補完ハンドラが定義されている');

// ============================================================
// 訓練結果ログの GeoIP 後追い補完(2026-08-30 追加)。
// click 行の国はキャッシュ(ip_cache.json)頼みで、未登録IPだと空欄になる。
// WebアクセスLog の「GeoIP取得」は superadmin 限定かつ access.log 由来の IP しか
// 対象にせず、訓練結果ログにだけ現れる IP を埋められなかったため専用actionを追加した。
// 外部APIを呼ばない範囲(=キャッシュ済みIPのみ)で、補完が効くことを検証する。
// ============================================================
$geoCache = weblog_load_ip_cache();
$geoCache['198.51.100.77'] = ['country' => 'Japan (JP)', 'location' => 'Tokyo',
    'isp' => 'GeoFixISP', 'org' => 'GeoFixOrg', 'as' => 'AS64999 GeoFix', 'hostname' => 'geofix.example.jp'];
weblog_save_ip_cache($geoCache);

// 補完前: キャッシュに無い IP は国が空(既存仕様の再確認)。
$beforeRaw = '203.0.113.231 - - [15/Jul/2026:11:00:00 +0900] "GET /link-1234567890.html HTTP/1.1" 200 100 "-" "UA"';
$before = training_log_parse_raw($beforeRaw);
check($before['country'] === '', 'GeoIP補完: キャッシュ未登録IPは補完前に国が空');

// キャッシュに入れば同じ raw から国が埋まる(=ボタンで解決した後の状態)。
$geoCache2 = weblog_load_ip_cache();
$geoCache2['203.0.113.231'] = ['country' => 'Japan (JP)', 'location' => 'Osaka',
    'isp' => 'ResolvedISP', 'org' => 'ResolvedOrg', 'as' => 'AS65000', 'hostname' => 'resolved.example.jp'];
weblog_save_ip_cache($geoCache2);
$after = training_log_parse_raw($beforeRaw);
check($after['country'] === 'Japan (JP)', 'GeoIP補完: キャッシュ登録後は国が埋まる');
check($after['isp'] === 'ResolvedISP' && $after['location'] === 'Osaka', 'GeoIP補完: ISP/場所も同時に埋まる');

// weblog_enrich_geoip は $allowApi=false ならキャッシュのみ参照し外部通信しない。
// (訓練結果ログ一覧の表示経路がタイムアウトしないことの担保)
$enrichRows = [['ip' => '198.51.100.77'], ['ip' => '203.0.113.244']];
weblog_enrich_geoip($enrichRows, false);
check($enrichRows[0]['country'] === 'Japan (JP)', 'GeoIP補完: allowApi=false でもキャッシュ済みは埋まる');
check($enrichRows[1]['country'] === '', 'GeoIP補完: allowApi=false は未キャッシュIPに外部APIを使わない');
check(function_exists('logs_handle_reply_maildir_csv'), '返信者Maildir CSVハンドラが定義されている');

// ============================================================
// 返信者(Maildir パース) — 純粋関数の単体テスト。実 Maildir 非依存。
// ============================================================

// MIME ヘッダーデコード(件名の =?UTF-8?B?...?= / =?ISO-2022-JP?B?...?=)
$subjB64 = '=?UTF-8?B?' . base64_encode('返信テスト') . '?=';
check(decode_mime_header($subjB64) === '返信テスト', 'decode_mime_header: UTF-8 Base64 件名をデコード');
$mixed = 'Re: ' . '=?UTF-8?B?' . base64_encode('件名') . '?=';
check(decode_mime_header($mixed) === 'Re: 件名', 'decode_mime_header: プレフィクス付き混在をデコード');
check(decode_mime_header('plain subject') === 'plain subject', 'decode_mime_header: 非エンコード文字列はそのまま');

// テスト用 Maildir を temp に作成し、maildir_list / 本文抽出を検証。
$tmpMaildir = sys_get_temp_dir() . '/tet2_test_maildir_' . getmypid();
@mkdir($tmpMaildir . '/new', 0700, true);
@mkdir($tmpMaildir . '/cur', 0700, true);
$eml = "Date: Wed, 15 Jul 2026 10:00:00 +0900\r\n"
     . 'From: ' . '=?UTF-8?B?' . base64_encode('山田太郎') . "?= <yamada@example.com>\r\n"
     . "To: kunren@cojp.online\r\n"
     . 'Subject: ' . '=?UTF-8?B?' . base64_encode('自動応答: 訓練') . "?=\r\n"
     . "Content-Type: text/plain; charset=UTF-8\r\n"
     . "Content-Transfer-Encoding: base64\r\n"
     . "\r\n"
     . base64_encode("不在にしております。\nよろしくお願いします。") . "\r\n";
file_put_contents($tmpMaildir . '/new/1752541200.TEST.mail', $eml);

$mails = maildir_list($tmpMaildir);
check(count($mails) === 1, 'maildir_list: 1件取得');
check($mails[0]['from_email'] === 'yamada@example.com', 'maildir_list: From アドレス抽出');
check(strpos($mails[0]['from'], '山田太郎') !== false, 'maildir_list: From 表示名を MIME デコード');
check($mails[0]['subject'] === '自動応答: 訓練', 'maildir_list: 件名を MIME デコード');
check($mails[0]['date'] === '2026-07-15 10:00:00', 'maildir_list: Date を正規化');

// 本文抽出(reply_maildir_view): base64 本文を UTF-8 で取得。
$fname = $mails[0]['filename'];
// discover_maildirs は本番パスを見るため使わず、view ハンドラの本文抽出ロジックを
// 経路そのままで検証するには account 解決が必要。ここでは maildir_find_text_parts の
// 非マルチパート経路(=boundary無し)を parseEmailFile 済みの本文で確認する。
$raw = file_get_contents($tmpMaildir . '/new/' . $fname);
$he = strpos($raw, "\r\n\r\n");
$body = substr($raw, $he + 4);
$decoded = base64_decode(trim($body));
check(strpos($decoded, '不在にしております') !== false, 'reply_maildir 本文: base64 デコードで日本語本文が取れる');

// パースできないメールを一覧から落とさない (返信見落とし防止)。
// 本番では Postfix virtual(8) が 0600 で作ったファイルを www-data が読めず
// null になり、一覧から静かに消えていた。ここではヘッダー境界のない壊れた
// ファイルで同じ経路 (maildir_parse_headers が null) を通す。
file_put_contents($tmpMaildir . '/new/1752541201.BROKEN.mail', 'no-header-boundary');
$mails2 = maildir_list($tmpMaildir);
check(count($mails2) === 2, 'maildir_list: 読めないメールも一覧に残す');
$broken = null;
foreach ($mails2 as $m) {
    if ($m['filename'] === '1752541201.BROKEN.mail') { $broken = $m; }
}
check($broken !== null, 'maildir_list: 読めないメールが filename で特定できる');
check($broken['unreadable'] === true, 'maildir_list: 読めないメールに unreadable フラグが立つ');
check(strpos($broken['subject'], '権限不足') !== false, 'maildir_list: 読めない理由が件名に出る');
check(preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $broken['date']) === 1, 'maildir_list: 読めないメールも日時書式が正常系と揃う');
// 正常系には unreadable=false が入り、キー構造が揃う。
check($mails2[0]['unreadable'] === false || $mails2[1]['unreadable'] === false, 'maildir_list: 正常系は unreadable=false');

// 後片付け。
@unlink($tmpMaildir . '/new/1752541201.BROKEN.mail');
@unlink($tmpMaildir . '/new/' . $fname);
@rmdir($tmpMaildir . '/new');
@rmdir($tmpMaildir . '/cur');
@rmdir($tmpMaildir);

// 返信者のキャンペーン絞り込み: reply_maildir_campaign_from_addresses が
// campaigns.from_address と campaign_contents.from_address の両方を小文字集合で返す。
check(function_exists('reply_maildir_campaign_from_addresses'),
    'reply_maildir_campaign_from_addresses が定義されている');
$_GET = [];
check(reply_maildir_campaign_from_addresses() === null,
    'campaign_id 未指定なら null(絞らない)');
Db::run("INSERT INTO campaigns (id, tenant_id, name, status, from_address) VALUES
    (720, ?, 'Reply Filter Fixture', 'draft', 'Kanri@Gwin.gr.cojp.online')", [$tenantId]);
Db::run("INSERT INTO campaign_contents (campaign_id, content_no, from_address) VALUES
    (720, 1, 'health_kanri@gwin.gr.cojp.online')");
Db::run("INSERT INTO campaign_contents (campaign_id, content_no, from_address) VALUES (720, 2, '')");
$_GET = ['campaign_id' => '720'];
$froms = reply_maildir_campaign_from_addresses();
check(is_array($froms) && in_array('kanri@gwin.gr.cojp.online', $froms, true),
    '送信元アドレスを小文字化して返す');
check(in_array('health_kanri@gwin.gr.cojp.online', $froms, true),
    'campaign_contents 側の送信元も集める');
check(count($froms) === 2, '空の from_address は除外し重複なく集める');
// 送信元未設定のキャンペーンは空配列(=どの Maildir にも一致せず0件)。
Db::run("INSERT INTO campaigns (id, tenant_id, name, status, from_address) VALUES (721, ?, 'NoFrom', 'draft', '')", [$tenantId]);
$_GET = ['campaign_id' => '721'];
check(reply_maildir_campaign_from_addresses() === [], '送信元未設定なら空配列');
$_GET = [];

// Maildir アドレスとキャンペーン送信元の照合: 完全一致 → ローカルパート一致フォールバック。
check(function_exists('reply_maildir_email_matches'), 'reply_maildir_email_matches が定義されている');
check(reply_maildir_email_matches('kanri@gwin.gr.cojp.online', ['kanri@gwin.gr.cojp.online']) === true,
    '照合: 完全一致');
check(reply_maildir_email_matches('KANRI@Gwin.gr.cojp.online', ['kanri@gwin.gr.cojp.online']) === true,
    '照合: 大小無視で完全一致');
// 送信元 event-support@mail.cojp.online の返信が event-support@gwin.gr.cojp.online の Maildir に届くケース。
check(reply_maildir_email_matches('event-support@gwin.gr.cojp.online', ['event-support@mail.cojp.online']) === true,
    '照合: ドメイン違いでもローカルパート一致で拾う');
check(reply_maildir_email_matches('other@gwin.gr.cojp.online', ['kanri@gwin.gr.cojp.online']) === false,
    '照合: ローカルパートも違えば不一致');
check(reply_maildir_email_matches('kanri@a.com', ['keiri@b.com', 'kanri@c.com']) === true,
    '照合: 複数送信元のいずれかにローカルパート一致すれば真');

// 返信の下限=キャンペーン start_at。期間外(送信開始より前)の古いメールを落とすため。
check(function_exists('reply_maildir_campaign_start'), 'reply_maildir_campaign_start が定義されている');
$_GET = [];
check(reply_maildir_campaign_start() === null, 'campaign_id 未指定なら start は null');
Db::run("INSERT INTO campaigns (id, tenant_id, name, status, from_address, start_at, end_at) VALUES
    (730, ?, 'Window Fixture', 'done', 'kanri@gwin.gr.cojp.online', '2026-08-20 10:00', '2026-08-21 23:25')", [$tenantId]);
$_GET = ['campaign_id' => '730'];
check(reply_maildir_campaign_start() === '2026-08-20 10:00:00', 'start_at を Y-m-d H:i:s で返す');
// start_at 未設定のキャンペーンは null(下限なし)。
Db::run("INSERT INTO campaigns (id, tenant_id, name, status, from_address, start_at) VALUES
    (731, ?, 'NoStart', 'draft', 'kanri@gwin.gr.cojp.online', '')", [$tenantId]);
$_GET = ['campaign_id' => '731'];
check(reply_maildir_campaign_start() === null, 'start_at 未設定なら null(下限なし)');
$_GET = [];

// ロールゲート(ディスパッチャ責務): reply_maildir は superadmin 限定。
// ディスパッチャは logs.php の try ブロック内なので、ここでは関数の存在のみ確認。
check(function_exists('logs_handle_reply_maildir'), 'reply_maildir ハンドラが定義されている');
check(function_exists('logs_handle_reply_maildir_view'), 'reply_maildir_view ハンドラが定義されている');
check(function_exists('discover_maildirs'), 'discover_maildirs が定義されている');

echo "ALL TESTS PASSED\n";

// WP1-b: 報告の明細/集計/列順と人間アクセス回数を固定。
Db::run("INSERT INTO events(tenant_id,campaign_id,tracking_id,event_type,occurred_at,source,raw) VALUES(?,?,?,'report',?,'report_mail',?)",
    [$tenantId, $cid, $rand, '2026-08-16 10:00:00', '{"subject_head":"IP: 203.0.113.50 | ISP: Microsoft Corporation"}']);
$_GET = ['campaign_id'=>(string)$cid];
$withReport = training_log_detail_rows($tenantId);
$reportRows = array_values(array_filter($withReport, static fn(array $r):bool=>$r['type']==='report'));
check(count($reportRows)===1 && $reportRows[0]['report']==='2026-08-16 10:00:00', '報告行に受信日時を表示');
check($reportRows[0]['ip']==='' && $reportRows[0]['human_total']===0, '報告の件名をアクセスログとして解釈せずアクセス回数にも含めない');
$table = training_log_detail_table_rows($reportRows);
check(count($table[0])===22 && $table[0][21]==='2026-08-16 10:00:00', '明細の末尾22列目が報告');
$resultRows = training_results_rows($tenantId);
check(count($resultRows[0])===12 && $resultRows[0][11]==='○', '対象者集計の末尾12列目に報告あり');
$_GET = ['campaign_id'=>(string)$cid, 'type'=>'report'];
check(count(training_log_detail_rows($tenantId))===1, 'reportでタイプを絞り込める');
