<?php
declare(strict_types=1);
/**
 * フィッシング訓練の反応ログ(Apache access.log)を定期取り込みする CLI スクリプト。
 * systemd timer (tet2-report-ingest.timer) から実行される。
 *
 * 開封(kunren-beacon-*.png) / クリック(link-*.html) / 認証(訓練ログ) を events に反映する。
 * これまで手動「ログ取込」ボタンでしか実行されず、反応が反映されない事故があったため自動化した。
 * access.log は root:adm 640。実行ユーザ(training)は adm グループ所属なので読める。
 */
require __DIR__ . '/../lib/Db.php';
require __DIR__ . '/../lib/EventIngest.php';
require __DIR__ . '/../lib/GeoIpCache.php';

$counts = EventIngest::ingestAll();
$ts = date('Y-m-d H:i:s');
echo "[{$ts}] ingest " . json_encode($counts, JSON_UNESCAPED_UNICODE) . "\n";

// 取込の後に、直近クリック IP の国/ISP をキャッシュへ自動解決する。
// これまでは画面の「GeoIP取得」ボタンを押すまで未解決 IP の明細が空欄のままだった。
// 外部 API 呼び出しや保存の失敗は取込結果に影響させない(例外は握ってログに出す)。
try {
    $geo = weblog_autoresolve_recent_click_ips(40, 30);
    $gts = date('Y-m-d H:i:s');
    echo "[{$gts}] geoip " . json_encode($geo, JSON_UNESCAPED_UNICODE) . "\n";
    if (!empty($geo['save_failed'])) {
        fwrite(STDERR, "[{$gts}] geoip save failed: ip_cache.json に書き込めません(権限/所有者を確認)\n");
    }
} catch (\Throwable $e) {
    $gts = date('Y-m-d H:i:s');
    fwrite(STDERR, "[{$gts}] geoip error: " . $e->getMessage() . "\n");
}
