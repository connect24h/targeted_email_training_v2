<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../lib/ReportMailParser.php';

function fixtureEml(string $name): string
{
    $raw = file_get_contents(__DIR__ . '/fixtures/eml/' . $name);
    if ($raw === false) { throw new RuntimeException("fixture missing: $name"); }
    return $raw;
}

function collect(string $name): array
{
    $result = ReportMailParser::parse(fixtureEml($name), ['collect' => true]);
    check($result['ok'], "$name: 解析成功 (" . ($result['error'] ?? '') . ')');
    return $result;
}

// collect=false では出力キーが従来と同じ（analysis が付かない）。
$plain = ReportMailParser::parse(fixtureEml('benign.eml'));
check(!array_key_exists('analysis', $plain), 'collect=false では analysis キーを持たない');
check(array_keys($plain) === ['ok', 'error', 'headers', 'message_ids', 'tracking_ids_from_msgid', 'tracking_ids_from_body', 'is_auto_submitted'],
    'collect=false の出力キー順が従来どおり');

// 1. multipart/alternative + SafeLinks + ISO-2022-JP 件名
$r = collect('plain_alternative.eml');
$m = $r['analysis']['messages'];
check(count($m) === 1 && $m[0]['depth'] === 0, 'alternative: メッセージは外側 1 件');
check($m[0]['subject'] === '【重要】アカウント確認のお願い', 'ISO-2022-JP 件名を復号: ' . $m[0]['subject']);
check($m[0]['from_email'] === 'desk@example.test' && $m[0]['from_name'] === 'Service Desk', 'From のアドレスと表示名');
check(count($m[0]['received']) === 2 && str_contains($m[0]['received'][1], '198.51.100.7'), 'Received を順序どおり保持');
check(count($m[0]['auth_results']) === 1 && str_contains($m[0]['auth_results'][0], 'spf=pass'), 'Authentication-Results を保持');
check(str_contains($m[0]['text'], 'Please confirm') && str_contains($m[0]['html_text'], 'Please') && !str_contains($m[0]['html_text'], '<a'), 'text と html_text（タグ除去）を保持');
$urls = $m[0]['urls'];
check(count($urls) === 1, 'alternative: 同一 URL は重複しない (' . count($urls) . ')');
check($urls[0]['unwrapped'] === 'https://login-example.test/verify?u=1', 'SafeLinks を展開した URL');
check($urls[0]['display'] === 'https://portal.example.test/login', '<a> の表示文字列を保持: ' . var_export($urls[0]['display'], true));
check($r['headers']['subject'] === '【重要】アカウント確認のお願い', '照合用ヘッダーの件名も従来どおり復号');

// 2. 転送: 内側 message/rfc822 が 2 件目のメッセージになり、本文・URL は内側に付く
$r = collect('forwarded_rfc822.eml');
$m = $r['analysis']['messages'];
check(count($m) === 2 && $m[0]['depth'] === 0 && $m[1]['depth'] === 2, '転送: 外側と内側の 2 メッセージ (depth 0 / 2)');
check($m[0]['from_email'] === 'reporter@corp.example' && $m[1]['from_email'] === 'notice@bank-secure.test', '外側は報告者、内側は不審な送信者');
check($m[1]['reply_to'] === 'support@other-domain.test', '内側の Reply-To');
check(str_contains($m[0]['text'], '不審です') && $m[0]['urls'] === [], '外側本文は外側に付き URL なし');
check(count($m[1]['urls']) === 1 && $m[1]['urls'][0]['raw'] === 'http://192.0.2.5/login'
    && $m[1]['urls'][0]['display'] === 'https://www.bank-secure.test/login', '内側の URL と表示文字列');
check(str_contains($m[1]['html_text'], '至急'), '内側の HTML 本文テキスト');
check($r['headers']['from_email'] === 'reporter@corp.example', '照合用の外側 From は従来どおり');

// 3. 添付: exe と zip（zip 内エントリ名）
$r = collect('attachment_exe_zip.eml');
$a = $r['analysis']['messages'][0]['attachments'];
check(count($a) === 2, '添付 2 件 (' . count($a) . ')');
check($a[0]['filename'] === 'invoice.pdf.exe' && $a[0]['extension'] === 'exe' && $a[0]['size'] === 66
    && $a[0]['sha256'] === hash('sha256', "MZ" . str_repeat("\0", 64)), 'exe 添付の名前・拡張子・サイズ・SHA256');
