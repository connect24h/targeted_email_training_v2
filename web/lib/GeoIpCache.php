<?php
/**
 * IP → 国/ISP 位置情報の解決とファイルキャッシュ。
 *
 * 元は api/logs.php 内に weblog_ip_info() / WEBLOG_IP_CACHE として実装されていたものを、
 * WebアクセスLog画面(明示refresh)とCLI一括解決(bin/geoip_resolve.php)の両方から
 * 同一ロジックを共有するために切り出した(2026-08-23)。
 *
 * 解決元は ip-api.com(無料HTTP・45req/分)。訓練の反応元が国内外どちらかを人手で
 * 分析するための情報であり、Bot自動除外には使わない(Goldwin はグローバル企業のため
 * 海外IP=Botと機械判定すると正規の反応を落とす。判定は人間が行う)。
 */
declare(strict_types=1);

// api/ の親(tet2/)配下の data/ に IP キャッシュを置く。
// CLI一括解決(bin/geoip_resolve.php)は本番の data/ を直接更新したいので、
// 環境変数 TET2_IP_CACHE で保存先を上書きできる(Web側は未設定=既定パス)。
if (!defined('WEBLOG_IP_CACHE')) {
    $envCache = getenv('TET2_IP_CACHE');
    define('WEBLOG_IP_CACHE', ($envCache !== false && $envCache !== '')
        ? $envCache
        : dirname(__DIR__) . '/data/ip_cache.json');
}

/** IPキャッシュを読む。 */
function weblog_load_ip_cache(): array
{
    $f = WEBLOG_IP_CACHE;
    if (is_file($f) && is_readable($f)) {
        $c = json_decode((string) file_get_contents($f), true);
        if (is_array($c)) { return $c; }
    }
    return [];
}

/** IPキャッシュを保存する。 */
function weblog_save_ip_cache(array $cache): void
{
    $dir = dirname(WEBLOG_IP_CACHE);
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    @file_put_contents(WEBLOG_IP_CACHE, json_encode($cache, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

/**
 * IP → 位置情報。プライベートIPは即返し、公開IPは ip-api.com(3秒timeout)で解決。
 * ip-api.com 無料枠(45req/分)超過時は HTTP 429 を返す。その場合 X-Ttl 秒待って
 * 最大 $maxRetry 回まで再試行する。API側の一時失敗を Unknown で確定させないため。
 * 恒久的に引けなかった場合のみ Unknown を返す(呼び出し側でキャッシュするか選べる)。
 */
function weblog_ip_info(string $ip, int $maxRetry = 3): array
{
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
        return ['country' => 'Private/Local', 'location' => 'Private Network', 'isp' => 'Private Network',
                'org' => 'Private Network', 'as' => '', 'hostname' => 'localhost'];
    }
    $url = "http://ip-api.com/json/{$ip}?fields=status,country,countryCode,regionName,city,isp,org,as,query";
    for ($attempt = 0; $attempt <= $maxRetry; $attempt++) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        curl_setopt($ch, CURLOPT_HEADER, true);
        $resp = curl_exec($ch);
        $err = curl_errno($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $hdrSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        if ($err || $resp === false) { break; }
        $headers = substr((string) $resp, 0, $hdrSize);
        $body = substr((string) $resp, $hdrSize);
        // レート制限: X-Ttl 秒待って再試行(最終試行なら諦める)。
        if ($code === 429) {
            if ($attempt >= $maxRetry) { break; }
            $ttl = 1;
            if (preg_match('/X-Ttl:\s*(\d+)/i', $headers, $mt)) { $ttl = max(1, (int) $mt[1]); }
            sleep($ttl + 1);
            continue;
        }
        $d = json_decode($body, true);
        if (is_array($d) && ($d['status'] ?? '') === 'success') {
            $country = $d['country'] ?? 'Unknown';
            $cc = $d['countryCode'] ?? '';
            $region = $d['regionName'] ?? '';
            $city = $d['city'] ?? '';
            $loc = trim("{$city}, {$region}");
            $loc = ($loc === '' || $loc === ', ') ? $country : $loc . ", {$country}";
            return ['country' => "{$country} ({$cc})", 'location' => $loc,
                    'isp' => $d['isp'] ?? 'Unknown', 'org' => $d['org'] ?? ($d['isp'] ?? 'Unknown'),
                    'as' => $d['as'] ?? '', 'hostname' => @gethostbyaddr($ip) ?: $ip];
        }
        // status=fail(私用IP以外の恒久失敗)は再試行しても変わらないので抜ける。
        break;
    }
    $hostname = @gethostbyaddr($ip);
    $org = 'Unknown';
    if ($hostname && $hostname !== $ip) {
        $parts = explode('.', $hostname);
        if (count($parts) >= 2) { $org = $parts[count($parts) - 2]; }
    }
    return ['country' => 'Unknown', 'location' => 'Unknown', 'isp' => 'Unknown',
            'org' => $org, 'as' => '', 'hostname' => $hostname ?: $ip];
}

/**
 * キャッシュに未解決(未登録 or country=Unknown)のIPだけを外部APIで解決してキャッシュへ書く。
 * $ips のうち実際にAPIを叩いた件数を返す。$limit<=0 で無制限。
 *
 * ip-api.com 無料枠(45req/分)を超えないよう既定1.5秒(=40/分)間隔で叩く。429 の待機は
 * weblog_ip_info 側が担う。長時間バッチが途中で死んでも成果を失わないよう、$flushEvery
 * 件ごとにキャッシュを保存する(中断耐性)。恒久失敗(Unknown)もキャッシュせず次回再試行できる
 * よう、country=Unknown の結果は保存しない。
 */
function weblog_resolve_ips(array $ips, int $limit = 0, int $sleepUs = 1500000, int $flushEvery = 10): int
{
    $cache = weblog_load_ip_cache();
    $calls = 0;
    $sinceFlush = 0;
    foreach ($ips as $ip) {
        if ($ip === '' || $ip === 'unknown') { continue; }
        $needs = !isset($cache[$ip]) || ($cache[$ip]['country'] ?? '') === 'Unknown';
        if (!$needs) { continue; }
        if ($limit > 0 && $calls >= $limit) { break; }
        $info = weblog_ip_info($ip);
        $calls++;
        // 解決できたものだけ保存。Unknown(=API一時失敗/恒久失敗)は残さず次回に回す。
        if (($info['country'] ?? 'Unknown') !== 'Unknown') {
            $cache[$ip] = $info;
            if (++$sinceFlush >= $flushEvery) { weblog_save_ip_cache($cache); $sinceFlush = 0; }
        }
        if ($sleepUs > 0) { usleep($sleepUs); }
    }
    if ($sinceFlush > 0) { weblog_save_ip_cache($cache); }
    return $calls;
}
