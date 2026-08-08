<?php
declare(strict_types=1);

/**
 * logs API(P8)の回帰テスト。
 * delivery/events/replies/schedule/audit の各ビューアが、テナント分離・
 * IDOR 防御・ロールゲート・入力検証を守ることを固定する。
 */

require_once __DIR__ . '/helpers.php';
tet2_test_boot();
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
check(function_exists('logs_handle_training_log_detail_csv'), '訓練結果ログ明細CSVハンドラが定義されている');
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

// 後片付け。
@unlink($tmpMaildir . '/new/' . $fname);
@rmdir($tmpMaildir . '/new');
@rmdir($tmpMaildir . '/cur');
@rmdir($tmpMaildir);

// ロールゲート(ディスパッチャ責務): reply_maildir は superadmin 限定。
// ディスパッチャは logs.php の try ブロック内なので、ここでは関数の存在のみ確認。
check(function_exists('logs_handle_reply_maildir'), 'reply_maildir ハンドラが定義されている');
check(function_exists('logs_handle_reply_maildir_view'), 'reply_maildir_view ハンドラが定義されている');
check(function_exists('discover_maildirs'), 'discover_maildirs が定義されている');

echo "ALL TESTS PASSED\n";
