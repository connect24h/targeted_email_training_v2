<?php
declare(strict_types=1);

// EventIngest のボット除外(isBotUserAgent)を固定する。
// 2026-08 に Slackbot 156件がキャンペーン89の全クリックを防衛失敗として誤計上した事故の再発防止。
// クリック取込は Apache combined ログの User-Agent を見てボットを弾く。

require_once __DIR__ . '/../lib/EventIngest.php';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException("FAIL: {$message}");
    }
    echo "PASS: {$message}\n";
}

$rm = new ReflectionMethod(EventIngest::class, 'isBotUserAgent');
$rm->setAccessible(true);
$isBot = static fn (string $line): bool => $rm->invoke(null, $line);

// combined ログ行を組み立てるヘルパ(末尾が referer と user-agent)。
$line = static fn (string $ua): string =>
    '1.2.3.4 - - [11/Aug/2026:10:00:00 +0900] "GET /link-1234567890.html HTTP/1.1" 200 100 "-" "' . $ua . '"';

// --- ボット(true) ---
check($isBot($line('Slackbot-LinkExpanding 1.0 (+https://api.slack.com/robots)')) === true, 'Slackbot はボット判定');
check($isBot($line('curl/8.12.1')) === true, 'curl はボット判定');
check($isBot($line('Proofpoint-URLDefense')) === true, 'Proofpoint はボット判定');
check($isBot($line('Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)')) === true, 'Googlebot はボット判定');
check($isBot($line('facebookexternalhit/1.1')) === true, 'facebookexternalhit はボット判定');

// --- 本物ブラウザ(false) — 実際に踏んだ人間を消さないことが最重要 ---
check($isBot($line('Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5.2 Mobile/15E148 Safari/604.1')) === false, 'iPhone Safari は人間');
check($isBot($line('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36')) === false, 'Windows Chrome は人間');

// --- UA が取れない行は false(既存挙動維持) ---
check($isBot('1.2.3.4 - - [11/Aug/2026:10:00:00 +0900] "GET /link-1.html HTTP/1.1" 200 100 "-" "-"') === false, 'UAなし(-) はボット扱いしない');
check($isBot('壊れた行') === false, 'UAを抽出できない行はボット扱いしない');

echo "ALL TESTS PASSED\n";
