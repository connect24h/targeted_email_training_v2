<?php
declare(strict_types=1);

/** Pure MIME parser for matching; no persistence, I/O, or sender trust decisions. */
final class ReportMailParser
{
    /**
     * Options: max_raw_bytes, max_depth, max_parts, max_decoded_bytes, max_header_bytes.
     * The outer entity is depth 0 and counts as one part. Encapsulated messages also count.
     * Decoded bytes count each decoded container and each text part (including UTF-8 expansion).
     */
    public static function parse(string $raw, array $opts = []): array
    {
        $result = [
            'ok' => false, 'error' => null,
            'headers' => ['message_id' => null, 'in_reply_to' => null, 'references' => [],
                'from_email' => null, 'subject' => null, 'date' => null,
                'auto_submitted' => null, 'received_first' => null],
            'message_ids' => [], 'tracking_ids_from_msgid' => [], 'tracking_ids_from_body' => [],
            'is_auto_submitted' => false,
        ];
        try {
            $limits = array_replace(['max_raw_bytes' => 2 * 1024 * 1024, 'max_depth' => 5,
                'max_parts' => 50, 'max_decoded_bytes' => 4 * 1024 * 1024,
                'max_header_bytes' => 8192], $opts);
            foreach ($limits as $value) {
                if (!is_int($value) || $value < 0) {
                    throw new RuntimeException('invalid parser limit');
                }
            }
            if (strlen($raw) > $limits['max_raw_bytes']) {
                throw new RuntimeException('raw size limit exceeded');
            }
            $state = ['limits' => $limits, 'parts' => 0, 'decoded' => 0, 'result' => $result];
            self::entity($raw, 0, $state);
            $result = $state['result'];
            foreach (['message_ids', 'tracking_ids_from_msgid', 'tracking_ids_from_body'] as $key) {
                $result[$key] = array_values(array_unique($result[$key]));
            }
            $result['ok'] = true;
        } catch (RuntimeException $error) {
            $result['error'] = $error->getMessage();
        } catch (Throwable) {
            $result['error'] = 'invalid MIME structure';
        }
        return $result;
    }

    private static function entity(string $raw, int $depth, array &$state): void
    {
        if ($depth > $state['limits']['max_depth']) {
            throw new RuntimeException('depth limit exceeded');
        }
        if (++$state['parts'] > $state['limits']['max_parts']) {
            throw new RuntimeException('parts limit exceeded');
        }
        [$headers, $body] = self::splitEntity($raw, $state['limits']['max_header_bytes']);
        if ($depth === 0) {
            $state['result']['headers'] = self::outerHeaders($headers);
            $auto = $headers['auto-submitted'][0] ?? '';
            $state['result']['is_auto_submitted'] = preg_match('/^auto-[a-z0-9-]+(?:\s*;|\s*$)/i', $auto) === 1;
        }
        foreach (['message-id', 'in-reply-to', 'references'] as $name) {
            foreach ($headers[$name] ?? [] as $value) {
                foreach (self::messageIds($value) as $id) {
                    $state['result']['message_ids'][] = $id;
                    if (preg_match('/^<t([0-9]{10})\./', $id, $match)) {
                        $state['result']['tracking_ids_from_msgid'][] = $match[1];
                    }
                }
            }
        }
        $contentType = $headers['content-type'][0] ?? 'text/plain';
        $type = strtolower(trim(explode(';', $contentType, 2)[0]));
        if (!str_starts_with($type, 'multipart/') && !in_array($type, ['message/rfc822', 'text/plain', 'text/html'], true)) {
            return;
        }
        $encoding = strtolower(trim($headers['content-transfer-encoding'][0] ?? '7bit'));
        $body = self::transferDecode($body, $encoding);
        if (str_starts_with($type, 'multipart/')) {
            if (in_array($encoding, ['base64', 'quoted-printable'], true)) {
                self::charge(strlen($body), $state);
            }
            self::multipart($body, self::parameter($contentType, 'boundary'), $depth, $state);
            return;
        }
        if ($type === 'message/rfc822') {
            self::charge(strlen($body), $state);
            self::entity($body, $depth + 1, $state);
            return;
        }
        $text = self::utf8($body, self::parameter($contentType, 'charset') ?? 'US-ASCII');
        self::charge(max(strlen($body), strlen($text)), $state);
        self::collectBody($text, $type === 'text/html', $state);
    }