check($a[1]['filename'] === 'docs.zip' && $a[1]['extension'] === 'zip' && $a[1]['zip_entries'] === ['readme.txt', 'run.js'],
    'zip 添付のエントリ名: ' . implode(',', $a[1]['zip_entries']));
check(ReportMailParser::parse(fixtureEml('attachment_exe_zip.eml'))['ok'], '添付付きでも collect=false は従来どおり成功');

// 4. 認証失敗と不一致
$r = collect('auth_fail_mismatch.eml');
$m = $r['analysis']['messages'][0];
check($m['from_name'] === 'ceo@corp.example' && $m['from_email'] === 'attacker@free-mail.test', '表示名にメールアドレス');
check($m['return_path'] === 'bounce@bulk-sender.test' && $m['reply_to'] === 'reply@another.test', 'Return-Path / Reply-To');
check(str_contains($m['auth_results'][0], 'spf=fail'), 'spf=fail を保持');

// 5. 訓練メール: 追跡 ID は従来どおり回収され、collect でも同じ
$r = collect('training_mail.eml');
check($r['tracking_ids_from_msgid'] === ['0000000001'] && $r['tracking_ids_from_body'] === ['0000000001'], '訓練メールの追跡 ID');
check($r['analysis']['messages'][0]['message_id'] === '<t0000000001.abc@mail.cojp.online>', 'Message-ID を保持');

// 6. 無害
$r = collect('benign.eml');
check(count($r['analysis']['messages'][0]['urls']) === 1 && $r['analysis']['messages'][0]['attachments'] === [], '無害メール: URL 1 件、添付なし');

// 上限は collect でも効く
$big = "From: a@b\r\nContent-Type: application/octet-stream\r\nContent-Transfer-Encoding: base64\r\n\r\n" . base64_encode(str_repeat('x', 10));
check(ReportMailParser::parse($big, ['collect' => true, 'max_decoded_bytes' => 5])['error'] === 'decoded size limit exceeded', '添付のデコードも decoded 上限に数える');
$text = "From: a@b\r\nContent-Type: text/plain\r\n\r\n" . str_repeat('y', ReportMailParser::COLLECT_TEXT_BYTES + 100);
$r = ReportMailParser::parse($text, ['collect' => true]);
check($r['ok'] && strlen($r['analysis']['messages'][0]['text']) === ReportMailParser::COLLECT_TEXT_BYTES, '本文テキストは COLLECT_TEXT_BYTES で切り詰める');

// 添付として付いたテキストは添付一覧にも載る
$txt = "From: a@b\r\nContent-Type: multipart/mixed; boundary=x\r\n\r\n--x\r\nContent-Type: text/plain\r\n\r\nbody\r\n--x\r\nContent-Type: text/plain; name=\"note.txt\"\r\nContent-Disposition: attachment; filename=\"note.txt\"\r\n\r\nattached https://x/link-0000000009.html\r\n--x--\r\n";
$r = ReportMailParser::parse($txt, ['collect' => true]);
check($r['ok'] && count($r['analysis']['messages'][0]['attachments']) === 1 && $r['analysis']['messages'][0]['attachments'][0]['filename'] === 'note.txt'
    && $r['tracking_ids_from_body'] === ['0000000009'], 'テキスト添付は添付一覧に載り、本文走査も従来どおり');

// RFC 2231 の filename*
$rfc2231 = "From: a@b\r\nContent-Type: multipart/mixed; boundary=x\r\n\r\n--x\r\nContent-Type: application/pdf\r\nContent-Disposition: attachment; filename*=UTF-8''%E8%AB%8B%E6%B1%82%E6%9B%B8.pdf\r\n\r\n%PDF\r\n--x--\r\n";
$r = ReportMailParser::parse($rfc2231, ['collect' => true]);
check($r['analysis']['messages'][0]['attachments'][0]['filename'] === '請求書.pdf', 'RFC 2231 filename* を復号');

echo "report_mail_parser_collect_test: OK\n";
