<?php
declare(strict_types=1);

/**
 * VirusTotal API v3 の参照クライアント。
 *
 * ファイルは sha256、URL は URL 識別子で「既存の解析結果を GET する」だけで、
 * ファイル本体や URL を VirusTotal へ送信（POST）しない。社内メールの内容を第三者に渡さないため。
 * 無料枠は 4 req/分・500 req/日。呼び出し側で件数を絞る。
 */
final class VirusTotalClient
{
    public const BASE_URL = 'https://www.virustotal.com/api/v3';
    public const TIMEOUT_SECONDS = 5;

    /** @var callable(string $url, array $headers): array{code:int, body:string} */
    private $http;

    public function __construct(private string $apiKey, ?callable $http = null)
    {
        $this->http = $http ?? [$this, 'curl'];
    }

    /** @return array{status:string, found:int, malicious:int, suspicious:int, harmless:int, undetected:int, raw:?array} */
    public function lookupFile(string $sha256): array
    {
        if (!preg_match('/^[0-9a-f]{64}$/', $sha256)) {
            return self::outcome('error');
        }
        return $this->lookup('/files/' . $sha256);
    }

    public function lookupUrl(string $url): array
    {
        return $this->lookup('/urls/' . self::urlId($url));
    }

    /** VirusTotal の URL 識別子（padding なしの base64url）。 */
    public static function urlId(string $url): string
    {
        return rtrim(strtr(base64_encode($url), '+/', '-_'), '=');
    }

    private function lookup(string $path): array
    {
        try {
            $response = ($this->http)(self::BASE_URL . $path, ['x-apikey: ' . $this->apiKey, 'Accept: application/json']);
        } catch (Throwable $error) {
            error_log('VirusTotalClient: ' . get_class($error));
            return self::outcome('error');
        }
        $code = (int) ($response['code'] ?? 0);
        if ($code === 404) {
            return self::outcome('not_found');
        }
        if ($code === 429) {
            return self::outcome('rate_limited');
        }
        if ($code === 401 || $code === 403) {
            return self::outcome('unauthorized');
        }
        if ($code !== 200) {
            return self::outcome('error');
        }
        $json = json_decode((string) ($response['body'] ?? ''), true);
        $stats = $json['data']['attributes']['last_analysis_stats'] ?? null;
        if (!is_array($stats)) {
            return self::outcome('error');
        }
        $result = self::outcome('found');
        $result['found'] = 1;
        foreach (['malicious', 'suspicious', 'harmless', 'undetected'] as $key) {
            $result[$key] = (int) ($stats[$key] ?? 0);
        }
        // 保存するのは集計と少数の属性だけ。応答全体（数十 KB）は持たない。
        $attributes = $json['data']['attributes'] ?? [];
        $result['raw'] = [
            'last_analysis_date' => $attributes['last_analysis_date'] ?? null,
            'reputation' => $attributes['reputation'] ?? null,
            'meaningful_name' => $attributes['meaningful_name'] ?? null,
            'type_description' => $attributes['type_description'] ?? null,
            'title' => $attributes['title'] ?? null,
            'last_final_url' => $attributes['last_final_url'] ?? null,
            'categories' => $attributes['categories'] ?? null,
        ];
        return $result;
    }

    private static function outcome(string $status): array
    {
        return ['status' => $status, 'found' => 0, 'malicious' => 0, 'suspicious' => 0, 'harmless' => 0, 'undetected' => 0, 'raw' => null];
    }

    /** @return array{code:int, body:string} */
    private function curl(string $url, array $headers): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('curl_init failed');
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($body === false) {
            throw new RuntimeException('curl_exec failed');
        }
        return ['code' => $code, 'body' => (string) $body];
    }
}
