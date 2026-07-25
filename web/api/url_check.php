<?php declare(strict_types=1); require __DIR__."/../lib/bootstrap.php";

/**
 * P6: URL 疎通確認 API。
 * キャンペーンのビーコンベース URL(IP/ドメイン)が到達可能かを確認する。
 * HTTP HEAD をタイムアウト付きで送り、HTTP ステータスと到達可否のみを返す。
 * レスポンス本文は取得しない(SSRF での情報吸い出しを避けるため)。operator 以上限定。
 */

/**
 * SSRF 対策(OWASP A10): URL のホストを IP 解決し、内部アドレス(ループバック/
 * プライベート/リンクローカル/予約)なら false を返す。公開アドレスのみ true。
 * http/https 以外・パース不能・ホストなしは fail-closed で false。
 * 戻り値が true のとき、$resolvedIps に検証済み IP を格納する(curl 固定接続用)。
 */
function url_check_is_safe_host(string $url, ?array &$resolvedIps = null): bool
{
    $resolvedIps = [];
    $scheme = parse_url($url, PHP_URL_SCHEME);
    if ($scheme !== 'http' && $scheme !== 'https') {
        return false;
    }
    $host = parse_url($url, PHP_URL_HOST);
    if (!is_string($host) || $host === '') {
        return false;
    }
    // [::1] 形式の IPv6 リテラルは括弧を外す。
    $host = trim($host, '[]');

    // ホストが IP リテラルならそれを、ドメインなら A/AAAA を全件解決して検査。
    $ips = [];
    if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
        $ips[] = $host;
    } else {
        foreach (dns_get_record($host, DNS_A + DNS_AAAA) ?: [] as $rec) {
            if (isset($rec['ip'])) { $ips[] = $rec['ip']; }
            if (isset($rec['ipv6'])) { $ips[] = $rec['ipv6']; }
        }
        if ($ips === []) {
            $g = gethostbyname($host); // A のみのフォールバック
            if ($g !== $host) { $ips[] = $g; }
        }
        if ($ips === []) {
            return false; // 解決できない = fail-closed
        }
    }
    // 1つでも内部/予約アドレスがあれば拒否(DNS リバインディングの複数解決対策)。
    foreach ($ips as $ip) {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
    }
    $resolvedIps = $ips;
    return true;
}

function url_check_handle(): never
{
    require_role('operator');
    tet2_require_csrf();
    $body = json_body();
    $url = isset($body['url']) && is_string($body['url']) ? trim($body['url']) : '';
    if ($url === '') {
        json_error('url は必須です', 400);
    }
    if (!preg_match('#^https?://[^\s/][^\s]*$#', $url)) {
        json_error('url は http:// または https:// で始まる URL を指定してください', 400);
    }
    // SSRF 対策: 内部アドレス(ループバック/プライベート/メタデータ等)を拒否。
    $resolvedIps = [];
    if (!url_check_is_safe_host($url, $resolvedIps)) {
        json_error('内部アドレスや解決できないホストは指定できません', 400);
    }

    // 検証済み IP を curl に固定し、curl 側での再解決(DNS リバインディング)を防ぐ。
    $curlOpts = [
        CURLOPT_URL => $url,
        CURLOPT_NOBODY => true,          // HEAD リクエスト(本文を取らない)
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false, // リダイレクトを追わない(SSRF 経路拡大を防ぐ)
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => false, // 訓練用途で自己署名/IP直の証明書不一致を許容
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_USERAGENT => 'TET2-URLCheck/1.0',
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, // file:// 等を明示排除
    ];
    $host = trim((string) parse_url($url, PHP_URL_HOST), '[]');
    $port = parse_url($url, PHP_URL_PORT);
    if (!filter_var($host, FILTER_VALIDATE_IP) && $resolvedIps !== []) {
        $p = $port !== null ? (int) $port : (parse_url($url, PHP_URL_SCHEME) === 'https' ? 443 : 80);
        $curlOpts[CURLOPT_RESOLVE] = ["{$host}:{$p}:" . implode(',', $resolvedIps)];
    }
    $ch = curl_init();
    curl_setopt_array($ch, $curlOpts);
    $ok = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $totalTime = round((float) curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000); // ms
    $errno = curl_errno($ch);
    $errmsg = curl_error($ch);
    curl_close($ch);

    // 到達 = curl が成功し、HTTP ステータスが返った(接続確立+応答受信)。
    // 4xx/5xx でも「サーバに届いた」ので reachable=true とし、status で判断させる。
    $reachable = ($ok !== false && $httpCode > 0);

    json_out([
        'success' => true,
        'reachable' => $reachable,
        'http_status' => $httpCode,
        'response_ms' => $totalTime,
        'error' => $reachable ? null : ($errmsg !== '' ? $errmsg : 'no response'),
        'errno' => $errno,
    ]);
}

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method !== 'POST') {
        json_error('不正なアクション', 400);
    }
    url_check_handle();
} catch (Throwable $e) {
    error_log($e->getMessage());
    json_error('サーバエラー', 500);
}
