<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../lib/ReportMailParser.php';
require_once __DIR__ . '/../lib/SuspiciousMailAnalyzer.php';

function analyzeFixture(string $name, array $context = [], array $reputation = []): array
{
    $raw = file_get_contents(__DIR__ . '/fixtures/eml/' . $name);
    $parsed = ReportMailParser::parse($raw, ['collect' => true]);
    check($parsed['ok'], "$name: 解析成功");
    return SuspiciousMailAnalyzer::analyze($parsed['analysis'], $context, $reputation);
}

function codes(array $result): array
{
    return array_column($result['findings'], 'code');
}

// 無害: info だけ → safe
$r = analyzeFixture('benign.eml');
check($r['suggested_category'] === 'safe' && $r['score'] === 0, '無害メールは safe / 0 点 (' . $r['suggested_category'] . '/' . $r['score'] . ')');
check(!in_array('auth_missing', codes($r), true), 'Authentication-Results があれば auth_missing は付かない');

// 認証失敗 + 不一致 + 表示名アドレス + 本文
$r = analyzeFixture('auth_fail_mismatch.eml', ['own_domains' => ['corp.example']]);
$c = codes($r);
foreach (['auth_fail', 'auth_weak', 'return_path_mismatch', 'reply_to_mismatch', 'display_name_email', 'display_name_lookalike', 'first_hop_ip', 'body_urgency', 'body_payment'] as $code) {
    check(in_array($code, $c, true), "auth_fail_mismatch: $code を検出");
}
check($r['suggested_category'] === 'threat', 'auth_fail_mismatch は threat (' . $r['score'] . ' 点)');
check($r['findings'][0]['severity'] === 'high', '所見は severity 順（先頭が high）');
$hop = array_values(array_filter($r['findings'], static fn(array $f): bool => $f['code'] === 'first_hop_ip'))[0];
check($hop['evidence']['ip'] === '198.51.100.99', '最初の送信元 IP を Received から抽出: ' . $hop['evidence']['ip']);
$r2 = analyzeFixture('auth_fail_mismatch.eml');
check(!in_array('display_name_lookalike', codes($r2), true), '自社ドメイン未指定なら lookalike は付かない');

// 転送: 内側が判定対象。IP リテラル + 表示不一致 + Reply-To + softfail + 本文（至急/ログイン/本人確認）
$r = analyzeFixture('forwarded_rfc822.eml');
check($r['target_index'] === 1, '転送メールは内側 (index 1) を判定対象にする');
$c = codes($r);
foreach (['url_ip_literal', 'url_display_mismatch', 'reply_to_mismatch', 'auth_weak', 'body_urgency', 'body_credential'] as $code) {
    check(in_array($code, $c, true), "forwarded: $code を検出");
}
check($r['suggested_category'] === 'threat', 'forwarded は threat (' . $r['score'] . ' 点)');

// 添付: 危険拡張子 + 二重拡張子 + zip 内 js
$r = analyzeFixture('attachment_exe_zip.eml');
$c = codes($r);
foreach (['attachment_dangerous_ext', 'attachment_double_ext', 'attachment_zip_dangerous', 'auth_missing', 'body_payment'] as $code) {
    check(in_array($code, $c, true), "attachment: $code を検出");
}
check($r['suggested_category'] === 'threat', 'attachment は threat');

// SafeLinks 展開は info、表示不一致（portal ≠ login-example）は high
$r = analyzeFixture('plain_alternative.eml');
$c = codes($r);
check(in_array('url_unwrapped', $c, true) && in_array('url_display_mismatch', $c, true) && in_array('body_credential', $c, true), 'alternative: unwrapped / display_mismatch / credential');
check($r['suggested_category'] === 'spam', 'alternative は spam 帯 (' . $r['score'] . ' 点)');

// 訓練メール: 追跡 ID があれば training 固定・0 点
$r = analyzeFixture('training_mail.eml', ['training_tracking_ids' => ['0000000001']]);
check($r['suggested_category'] === 'training' && $r['score'] === 0 && codes($r) === ['training_mail'], '訓練メールは training / 0 点');

// 評判: file / url の malicious で vt_* が付き threat になる
$parsed = ReportMailParser::parse(file_get_contents(__DIR__ . '/fixtures/eml/benign.eml'), ['collect' => true]);
$rep = ['url' => ['https://wiki.corp.example/notes/2026-09-06' => ['found' => 1, 'malicious' => 3, 'suspicious' => 0, 'harmless' => 60, 'undetected' => 10]]];
$r = SuspiciousMailAnalyzer::analyze($parsed['analysis'], [], $rep);
check(in_array('vt_url_malicious', codes($r), true) && $r['score'] === 20 && $r['suggested_category'] === 'spam', 'URL の VT 悪性判定で vt_url_malicious (20 点)');
$rep = ['url' => ['https://wiki.corp.example/notes/2026-09-06' => ['found' => 0]]];
$r = SuspiciousMailAnalyzer::analyze($parsed['analysis'], [], $rep);
check(in_array('vt_unknown', codes($r), true) && $r['suggested_category'] === 'safe', 'VT 未登録は vt_unknown (info)');
$parsed = ReportMailParser::parse(file_get_contents(__DIR__ . '/fixtures/eml/attachment_exe_zip.eml'), ['collect' => true]);
$sha = $parsed['analysis']['messages'][0]['attachments'][0]['sha256'];
$r = SuspiciousMailAnalyzer::analyze($parsed['analysis'], [], ['file' => [$sha => ['found' => 1, 'malicious' => 40]]]);
check(in_array('vt_file_malicious', codes($r), true), 'ファイルの VT 悪性判定で vt_file_malicious');

// スコア境界
$mk = static fn(int $score): array => [['code' => 'x', 'severity' => 'low', 'score' => $score, 'message' => '', 'evidence' => []]];
check(SuspiciousMailAnalyzer::categoryFor(9, $mk(9)) === 'safe', '9 点は safe');
check(SuspiciousMailAnalyzer::categoryFor(10, $mk(10)) === 'spam', '10 点は spam');
check(SuspiciousMailAnalyzer::categoryFor(24, $mk(24)) === 'spam', '24 点は spam');
check(SuspiciousMailAnalyzer::categoryFor(25, $mk(25)) === 'threat', '25 点は threat');

// メッセージなし → undetermined
$r = SuspiciousMailAnalyzer::analyze(['messages' => []], ['parse_error' => 'x']);
check($r['suggested_category'] === 'undetermined' && $r['target_index'] === null, '解析結果なしは undetermined');

// 個別ルール: punycode / 短縮 / ポート / userinfo
$mail = "From: a@b.test\r\nContent-Type: text/html\r\n\r\n<a href=\"http://xn--80ak6aa92e.com/\">x</a> <a href=\"https://bit.ly/abc\">y</a> <a href=\"http://example.test:8080/\">z</a> <a href=\"http://bank.test@evil.test/\">w</a>";
$r = SuspiciousMailAnalyzer::analyze(ReportMailParser::parse($mail, ['collect' => true])['analysis']);
$c = codes($r);
foreach (['url_punycode', 'url_shortener', 'url_nonstandard_port', 'url_userinfo'] as $code) {
    check(in_array($code, $c, true), "URL ルール: $code");
}

echo "suspicious_mail_analyzer_test: OK\n";
