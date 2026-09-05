<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../lib/ReportMailParser.php';

function mailText(string $body, string $extra = ''): string
{
    return "From: Reporter <reporter@example.net>\r\nSubject: report\r\n" . $extra . "\r\n" . $body;
}

function multipartMail(array $parts): string
{
    return "Content-Type: multipart/mixed; boundary=fixture\r\n\r\n"
        . '--fixture' . "\r\n" . implode("\r\n--fixture\r\n", $parts) . "\r\n--fixture--\r\n";
}

function bodyIds(string $raw): array
{
    $result = ReportMailParser::parse($raw);
    check($result['ok'], '本文解析成功: ' . ($result['error'] ?? ''));
    return $result['tracking_ids_from_body'];
}

check(bodyIds(mailText('https://outside/link-12345=' . "\r\n" . '67890.html',
    "Content-Transfer-Encoding: quoted-printable\r\n")) === ['1234567890'], 'QP soft breakを復元');
check(bodyIds(mailText('<a href="https://outside/link-1111111111.html?a=1&amp;b=2">報告</a>',
    "Content-Type: text/html; charset=UTF-8\r\n")) === ['1111111111'], 'hrefのみのURLとentity');
$url = 'https://outside/link-1234567890.html?a=1&b=2';
check(bodyIds(mailText('https://nam01.safelinks.protection.outlook.com/?url=' . rawurlencode($url)
    . '&data=abc')) === ['1234567890'], 'SafeLinks解除');
check(bodyIds(mailText('https://urldefense.com/v3/__https://outside/link-1234567890.html__;!!abc!def$'))
    === ['1234567890'], 'URLDefense v3解除');
check(bodyIds(mailText('https://urldefense.proofpoint.com/v2/url?u=https-3A__outside_link-2D1234567890.html&d=x'))
    === ['1234567890'], 'URLDefense v2解除');
check(bodyIds(mailText('https://urldefense.com/v3/__https://outside/link*1234567890.html__;LQ!abc!def$'))
    === ['1234567890'], 'URLDefense v3 escape解除');
$inner = "Message-ID: <t0987654321.abc@example>\r\nContent-Type: text/plain\r\n\r\nhttps://x/link-0987654321.html";
$attached = multipartMail(["Content-Type: message/rfc822\r\n\r\n" . $inner]);
$result = ReportMailParser::parse($attached);
check($result['ok'] && $result['tracking_ids_from_msgid'] === ['0987654321']
    && $result['tracking_ids_from_body'] === ['0987654321'], '.emlのヘッダと本文を回収');
check($result['headers']['message_id'] === null, '内側ヘッダは外側を上書きしない');
$result = ReportMailParser::parse(mailText('', "Message-ID: <outer@example>\r\n"
    . "In-Reply-To: <t0000000001.abc@example>\r\nReferences: <t0000000002.def@example>\r\n"
    . "\t<t0000000001.abc@example>\r\nReceived: first\r\nReceived: second\r\nDate: original date\r\n"));
check($result['tracking_ids_from_msgid'] === ['0000000001', '0000000002'], '返信ヘッダと重複除去');
check(count($result['message_ids']) === 3 && count($result['headers']['references']) === 2, '外側IDも回収');
check($result['headers']['received_first'] === 'first' && $result['headers']['date'] === 'original date'
    && $result['headers']['from_email'] === 'reporter@example.net', '外側メタデータ');
foreach (['auto-replied' => true, 'auto-generated' => true, 'no' => false] as $value => $expected) {
    check(ReportMailParser::parse(mailText('', "Auto-Submitted: $value\r\n"))['is_auto_submitted'] === $expected,
        'Auto-Submitted: ' . $value);
}
check(bodyIds(mailText('1234567890 https://outside/link-1111111111.html')) === ['1111111111'], '無関係な数字を除外');
check(bodyIds(mailText('caf' . chr(233) . ' https://x/link-0000000001.html',
    "Content-Type: text/plain; charset=xxx-unknown\r\n")) === ['0000000001'], '不明charsetでも解析');
check(bodyIds(mailText(base64_encode('https://x/link-0000000001.html'),
    "Content-Transfer-Encoding: base64\r\n")) === ['0000000001'], 'base64復号');
