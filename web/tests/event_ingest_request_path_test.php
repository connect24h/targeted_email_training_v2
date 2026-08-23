<?php
declare(strict_types=1);

// EventIngest の click/open 判定がリクエスト行のパスのみを見ることを固定する。
// 2026-08-23: favicon.ico や認証 POST(/training_log.php)は「リファラーに link-{tid}.html
// を含むだけ」で click に誤計上され、1回の開封が複数 click に水増しされていた。
// リクエスト行のパスで判定することでこれを防ぐ。

require_once __DIR__ . '/../lib/EventIngest.php';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException("FAIL: {$message}");
    }
    echo "PASS: {$message}\n";
}

$rp = new ReflectionMethod(EventIngest::class, 'apacheRequestPath');
$rp->setAccessible(true);
$path = static fn (string $line): ?string => $rp->invoke(null, $line);

$clickPat = '/link-(\d{10})\.html/';
$openPat  = '/kunren-beacon-(\d{10})\.png/';
$UA = '"Mozilla/5.0 (iPhone) Safari"';
$REF = '"https://filesend.cojp.online/link-6369365830.html"';

// リクエスト行のパスを正しく抽出する。
check($path('1.2.3.4 - - [22/Aug/2026:10:33:48 +0900] "GET /link-6369365830.html HTTP/1.1" 200 100 "-" ' . $UA)
    === '/link-6369365830.html', 'リクエストパス抽出: GET /link-...html');
check($path('1.2.3.4 - - [22/Aug/2026:10:33:48 +0900] "POST /training_log.php HTTP/1.1" 200 74 ' . $REF . ' ' . $UA)
    === '/training_log.php', 'リクエストパス抽出: POST /training_log.php');
check($path('壊れた行') === null, 'リクエスト行が無ければ null');

// 真のクリック(GET /link-xxx.html)だけが click パターンにマッチする。
$clickHit = static fn (string $line): bool => ($p = $path($line)) !== null && preg_match($clickPat, $p) === 1;

// これは click(本物)。
check($clickHit('1.2.3.4 - - [22/Aug/2026:10:33:48 +0900] "GET /link-6369365830.html HTTP/1.1" 200 100 "-" ' . $UA) === true,
    'GET /link-...html は click');
// favicon はリファラーに link-{tid}.html を含むが、パスは /favicon.ico なので click にしない。
check($clickHit('1.2.3.4 - - [22/Aug/2026:11:11:41 +0900] "GET /favicon.ico HTTP/1.1" 404 5030 ' . $REF . ' ' . $UA) === false,
    'favicon.ico(リファラーにlink含む)は click にしない');
// 認証 POST は /training_log.php なので click にしない(auth は別経路 text_log で取込)。
check($clickHit('1.2.3.4 - - [22/Aug/2026:11:11:49 +0900] "POST /training_log.php HTTP/1.1" 200 7408 ' . $REF . ' ' . $UA) === false,
    'POST /training_log.php(リファラーにlink含む)は click にしない');
// 画像等の別リソースも click にしない。
check($clickHit('1.2.3.4 - - [22/Aug/2026:11:11:42 +0900] "GET /assets/logo.png HTTP/1.1" 200 200 ' . $REF . ' ' . $UA) === false,
    'その他リソース(リファラーにlink含む)は click にしない');
// クエリ付きでも本体パスで判定。
check($clickHit('1.2.3.4 - - [22/Aug/2026:11:11:42 +0900] "GET /link-6369365830.html?x=1 HTTP/1.1" 200 100 "-" ' . $UA) === true,
    'クエリ付き /link-...html も click');

// open(ビーコン)も同様にリクエストパスで判定。
$openHit = static fn (string $line): bool => ($p = $path($line)) !== null && preg_match($openPat, $p) === 1;
check($openHit('1.2.3.4 - - [22/Aug/2026:10:33:50 +0900] "GET /kunren-beacon-6369365830.png HTTP/1.1" 200 100 "-" ' . $UA) === true,
    'GET /kunren-beacon-...png は open');
check($openHit('1.2.3.4 - - [22/Aug/2026:11:11:41 +0900] "GET /favicon.ico HTTP/1.1" 404 5030 ' . $REF . ' ' . $UA) === false,
    'favicon.ico は open にしない');

echo "ALL TESTS PASSED\n";
