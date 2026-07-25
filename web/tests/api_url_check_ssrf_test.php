<?php
declare(strict_types=1);

/**
 * url_check.php の SSRF 対策テスト(2026-07-20 OWASP A10 対応)。
 * url_check_is_safe_host() が内部アドレス(ループバック/プライベート/リンクローカル/
 * クラウドメタデータ)を拒否し、正常な公開ホストを許可することを固定する。
 */

require_once __DIR__ . '/helpers.php';
tet2_test_boot();
load_api('url_check');

// --- 拒否されるべき内部アドレス(SSRF ベクタ) ---
$blocked = [
    'http://127.0.0.1/'                => 'ループバック(127.0.0.1)',
    'http://127.0.0.1:22/'             => 'ループバック+ポート指定(内部ポートスキャン)',
    'https://localhost/'               => 'localhost',
    'http://169.254.169.254/latest/'   => 'クラウドメタデータ(169.254.169.254)',
    'http://10.0.0.1/'                 => 'プライベート(10/8)',
    'http://192.168.1.1/'              => 'プライベート(192.168/16)',
    'http://172.16.0.1/'               => 'プライベート(172.16/12)',
    'http://[::1]/'                    => 'IPv6 ループバック(::1)',
    'http://0.0.0.0/'                  => '未指定アドレス(0.0.0.0)',
];
foreach ($blocked as $url => $label) {
    check(url_check_is_safe_host($url) === false, "拒否: {$label}");
}

// --- 許可されるべき公開アドレス ---
$allowed = [
    'http://85.131.251.224/'  => '本番ビーコンIP(公開)',
    'https://example.com/'    => '公開ドメイン(example.com)',
    'http://8.8.8.8/'         => '公開IP(8.8.8.8)',
];
foreach ($allowed as $url => $label) {
    check(url_check_is_safe_host($url) === true, "許可: {$label}");
}

// --- パースできない/スキーム不正は false(fail-closed) ---
check(url_check_is_safe_host('notaurl') === false, 'パース不能は拒否(fail-closed)');
check(url_check_is_safe_host('ftp://example.com/') === false, '非http/httpsスキームは拒否');
check(url_check_is_safe_host('http:///path') === false, 'ホストなしは拒否');

echo "ALL TESTS PASSED\n";
