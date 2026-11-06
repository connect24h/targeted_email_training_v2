<?php
declare(strict_types=1);

/**
 * 送信アラートの「クリア」で履歴が消えず、send_alerts_history.json へ退避されることを固定する。
 * 依頼(2026-11-06): クリアしても後から履歴が見えること。
 * send_email.py(v1共用)は不変。退避・履歴取得は Web(send_control.php)側だけで行う。
 */

require_once __DIR__ . '/helpers.php';
tet2_test_boot();
load_api('send_control');

check(function_exists('send_control_append_history'), 'send_control_append_history が定義されている');

$dir = sys_get_temp_dir() . '/sc_hist_' . getmypid() . '_' . random_int(1000, 9999);
@mkdir($dir, 0700, true);
register_shutdown_function(static function () use ($dir) {
    foreach (glob($dir . '/*') ?: [] as $f) { @unlink($f); }
    @rmdir($dir);
});

$alertsPath = $dir . '/send_alerts.json';
$histPath = $dir . '/send_alerts_history.json';

// 現役アラート2件を置く。
file_put_contents($alertsPath, json_encode([
    ['type' => 'delivery_delay', 'message' => '遅延A', 'timestamp' => '2026-11-06 10:00:00'],
    ['type' => 'send_failure', 'message' => '失敗B', 'timestamp' => '2026-11-06 10:05:00'],
], JSON_UNESCAPED_UNICODE));

// 1回目の退避: 現役2件が履歴へ移る。
$moved = send_control_append_history($dir);
check($moved === 2, '1回目の退避で2件が履歴へ移る (実際: ' . var_export($moved, true) . ')');

$hist = json_decode((string) file_get_contents($histPath), true);
check(is_array($hist) && count($hist) === 2, '履歴ファイルに2件');
check(isset($hist[0]['cleared_at']) && $hist[0]['cleared_at'] !== '', '各履歴に cleared_at が付く');
// 元メッセージが保持される。
$msgs = array_map(static fn ($a) => $a['message'] ?? '', $hist);
check(in_array('遅延A', $msgs, true) && in_array('失敗B', $msgs, true), '履歴に元メッセージが残る');

// 退避後、現役 send_alerts.json は空(=[] か不在)になる。
// (空化は unlink→'[]'再作成方式。training所有で上書き不可なファイルでも効くようにした)
$cur = is_file($alertsPath) ? json_decode((string) file_get_contents($alertsPath), true) : [];
check($cur === [] || $cur === null, '退避後、現役アラートは空');
check(is_file($alertsPath) && trim((string) file_get_contents($alertsPath)) === '[]',
    '退避後、現役ファイルは [] に再作成される(unlink→再作成方式)');

// 2回目: 新たな現役3件を置いて退避 → 履歴は 2+3=5 件に追記される(全件保持)。
file_put_contents($alertsPath, json_encode([
    ['type' => 'send_failure', 'message' => '失敗C', 'timestamp' => '2026-11-06 11:00:00'],
    ['type' => 'delivery_delay', 'message' => '遅延D', 'timestamp' => '2026-11-06 11:01:00'],
    ['type' => 'send_failure', 'message' => '失敗E', 'timestamp' => '2026-11-06 11:02:00'],
], JSON_UNESCAPED_UNICODE));
$moved2 = send_control_append_history($dir);
check($moved2 === 3, '2回目の退避で3件');
$hist2 = json_decode((string) file_get_contents($histPath), true);
check(is_array($hist2) && count($hist2) === 5, '履歴は累計5件(全件保持)');

// 現役が空(退避すべきものが無い)なら 0 件で履歴も増えない。
$moved3 = send_control_append_history($dir);
check($moved3 === 0, '現役が空なら退避0件');
$hist3 = json_decode((string) file_get_contents($histPath), true);
check(count($hist3) === 5, '空退避で履歴は増えない(5件のまま)');

// --- 重複排除: 既に履歴にある同一アラートは再退避しても追記されない ---
// 既出の「失敗C」(timestamp 2026-11-06 11:00:00)と同じものを現役に置いて退避。
file_put_contents($alertsPath, json_encode([
    ['type' => 'send_failure', 'message' => '失敗C', 'timestamp' => '2026-11-06 11:00:00'], // 既出(重複)
    ['type' => 'send_failure', 'message' => '失敗F', 'timestamp' => '2026-11-06 12:00:00'], // 新規
], JSON_UNESCAPED_UNICODE));
$moved4 = send_control_append_history($dir);
check($moved4 === 1, '重複1・新規1 のうち新規1件だけ退避される (実際: ' . var_export($moved4, true) . ')');
$hist4 = json_decode((string) file_get_contents($histPath), true);
check(count($hist4) === 6, '履歴は6件(既存5＋新規1。重複は積まない)');
// 「失敗F」が入り、「失敗C」は増えていない(1つのまま)。
$countC = count(array_filter($hist4, static fn ($a) => ($a['message'] ?? '') === '失敗C'));
$countF = count(array_filter($hist4, static fn ($a) => ($a['message'] ?? '') === '失敗F'));
check($countC === 1 && $countF === 1, '失敗Cは1件のまま・失敗Fが1件追加');

echo "\n✅ send_control_alert_history_test 完了\n";