    private static function splitEntity(string $raw, int $limit): array
    {
        // Normalize transport line endings only; QP accepts both CRLF and LF soft breaks.
        $raw = str_replace("\r\n", "\n", $raw);
        if (str_starts_with($raw, "\n")) {
            return [[], substr($raw, 1)];
        }
        $split = strpos($raw, "\n\n");
        if ($split === false) {
            throw new RuntimeException('invalid header/body separator');
        }
        $headers = [];
        $name = null;
        $size = 0;
        foreach (explode("\n", substr($raw, 0, $split)) as $line) {
            if (preg_match('/[\x00-\x08\x0b-\x1f\x7f]/', $line)) {
                throw new RuntimeException('invalid header control character');
            }
            $folded = preg_match('/^[ \t]/', $line) === 1;
            $size = $folded ? $size + strlen($line) + 2 : strlen($line);
            if ($size > $limit) {
                throw new RuntimeException('header size limit exceeded');
            }
            if ($folded && $name !== null) {
                $last = count($headers[$name]) - 1;
                $headers[$name][$last] .= ' ' . trim($line);
                continue;
            }
            if (!preg_match('/^([!-9;-~]+):[ \t]*(.*)$/D', $line, $match)) {
                throw new RuntimeException('invalid header structure');
            }
            $name = strtolower($match[1]);
            $headers[$name][] = trim($match[2]);
        }
        return [$headers, substr($raw, $split + 2)];
    }

    private static function outerHeaders(array $headers): array
    {
        $first = static fn(string $key): ?string => $headers[$key][0] ?? null;
        $from = $first('from') ?? '';
        // Prefer the mailbox in angle brackets so a display name is not selected.
        if (preg_match('/<([^<>]+)>/', $from, $match)) {
            $from = trim($match[1]);
        }
        preg_match('/[a-z0-9.!#$%&\x27*+\/=?^_`{|}~-]+@[a-z0-9.-]+/i', $from, $mailbox);
        $subject = $first('subject');
        if ($subject !== null) {
            $subject = preg_replace('/(\?=)[ \t]+(?==\?)/', '$1', $subject);
            $subject = preg_replace_callback('/=\?([^?]+)\?([bq])\?([^?]*)\?=/i', static function (array $m): string {
                $decoded = strtolower($m[2]) === 'b' ? base64_decode($m[3], true)
                    : quoted_printable_decode(str_replace('_', ' ', $m[3]));
                return self::utf8($decoded === false ? $m[3] : $decoded, $m[1]);
            }, $subject);
        }
        return ['message_id' => self::messageIds($first('message-id') ?? '')[0] ?? null,
            'in_reply_to' => $first('in-reply-to'),
            'references' => self::messageIds(implode(' ', $headers['references'] ?? [])),
            'from_email' => isset($mailbox[0]) ? trim($mailbox[0]) : null,
            'subject' => $subject, 'date' => $first('date'),
            'auto_submitted' => $first('auto-submitted'), 'received_first' => $first('received')];
    }

    private static function messageIds(string $value): array
    {
        preg_match_all('/<[^<>\s@]+@[^<>\s@]+>/', $value, $matches);
        return array_values(array_unique($matches[0]));
    }

    private static function parameter(string $value, string $name): ?string
    {
        $pattern = '/;\s*' . preg_quote($name, '/') . '\s*=\s*(?:"((?:[^"\\\\]|\\\\.)*)"|([^;\s]+))/i';
        if (!preg_match($pattern, $value, $match)) {
            return null;
        }
        return isset($match[2]) ? $match[2] : preg_replace('/\\\\(.)/s', '$1', $match[1]);
    }

    private static function transferDecode(string $body, string $encoding): string
    {
        if ($encoding === 'quoted-printable') {
            return quoted_printable_decode($body);
        }
        if ($encoding === 'base64') {
            $decoded = base64_decode($body, true);
            if ($decoded === false) {
                throw new RuntimeException('invalid base64 body');
            }
            return $decoded;
        }
        if (!in_array($encoding, ['7bit', '8bit', 'binary', ''], true)) {
            throw new RuntimeException('invalid transfer encoding');
        }
        return $body;
    }

    private static function utf8(string $body, string $charset): string
    {
        try {
            return mb_convert_encoding($body, 'UTF-8', $charset);
        } catch (ValueError) {
            return mb_convert_encoding($body, 'UTF-8', 'ISO-8859-1');
        }
    }

    private static function charge(int $bytes, array &$state): void
    {
        $state['decoded'] += $bytes;
        if ($state['decoded'] > $state['limits']['max_decoded_bytes']) {
            throw new RuntimeException('decoded size limit exceeded');
        }
    }

