<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../lib/VirusTotalClient.php';
require_once __DIR__ . '/../lib/Secrets.php';

$calls = [];
$responses = [];
$http = static function (string $url, array $headers) use (&$calls, &$responses): array {
    $calls[] = ['url' => $url, 'headers' => $headers];
    return array_shift($responses) ?? ['code' => 500, 'body' => ''];
};
$client = new VirusTotalClient('test-key', $http);
$sha = str_repeat('a', 64);

$responses[] = ['code' => 200, 'body' => json_encode(['data' => ['attributes' => [
    'last_analysis_stats' => ['malicious' => 5, 'suspicious' => 1, 'harmless' => 60, 'undetected' => 4],
    'last_analysis_date' => 1700000000, 'meaningful_name' => 'x.exe']]])];
$r = $client->lookupFile($sha);
check($r['status'] === 'found' && $r['found'] === 1 && $r['malicious'] === 5 && $r['suspicious'] === 1 && $r['harmless'] === 60, '200: 集計を取り込む');
check($r['raw']['meaningful_name'] === 'x.exe' && !isset($r['raw']['last_analysis_results']), '保持する属性は限定');
check($calls[0]['url'] === VirusTotalClient::BASE_URL . '/files/' . $sha && in_array('x-apikey: test-key', $calls[0]['headers'], true), 'files エンドポイントと x-apikey ヘッダー');

$responses[] = ['code' => 404, 'body' => '{"error":{"code":"NotFoundError"}}'];
$r = $client->lookupUrl('https://example.test/a?b=c');
check($r['status'] === 'not_found' && $r['found'] === 0, '404: not_found');
check($calls[1]['url'] === VirusTotalClient::BASE_URL . '/urls/' . rtrim(strtr(base64_encode('https://example.test/a?b=c'), '+/', '-_'), '='), 'URL 識別子は padding なし base64url');

$responses[] = ['code' => 429, 'body' => ''];
check($client->lookupFile($sha)['status'] === 'rate_limited', '429: rate_limited');
$responses[] = ['code' => 401, 'body' => ''];
check($client->lookupFile($sha)['status'] === 'unauthorized', '401: unauthorized');
$responses[] = ['code' => 200, 'body' => 'not json'];
check($client->lookupFile($sha)['status'] === 'error', '壊れた JSON は error');
check($client->lookupFile('not-a-hash')['status'] === 'error' && count($calls) === 5, '不正なハッシュは HTTP を呼ばず error');

$thrower = new VirusTotalClient('k', static function (): array { throw new RuntimeException('boom'); });
check($thrower->lookupFile($sha)['status'] === 'error', 'HTTP 例外は error に畳む');

// Secrets: ファイル無し → null、ini あり → 値
Secrets::reset();
putenv('TET2_SECRETS_FILE=/nonexistent/secrets.ini');
check(Secrets::get('virustotal.api_key') === null, 'secrets.ini が無ければ null');
$tmp = tempnam(sys_get_temp_dir(), 'tet2-secrets-');
file_put_contents($tmp, "[virustotal]\napi_key = abc123\nempty =\n");
Secrets::reset();
putenv("TET2_SECRETS_FILE=$tmp");
check(Secrets::get('virustotal.api_key') === 'abc123', 'ini から値を読む');
check(Secrets::get('virustotal.empty') === null && Secrets::get('nosection.x') === null && Secrets::get('bad') === null, '空値・未知セクション・不正キーは null');
unlink($tmp);
Secrets::reset();
putenv('TET2_SECRETS_FILE');

echo "virustotal_client_test: OK\n";