check(bodyIds(multipartMail(["Content-Type: application/octet-stream\r\nContent-Transfer-Encoding: base64\r\n\r\n%%%link-1111111111.html"])) === [], 'バイナリを読み飛ばす');
$nested = mailText('leaf');
for ($i = 0; $i < 5; $i++) {
    $nested = "Content-Type: message/rfc822\r\n\r\n" . $nested;
}
check(ReportMailParser::parse($nested)['ok'], '深さ5は受理（外側は0）');
check(ReportMailParser::parse(multipartMail(array_fill(0, 49, mailText('part'))))['ok'], '外側を含む50パートは受理');
$failures = [
    ['depth', "Content-Type: message/rfc822\r\n\r\n" . $nested, []],
    ['parts', multipartMail(array_fill(0, 50, mailText('part'))), []],
    ['raw', str_repeat('x', 2 * 1024 * 1024 + 1), []],
    ['header', mailText('', 'X-Large: ' . str_repeat('x', 8192) . "\r\n"), []],
    ['header', mailText('', 'X-Folded: ' . str_repeat('x', 4100) . "\r\n " . str_repeat('x', 4100) . "\r\n"), []],
    ['decoded', mailText('abcdef'), ['max_decoded_bytes' => 5]],
    ['decoded', multipartMail([mailText('abc'), mailText('def')]), ['max_decoded_bytes' => 5]],
    ['boundary', "Content-Type: multipart/mixed\r\n\r\nx", []],
    ['boundary', "Content-Type: multipart/mixed; boundary=x\r\n\r\n--x\r\n\r\nunfinished", []],
    ['header', "bad header\r\n\r\nbody", []],
    ['base64', mailText('%%%', "Content-Transfer-Encoding: base64\r\n"), []],
];
foreach ($failures as [$reason, $raw, $opts]) {
    $result = ReportMailParser::parse($raw, $opts);
    check(!$result['ok'] && is_string($result['error']) && str_contains($result['error'], $reason), '失敗を返す: ' . $reason);
}
check(ReportMailParser::parse(mailText('abcdef'), ['max_raw_bytes' => 10])['ok'] === false, 'raw上限を上書き');
check(ReportMailParser::parse($nested, ['max_depth' => 4])['ok'] === false, '深さ上限を上書き');
check(ReportMailParser::parse($attached, ['max_parts' => 2])['ok'] === false, 'パート上限を上書き');
check(ReportMailParser::parse(mailText(''), ['max_header_bytes' => 10])['ok'] === false, 'ヘッダ上限を上書き');

check(bodyIds(mailText('https://urldefense.com/v3/__https:/outside/link-**I.html__;MTIzNDU2Nzg5MA!abc$'))
    === ['1234567890'], 'URLDefense v3連続置換');
check(bodyIds(mailText('<a href="">empty</a><a href=https://x/link-0000000001.html>link</a>',
    "Content-Type: text/html\r\n")) === ['0000000001'], '空hrefと引用符なしhref');
check(bodyIds(mailText(base64_encode(mb_convert_encoding('報告 https://x/link-0000000001.html', 'ISO-2022-JP', 'UTF-8')),
    "Content-Type: text/plain; charset=ISO-2022-JP\r\nContent-Transfer-Encoding: base64\r\n"))
    === ['0000000001'], 'base64からISO-2022-JPを復号');
$result = ReportMailParser::parse("Subject: =?UTF-8?B?" . base64_encode('報告') . "?=\r\n\r\nbody");
check($result['headers']['subject'] === '報告', 'encoded-word件名');
check(bodyIds(multipartMail(["Content-Type: message/rfc822\r\nContent-Transfer-Encoding: base64\r\n\r\n" . base64_encode($inner)]))
    === ['0987654321'], 'base64の.eml添付');

$largeNested = mailText(str_repeat('x', 850000));
for ($i = 0; $i < 5; $i++) {
    $largeNested = "Content-Type: message/rfc822\r\n\r\n" . $largeNested;
}
$result = ReportMailParser::parse($largeNested);
check(!$result['ok'] && str_contains($result['error'], 'decoded'), '既定4MBの累積復号上限');
check($result['message_ids'] === [] && $result['tracking_ids_from_body'] === [], '失敗時に部分候補を返さない');
$result = ReportMailParser::parse(mailText(str_repeat(chr(233), 10),
    "Content-Type: text/plain; charset=xxx-unknown\r\n"), ['max_decoded_bytes' => 15]);
check(!$result['ok'] && str_contains($result['error'], 'decoded'), 'UTF-8変換後の増加も上限に含む');
check(bodyIds(mailText('12345678901 link-12345678901.html link-123456789.html')) === [], '桁数を厳密に限定');
$result = ReportMailParser::parse(mailText('', "Subject: ignored second\r\n"), ['max_depth' => -1]);
check(!$result['ok'] && $result['error'] !== null, '不正オプションを例外にしない');

check(bodyIds(mailText('https://nam01.safelinks.protection.outlook.com/?'
    . str_repeat('data=x&', 1100) . 'url=' . rawurlencode('https://x/link-0000000001.html')))
    === ['0000000001'], '大量クエリでもSafeLinksを復号');
$result = ReportMailParser::parse("X-Bad: bad\0value\r\n\r\nbody");
check(!$result['ok'] && str_contains($result['error'], 'header'), 'ヘッダ制御文字を拒否');
echo "ALL TESTS PASSED\n";
