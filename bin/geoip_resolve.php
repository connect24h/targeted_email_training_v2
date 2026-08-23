<?php
/**
 * 訓練の反応(ビーコン表示 kunren-beacon-*.png / クリック link-*.html)を起こした
 * ユニークIPだけを ip-api.com で解決し、WebアクセスLog画面と同じ data/ip_cache.json に
 * 書き込む CLI バッチ(2026-08-23)。
 *
 * 目的: WebアクセスLog画面の国/ISPが空欄(=キャッシュ未解決)のまま残るのを一括で解消する。
 * access.log 全体(数万IP)は対象にしない。訓練イベント元IPのみ(数百件)。
 * Bot自動除外はしない — 海外IPが正規社員(グローバル企業)か装置かの判定は人間が行う。
 *
 * 使い方:
 *   php bin/geoip_resolve.php            # 訓練反応IPの未解決分をすべて解決
 *   php bin/geoip_resolve.php --dry-run  # 対象IP数だけ表示(API呼び出しなし)
 *   php bin/geoip_resolve.php --limit=N  # 今回はN件だけ解決(分割実行)
 *
 * ip-api.com 無料枠は 45req/分。既定 100ms スリープで概ね収まる。
 */
declare(strict_types=1);

require_once __DIR__ . '/../web/lib/GeoIpCache.php';

$dryRun = in_array('--dry-run', $argv, true);
$limit = 0;
foreach ($argv as $a) {
    if (preg_match('/^--limit=(\d+)$/', $a, $m)) { $limit = (int) $m[1]; }
}

$logDir = '/var/log/apache2';
$files = [];
foreach (["{$logDir}/access.log", "{$logDir}/access.log.1"] as $p) {
    if (is_readable($p)) { $files[] = $p; }
}
foreach (glob("{$logDir}/access.log.*.gz") ?: [] as $g) {
    if (is_readable($g)) { $files[] = $g; }
}

$pattern = '/(kunren-beacon-\d{10}\.png|link-\d{10}\.html)/';
$ipRe = '/^(\S+)\s/';
$ips = [];
foreach ($files as $file) {
    $fp = str_ends_with($file, '.gz') ? gzopen($file, 'r') : fopen($file, 'r');
    if ($fp === false) { continue; }
    $read = str_ends_with($file, '.gz') ? 'gzgets' : 'fgets';
    while (($line = $read($fp)) !== false) {
        if (!preg_match($pattern, $line)) { continue; }
        if (!preg_match($ipRe, $line, $m)) { continue; }
        $ip = $m[1];
        if (filter_var($ip, FILTER_VALIDATE_IP) !== false) { $ips[$ip] = true; }
    }
    str_ends_with($file, '.gz') ? gzclose($fp) : fclose($fp);
}
$ips = array_keys($ips);

$cache = weblog_load_ip_cache();
$need = array_values(array_filter($ips, static function (string $ip) use ($cache): bool {
    return !isset($cache[$ip]) || ($cache[$ip]['country'] ?? '') === 'Unknown';
}));

fwrite(STDOUT, sprintf(
    "訓練反応ユニークIP: %d / キャッシュ済み: %d / 要解決: %d\n",
    count($ips), count($cache), count($need)
));

if ($dryRun) {
    fwrite(STDOUT, "--dry-run のため解決は行いません。\n");
    exit(0);
}
if (count($need) === 0) {
    fwrite(STDOUT, "解決が必要なIPはありません(すべてキャッシュ済み)。\n");
    exit(0);
}

$eta = $limit > 0 ? min($limit, count($need)) : count($need);
fwrite(STDOUT, sprintf("解決を開始します(%d件, 概ね %.1f分)…\n", $eta, $eta / 40.0));
// 進捗を見えるようにするため50件ずつ分割して回す(各バッチ後にログと再保存)。
$calls = 0;
$batch = 50;
$remaining = $need;
$prevRemaining = count($remaining) + 1; // 初回は必ず「減った」扱い
while (count($remaining) > 0) {
    $slice = $limit > 0 ? array_slice($remaining, 0, min($batch, $limit - $calls)) : array_slice($remaining, 0, $batch);
    if (count($slice) === 0) { break; }
    $c = weblog_resolve_ips($slice, 0);
    $calls += $c;
    // 解決済みを差し引いて残りを再計算(Unknownは保存されないので再試行対象に残る)。
    $cacheNow = weblog_load_ip_cache();
    $remaining = array_values(array_filter($need, static function (string $ip) use ($cacheNow): bool {
        return !isset($cacheNow[$ip]) || ($cacheNow[$ip]['country'] ?? '') === 'Unknown';
    }));
    fwrite(STDOUT, sprintf("[%s] 累計API %d件 / 残り %d件\n", date('H:i:s'), $calls, count($remaining)));
    if ($limit > 0 && $calls >= $limit) { break; }
    // 残りが1件も減らなかった(全部Unknownで解決不能)なら無限ループ回避で打ち切り。
    if (count($remaining) >= $prevRemaining) { break; }
    $prevRemaining = count($remaining);
}

$cache = weblog_load_ip_cache();
$stillUnknown = 0;
foreach ($ips as $ip) {
    if (!isset($cache[$ip]) || ($cache[$ip]['country'] ?? '') === 'Unknown') { $stillUnknown++; }
}
fwrite(STDOUT, sprintf("API呼び出し: %d件 / 未解決の残り: %d件\n", $calls, $stillUnknown));
exit(0);
