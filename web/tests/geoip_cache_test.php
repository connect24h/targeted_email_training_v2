<?php
declare(strict_types=1);

/**
 * lib/GeoIpCache.php のテスト。外部API(ip-api.com)には依存させない。
 * - プライベートIPは即 Private/Local を返す(通信しない)
 * - TET2_IP_CACHE 環境変数でキャッシュ保存先を差し替えられる
 * - weblog_resolve_ips: キャッシュ済み(country!=Unknown)は再解決しない/上限が効く
 *   → プライベートIPだけを渡し、外部通信を発生させずに件数ロジックを固定する
 */

require_once __DIR__ . '/helpers.php';

// キャッシュ先を一時ファイルに固定してから GeoIpCache を読み込む(define は一度きり)。
$tmpCache = tempnam(sys_get_temp_dir(), 'ipcache_') ?: (sys_get_temp_dir() . '/ipcache_test.json');
putenv('TET2_IP_CACHE=' . $tmpCache);
@unlink($tmpCache); // tempnam が作る空ファイルを消し「未作成」状態から始める
require_once __DIR__ . '/../lib/GeoIpCache.php';

check(WEBLOG_IP_CACHE === $tmpCache, 'TET2_IP_CACHE でキャッシュ先を差し替えられる');

// --- プライベートIPは通信せず即返し ---
$priv = weblog_ip_info('10.0.0.5');
check($priv['country'] === 'Private/Local', 'プライベートIP(10.x)は Private/Local(通信なし)');
$priv2 = weblog_ip_info('192.168.10.10');
check($priv2['isp'] === 'Private Network', 'プライベートIP(192.168.x)は Private Network');

// --- キャッシュ未作成なら空配列 ---
check(weblog_load_ip_cache() === [], 'キャッシュ未作成時は空配列');

// --- 保存 → 読み戻し ---
weblog_save_ip_cache(['1.2.3.4' => ['country' => 'Japan (JP)', 'isp' => 'Example']]);
$loaded = weblog_load_ip_cache();
check(($loaded['1.2.3.4']['country'] ?? '') === 'Japan (JP)', 'キャッシュを保存して読み戻せる');

// --- weblog_resolve_ips: 既に解決済み(country!=Unknown)は再解決しない ---
// 事前に「解決済み」1件と「プライベート(未登録)」を混ぜる。プライベートは通信せず
// 即 Private/Local が入るので、外部通信は一切発生しない。
weblog_save_ip_cache(['8.8.8.8' => ['country' => 'United States (US)', 'isp' => 'Google']]);
$calls = weblog_resolve_ips(['8.8.8.8', '10.1.1.1'], 0, 0);
check($calls === 1, '解決済みIPは再解決せず、未登録の1件だけAPI経路に入る（プライベートは即返し）');
$after = weblog_load_ip_cache();
check(($after['10.1.1.1']['country'] ?? '') === 'Private/Local', '未登録プライベートIPが解決されキャッシュされる');
check(($after['8.8.8.8']['country'] ?? '') === 'United States (US)', '解決済みIPは書き換えられない');

// --- 上限($limit)が効く: プライベート2件・limit=1 なら1件だけ ---
@unlink($tmpCache);
$calls2 = weblog_resolve_ips(['172.16.0.1', '172.16.0.2'], 1, 0);
check($calls2 === 1, '$limit=1 で解決は1件に制限される');

// --- Unknown はキャッシュ済みでも再解決対象(needs=true) ---
weblog_save_ip_cache(['9.9.9.9' => ['country' => 'Unknown']]);
// 9.9.9.9 は公開IPなので実通信になる。通信を避けるため limit=0 で「対象に数えるか」だけ確認したいが、
// weblog_resolve_ips は実際に叩くため、ここではプライベートに置換して needs 判定のみ固定する。
@unlink($tmpCache);
weblog_save_ip_cache(['10.5.5.5' => ['country' => 'Unknown']]);
$calls3 = weblog_resolve_ips(['10.5.5.5'], 0, 0);
check($calls3 === 1, 'country=Unknown のキャッシュは再解決対象になる');
$after3 = weblog_load_ip_cache();
check(($after3['10.5.5.5']['country'] ?? '') === 'Private/Local', 'Unknown だったIPが再解決で上書きされる');

@unlink($tmpCache);
echo "\n";