    private static function multipart(string $body, ?string $boundary, int $depth, array &$state): void
    {
        if ($boundary === null || $boundary === '' || strpbrk($boundary, "\r\n") !== false) {
            throw new RuntimeException('invalid multipart boundary');
        }
        $pattern = '/^--' . preg_quote($boundary, '/') . '(--)?[ \t]*(?:\r?\n|$)/m';
        $offset = 0;
        $start = null;
        while (preg_match($pattern, $body, $match, PREG_OFFSET_CAPTURE, $offset)) {
            if ($start !== null) {
                $part = substr($body, $start, $match[0][1] - $start);
                // The CRLF immediately before the delimiter belongs to the delimiter.
                $part = preg_replace('/\r?\n$/D', '', $part);
                self::entity($part, $depth + 1, $state);
            }
            if (($match[1][0] ?? '') === '--') {
                if ($start === null) {
                    throw new RuntimeException('empty multipart boundary structure');
                }
                return;
            }
            $offset = $match[0][1] + strlen($match[0][0]);
            $start = $offset;
        }
        throw new RuntimeException('missing closing multipart boundary');
    }

    private static function collectBody(string $text, bool $html, array &$state): void
    {
        $candidates = [];
        if ($html) {
            preg_match_all('/\bhref\s*=\s*(?:"([^"]*)"|\x27([^\x27]*)\x27|([^\s>]+))/i', $text, $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                $candidates[] = html_entity_decode($match[1] !== '' ? $match[1] : (($match[2] ?? '') !== '' ? $match[2] : ($match[3] ?? '')),
                    ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
            $text = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1\s*>/is', '', $text);
            $text = strip_tags($text);
        }
        $candidates[] = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        foreach ($candidates as $candidate) {
            preg_match_all('~https?://[^\s<>"\x27]+~i', $candidate, $urls);
            foreach ($urls[0] as $url) {
                // Bound nested wrappers without any network resolution.
                for ($i = 0; $i < 5; $i++) {
                    $unwrapped = self::unwrap($url);
                    if ($unwrapped === $url) {
                        break;
                    }
                    $candidate .= "\n" . $unwrapped;
                    $url = $unwrapped;
                }
            }
            preg_match_all('/link-([0-9]{10})\.html/', $candidate, $ids);
            array_push($state['result']['tracking_ids_from_body'], ...$ids[1]);
        }
    }

    private static function unwrap(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false) {
            return $url;
        }
        $host = strtolower($parts['host'] ?? '');
        if ($host === 'safelinks.protection.outlook.com' || str_ends_with($host, '.safelinks.protection.outlook.com')) {
            return self::queryValue($parts['query'] ?? '', 'url') ?? $url;
        }
        if (!in_array($host, ['urldefense.com', 'urldefense.proofpoint.com'], true)) {
            return $url;
        }
        if (str_starts_with($parts['path'] ?? '', '/v2/')) {
            $target = self::queryValue($parts['query'] ?? '', 'u');
            if ($target === null) {
                return $url;
            }
            $decoded = str_replace('_', '/', $target);
            return preg_replace_callback('/-([0-9a-f]{2})/i', static fn(array $m): string => chr(hexdec($m[1])), $decoded);
        }
        if (preg_match('~/v3/__(.*?)__;([A-Za-z0-9_-]*)!~s', $url, $match)) {
            return self::unwrapV3($match[1], $match[2]) ?? $url;
        }
        return $url;
    }

    private static function queryValue(string $query, string $key): ?string
    {
        // Avoid parse_str's global max_input_vars limit and bracket expansion.
        if (preg_match('/(?:^|&)' . preg_quote($key, '/') . '=([^&]*)/', $query, $match)) {
            return urldecode($match[1]);
        }
        return null;
    }

    /** V3 stores replacement characters in the base64url field after __;. */
    private static function unwrapV3(string $url, string $encoded): ?string
    {
        $bytes = base64_decode(strtr($encoded, '-_', '+/'), true);
        if ($bytes === false || !mb_check_encoding($bytes, 'UTF-8')) {
            return null;
        }
        $characters = mb_str_split($bytes, 1, 'UTF-8');
        $offset = 0;
        $valid = true;
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';
        $url = preg_replace('~^(https?:/)([^/])~i', '$1/$2', rawurldecode($url));
        $decoded = preg_replace_callback('/\*(?:\*([A-Za-z0-9_-]))?/',
            static function (array $match) use (&$offset, &$valid, $characters, $alphabet): string {
                $length = isset($match[1]) ? strpos($alphabet, $match[1]) + 2 : 1;
                if ($offset + $length > count($characters)) {
                    $valid = false;
                    return $match[0];
                }
                $replacement = implode('', array_slice($characters, $offset, $length));
                $offset += $length;
                return $replacement;
            }, $url);
        return $valid ? $decoded : null;
    }

}
