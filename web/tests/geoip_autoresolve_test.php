<?php
declare(strict_types=1);

/**
 * lib/GeoIpCache.php の自動取込ヘルパーのテスト。
 * - weblog_collect_unresolved_click_ips: 直近 N 日の click(apache_access)IP だけを集める。
 *   open/auth/古い行は対象外。キャッシュ済み(country!=Unknown)は除外。
 * - weblog_autoresolve_recent_click_ips: 上限が効く。外部通信はプライベートIPで避ける。
 * events を直接 INSERT して条件を固定する(report_ingest 全体は走らせない)。
 */

require_once __DIR__ . '/helpers.php';

// キャッシュ先を一時ファイルに固定してから GeoIpCache を読み込む(define は一度きり)。
$tmpCache = tempnam(sys_get_temp_dir(), 'ipcache_auto_') ?: (sys_get_temp_dir() . '/ipcache_auto_test.json');
putenv('TET2_IP_CACHE=' . $tmpCache);
@unlink($tmpCache);
register_shutdown_function(static fn () => @unlink($tmpCache));

tet2_test_boot();
require_once __DIR__ . '/../lib/GeoIpCache.php';

/** combined ログ1行を組み立てる(先頭が IP)。 */
function geoauto_line(string $ip, string $path): string
{
    return "{$ip} - - [04/Nov/2026:10:00:00 +0900] \"GET {$path} HTTP/1.1\" 200 100 \"-\" \"UA\"";
}

function geoauto_event(int $tid, string $type, string $source, string $occurred, string $raw): void
{
    Db::run(
        'INSERT INTO events (tenant_id, campaign_id, tracking_id, event_type, occurred_at, source, raw)
         VALUES (1, 1, ?, ?, ?, ?, ?)',
        [(string) $tid, $type, $occurred, $source, $raw]
    );
}

$today = date('Y-m-d H:i:s');
$old   = date('Y-m-d H:i:s', strtotime('-40 days'));

// 直近の click(プライベートIP。外部通信なし)。
geoauto_event(1000000001, 'click', 'apache_access', $today, geoauto_line('10.1.1.1', '/link-1000000001.html'));
geoauto_event(1000000002, 'click', 'apache_access', $today, geoauto_line('10.1.1.2', '/link-1000000002.html'));
// 同じ IP の2件目(ユニーク化で1つに畳まれる)。
geoauto_event(1000000003, 'click', 'apache_access', $today, geoauto_line('10.1.1.1', '/link-1000000003.html'));
// 40日前の click(範囲外)。
geoauto_event(1000000004, 'click', 'apache_access', $old, geoauto_line('10.9.9.9', '/link-1000000004.html'));
// open と auth(対象外)。
geoauto_event(1000000005, 'open', 'apache_access', $today, geoauto_line('10.2.2.2', '/kunren-beacon-1000000005.png'));
geoauto_event(1000000006, 'auth', 'text_log', $today, 'Random: 1000000006 | IP: 10.3.3.3 | Country: X');

// --- 収集: 直近 click の 10.1.1.1 / 10.1.1.2 の2件だけ ---
$need = weblog_collect_unresolved_click_ips(30);
sort($need);
check($need === ['10.1.1.1', '10.1.1.2'], '直近 click の IP だけをユニークに集める(open/auth/古い行は除外)');

// --- キャッシュ済みは除外 ---
weblog_save_ip_cache(['10.1.1.1' => ['country' => 'Japan (JP)']]);
$need2 = weblog_collect_unresolved_click_ips(30);
check($need2 === ['10.1.1.2'], 'キャッシュ済み(country!=Unknown)は収集から除外される');

// --- Unknown は再収集対象 ---
weblog_save_ip_cache(['10.1.1.1' => ['country' => 'Unknown'], '10.1.1.2' => ['country' => 'United States (US)']]);
$need3 = weblog_collect_unresolved_click_ips(30);
check($need3 === ['10.1.1.1'], 'country=Unknown は再収集対象(10.1.1.1 が戻る)');

// --- 自動解決: プライベートIPは即 Private/Local で解決され、外部通信なし ---
@unlink($tmpCache);
$res = weblog_autoresolve_recent_click_ips(40, 30);
check($res['target'] === 2, '要解決は2件(10.1.1.1 / 10.1.1.2)');
check($res['resolved'] === 2, 'プライベート2件が解決される(外部通信なし)');
check($res['save_failed'] === false, '保存は成功(save_failed=false)');
$cache = weblog_load_ip_cache();
check(($cache['10.1.1.1']['country'] ?? '') === 'Private/Local', '10.1.1.1 がキャッシュに入る');

// --- 上限($limit)が効く ---
@unlink($tmpCache);
$res2 = weblog_autoresolve_recent_click_ips(1, 30);
check($res2['target'] === 2 && $res2['resolved'] === 1, '$limit=1 で解決は1件に制限される');

// --- 対象ゼロ時は何もしない ---
@unlink($tmpCache);
weblog_save_ip_cache(['10.1.1.1' => ['country' => 'JP'], '10.1.1.2' => ['country' => 'JP']]);
$res3 = weblog_autoresolve_recent_click_ips(40, 30);
check($res3['target'] === 0 && $res3['resolved'] === 0 && $res3['save_failed'] === false,
    '要解決ゼロなら resolved=0・save_failed=false');

echo "\n✅ geoip_autoresolve_test 完了\n";
