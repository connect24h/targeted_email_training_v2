<?php
declare(strict_types=1);

/**
 * ビーコン明細(ユーザ×コンテンツ)の行生成テスト。
 * report_beacon_rows が tracking_id 単位で対象者×コンテンツを1行にし、
 * 開封/クリック/認証の有無と初回時刻を出すこと、Excel シート変換が○/空になることを固定する。
 */

require_once __DIR__ . '/helpers.php';
tet2_test_boot();
load_api('report');

$tenantId = (int) current_user()['tenant_id'];

Db::run("INSERT INTO campaigns (tenant_id, name, status) VALUES (?, 'BEACON_T', 'done')", [$tenantId]);
$campaignId = (int) Db::one('SELECT id FROM campaigns WHERE tenant_id=? AND name=? ORDER BY id DESC LIMIT 1',
    [$tenantId, 'BEACON_T'])['id'];

// 対象者2名(うち1名はテスト)。
Db::run("INSERT INTO targets (tenant_id, email, name, company, department, position_category, is_test)
         VALUES (?,?,?,?,?,?,0)", [$tenantId, 'u1@t.test', '山田太郎', 'A社', '営業部', '一般']);
$t1 = (int) Db::one('SELECT id FROM targets WHERE email=?', ['u1@t.test'])['id'];
Db::run("INSERT INTO targets (tenant_id, email, name, company, department, position_category, is_test)
         VALUES (?,?,?,?,?,?,1)", [$tenantId, 'u2@t.test', 'テスト花子', 'B社', '検証部', '管理職']);
$t2 = (int) Db::one('SELECT id FROM targets WHERE email=?', ['u2@t.test'])['id'];

// u1 はコンテンツ1と2(別 tracking_id)、u2 はコンテンツ1のみ。
$mkct = static function (int $campaignId, int $targetId, string $tid, int $contentNo) {
    Db::run("INSERT INTO campaign_targets (campaign_id, target_id, tracking_id, koban, send_status, content_no)
             VALUES (?,?,?,?, 'sent', ?)", [$campaignId, $targetId, $tid, $tid, $contentNo]);
};
$mkct($campaignId, $t1, 'TID_A', 1);
$mkct($campaignId, $t1, 'TID_B', 2);
$mkct($campaignId, $t2, 'TID_C', 1);

// イベント: TID_A は open+click、TID_C は open のみ。TID_B は無反応。
$ev = static function (int $tenantId, int $campaignId, string $tid, string $type, string $at) {
    Db::run("INSERT INTO events (tenant_id, campaign_id, tracking_id, event_type, occurred_at, source, verdict)
             VALUES (?,?,?,?,?, 'apache_access', 'user')", [$tenantId, $campaignId, $tid, $type, $at]);
};
$ev($tenantId, $campaignId, 'TID_A', 'open', '2026-11-05 10:00:00');
$ev($tenantId, $campaignId, 'TID_A', 'click', '2026-11-05 10:01:00');
$ev($tenantId, $campaignId, 'TID_C', 'open', '2026-11-05 11:00:00');

$rows = report_beacon_rows($campaignId, $tenantId);
check(count($rows) === 3, 'ビーコン明細は tracking_id ごとに1行(3行)');

$byTid = [];
foreach ($rows as $r) { $byTid[$r['tracking_id']] = $r; }

check($byTid['TID_A']['opened'] === true && $byTid['TID_A']['clicked'] === true
    && $byTid['TID_A']['authed'] === false, 'TID_A は開封○クリック○認証×');
check($byTid['TID_A']['content_no'] === 1 && $byTid['TID_A']['name'] === '山田太郎',
    'TID_A は山田太郎・コンテンツ1');
check($byTid['TID_A']['opened_at'] === '2026-11-05 10:00:00', 'TID_A の開封初回時刻');
check($byTid['TID_B']['opened'] === false && $byTid['TID_B']['content_no'] === 2,
    'TID_B は無反応・コンテンツ2');
check($byTid['TID_C']['opened'] === true && $byTid['TID_C']['is_test'] === 1,
    'TID_C は開封○・テスト対象者');

// Excel シート変換: ○/空、テスト列。
$sheet = report_beacon_sheet_rows($rows);
check(count($sheet) === 3, 'シート行も3行');
$headers = report_beacon_sheet_headers();
check($headers[5] === 'コンテンツNo' && $headers[7] === '開封' && $headers[13] === 'テスト',
    'シートのヘッダ順(コンテンツNo/開封/テスト)');
// TID_A の行を探す(氏名=山田太郎 かつ コンテンツ1)。
$aRow = null;
foreach ($sheet as $row) { if ($row[0] === '山田太郎' && $row[5] === '1') { $aRow = $row; } }
check($aRow !== null && $aRow[7] === '○' && $aRow[8] === '○' && $aRow[9] === '',
    'TID_A のシート行: 開封○ クリック○ 認証空');
// テスト対象者の行にテスト列が立つ。
$testMarked = false;
foreach ($sheet as $row) { if ($row[1] === 'u2@t.test') { $testMarked = ($row[13] === 'テスト'); } }
check($testMarked, 'テスト対象者の行にテスト表記');

echo "\n✅ report_beacon_rows_test 完了\n";
