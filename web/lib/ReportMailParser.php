<?php
declare(strict_types=1);

/**
 * Pure MIME parser for matching; no persistence, I/O, or sender trust decisions.
 *
 * `collect` オプションを true にすると、照合用の結果に加えて解析用の情報
 * （メッセージごとの全ヘッダー・本文テキスト・URL・添付メタ）を result['analysis'] に積む。
 * collect が false（既定）のときは出力キー・例外・上限のいずれも従来と変わらない。
 */
final class ReportMailParser
{
    /** 解析用に保持する本文テキストの上限（メッセージごと、text / html_text それぞれ）。 */
    public const COLLECT_TEXT_BYTES = 256 * 1024;
    /** zip 添付から読むエントリ名の上限。 */
    public const COLLECT_ZIP_ENTRIES = 200;

    /**
     * Options: max_raw_bytes, max_depth, max_parts, max_decoded_bytes, max_header_bytes, collect.
     * The outer entity is depth 0 and counts as one part. Encapsulated messages also count.
     * Decoded bytes count each decoded container and each text part (including UTF-8 expansion).
     */
    public static function parse(string $raw, array $opts = []): array
    {
        $collect = (bool) ($opts['collect'] ?? false);
        unset($opts['collect']);
        $result = [
            'ok' => false, 'error' => null,
            'headers' => ['message_id' => null, 'in_reply_to' => null, 'references' => [],
                'from_email' => null, 'subject' => null, 'date' => null,
                'auto_submitted' => null, 'received_first' => null],
            'message_ids' => [], 'tracking_ids_from_msgid' => [], 'tracking_ids_from_body' => [],
            'is_auto_submitted' => false,
        ];
        if ($collect) {
            $result['analysis'] = ['messages' => []];
        }
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
            $state = ['limits' => $limits, 'parts' => 0, 'decoded' => 0, 'result' => $result,
                'collect' => $collect, 'current' => null, 'stack' => []];
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

    /** @param bool $message true のとき、このエンティティは（外側または message/rfc822 の）メッセージ本体。 */
    private static function entity(string $raw, int $depth, array &$state, bool $message = true): void
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
        if ($state['collect'] && $message) {
            self::openMessage($headers, $depth, $state);
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
            if ($state['collect']) {
                self::collectAttachment($headers, $body, $type, $state);
            }
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
            self::entity($body, $depth + 1, $state, true);
            if ($state['collect']) {
                $state['current'] = array_pop($state['stack']);
            }
            return;
        }
        $text = self::utf8($body, self::parameter($contentType, 'charset') ?? 'US-ASCII');
        self::charge(max(strlen($body), strlen($text)), $state);
        if ($state['collect'] && self::attachmentName($headers) !== null
            && str_starts_with(strtolower(trim($headers['content-disposition'][0] ?? '')), 'attachment')) {
            // 添付として付いたテキスト（.txt / .htm）は添付一覧にも載せる。本文の URL 走査は従来どおり行う。
            self::recordAttachment(self::attachmentName($headers), $type, $body, $state);
        }
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
        $subject = $first('subject');
        if ($subject !== null) {
            $subject = self::decodeWords($subject);
        }
        return ['message_id' => self::messageIds($first('message-id') ?? '')[0] ?? null,
            'in_reply_to' => $first('in-reply-to'),
            'references' => self::messageIds(implode(' ', $headers['references'] ?? [])),
            'from_email' => self::mailbox($first('from') ?? '')['email'],
            'subject' => $subject, 'date' => $first('date'),
            'auto_submitted' => $first('auto-submitted'), 'received_first' => $first('received')];
    }

    /** From 等のヘッダー値からメールアドレスと表示名を取り出す。 */
    private static function mailbox(string $value): array
    {
        $decoded = self::decodeWords($value);
        $address = $decoded;
        $name = null;
        // Prefer the mailbox in angle brackets so a display name is not selected.
        if (preg_match('/^(.*?)<([^<>]+)>/s', $decoded, $match)) {
            $address = trim($match[2]);
            $name = trim($match[1], " \t\"'");
        }
        preg_match('/[a-z0-9.!#$%&\x27*+\/=?^_`{|}~-]+@[a-z0-9.-]+/i', $address, $mailbox);
        return ['email' => isset($mailbox[0]) ? trim($mailbox[0]) : null,
            'name' => $name !== null && $name !== '' ? $name : null];
    }

    /** RFC 2047 encoded-word を UTF-8 に復号する。 */
    private static function decodeWords(string $value): string
    {
        $value = preg_replace('/(\?=)[ \t]+(?==\?)/', '$1', $value);
        return preg_replace_callback('/=\?([^?]+)\?([bq])\?([^?]*)\?=/i', static function (array $m): string {
            $decoded = strtolower($m[2]) === 'b' ? base64_decode($m[3], true)
                : quoted_printable_decode(str_replace('_', ' ', $m[3]));
            return self::utf8($decoded === false ? $m[3] : $decoded, $m[1]);
        }, $value);
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
                self::entity($part, $depth + 1, $state, false);
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
        $anchors = [];
        if ($html) {
            preg_match_all('/\bhref\s*=\s*(?:"([^"]*)"|\x27([^\x27]*)\x27|([^\s>]+))/i', $text, $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                $candidates[] = html_entity_decode($match[1] !== '' ? $match[1] : (($match[2] ?? '') !== '' ? $match[2] : ($match[3] ?? '')),
                    ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
            if ($state['collect']) {
                // 表示文字列と href の食い違いを見るため、<a> 単位でも拾う。
                preg_match_all('/<a\b[^>]*\bhref\s*=\s*(?:"([^"]*)"|\x27([^\x27]*)\x27|([^\s>]+))[^>]*>(.*?)<\/a\s*>/is', $text, $links, PREG_SET_ORDER);
                foreach ($links as $link) {
                    $href = html_entity_decode($link[1] !== '' ? $link[1] : (($link[2] ?? '') !== '' ? $link[2] : ($link[3] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    $label = trim(html_entity_decode(strip_tags($link[4]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                    $anchors[$href] = $label;
                }
            }
            $text = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1\s*>/is', '', $text);
            $text = strip_tags($text);
        }
        $plain = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $candidates[] = $plain;
        if ($state['collect']) {
            self::recordText($plain, $html, $state);
        }
        foreach ($candidates as $index => $candidate) {
            $fromHref = $html && $index < count($candidates) - 1;
            preg_match_all('~https?://[^\s<>"\x27]+~i', $candidate, $urls);
            foreach ($urls[0] as $url) {
                $raw = $url;
                // Bound nested wrappers without any network resolution.
                for ($i = 0; $i < 5; $i++) {
                    $unwrapped = self::unwrap($url);
                    if ($unwrapped === $url) {
                        break;
                    }
                    $candidate .= "\n" . $unwrapped;
                    $url = $unwrapped;
                }
                // <a> の表示文字列が URL 形の場合、それはリンク先ではなく見せかけなので URL 一覧には載せない。
                if ($state['collect'] && !(!$fromHref && $html && in_array($raw, $anchors, true))) {
                    self::recordUrl($raw, $url, $fromHref ? ($anchors[$raw] ?? null) : null, $fromHref ? 'href' : 'text', $state);
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

    /* ========== collect モード（解析用の情報収集） ========== */

    /** メッセージ本体（外側または message/rfc822 の内側）の記録を開き、以後の本文・URL・添付の行き先にする。 */
    private static function openMessage(array $headers, int $depth, array &$state): void
    {
        $first = static fn(string $key): ?string => $headers[$key][0] ?? null;
        $from = self::mailbox($first('from') ?? '');
        $decoded = [];
        foreach (['subject', 'to', 'cc', 'reply-to', 'return-path', 'sender'] as $name) {
            $decoded[$name] = $first($name) === null ? null : self::decodeWords($first($name));
        }
        $state['stack'][] = $state['current'];
        $state['result']['analysis']['messages'][] = [
            'depth' => $depth,
            'headers' => $headers,
            'subject' => $decoded['subject'],
            'from_email' => $from['email'],
            'from_name' => $from['name'],
            'to' => $decoded['to'],
            'cc' => $decoded['cc'],
            'reply_to' => self::mailbox($decoded['reply-to'] ?? '')['email'],
            'return_path' => self::mailbox($decoded['return-path'] ?? '')['email'],
            'sender' => self::mailbox($decoded['sender'] ?? '')['email'],
            'date' => $first('date'),
            'message_id' => self::messageIds($first('message-id') ?? '')[0] ?? null,
            'received' => $headers['received'] ?? [],
            'auth_results' => $headers['authentication-results'] ?? [],
            'received_spf' => $headers['received-spf'] ?? [],
            'text' => '',
            'html_text' => '',
            'urls' => [],
            'attachments' => [],
        ];
        $state['current'] = count($state['result']['analysis']['messages']) - 1;
    }

    private static function &currentMessage(array &$state): array
    {
        return $state['result']['analysis']['messages'][$state['current']];
    }

    private static function recordText(string $plain, bool $html, array &$state): void
    {
        $message = &self::currentMessage($state);
        $key = $html ? 'html_text' : 'text';
        $room = self::COLLECT_TEXT_BYTES - strlen($message[$key]);
        if ($room <= 0) {
            return;
        }
        $chunk = ($message[$key] === '' ? '' : "\n") . $plain;
        $message[$key] .= mb_strcut($chunk, 0, $room, 'UTF-8');
    }

    private static function recordUrl(string $raw, string $unwrapped, ?string $display, string $location, array &$state): void
    {
        $message = &self::currentMessage($state);
        foreach ($message['urls'] as &$existing) {
            if ($existing['raw'] === $raw) {
                if ($display !== null && $existing['display'] === null) {
                    $existing['display'] = $display;
                }
                return;
            }
        }
        unset($existing);
        $message['urls'][] = ['raw' => $raw, 'unwrapped' => $unwrapped, 'display' => $display, 'location' => $location];
    }

    /** 添付ファイル名（Content-Disposition filename / Content-Type name）。RFC 2231 の filename* も最低限扱う。 */
    private static function attachmentName(array $headers): ?string
    {
        $disposition = $headers['content-disposition'][0] ?? '';
        $contentType = $headers['content-type'][0] ?? '';
        if (preg_match('/;\s*filename\*\s*=\s*([^\x27]*)\x27[^\x27]*\x27([^;\s]+)/i', $disposition, $match)) {
            $charset = $match[1] !== '' ? $match[1] : 'UTF-8';
            return self::utf8(rawurldecode($match[2]), $charset);
        }
        $name = self::parameter($disposition, 'filename') ?? self::parameter($contentType, 'name');
        if ($name === null || trim($name) === '') {
            return null;
        }
        return trim(self::decodeWords($name));
    }

    /** text 以外のパートを添付として記録する。本文は転送デコードしてハッシュだけ取り、内容は保持しない。 */
    private static function collectAttachment(array $headers, string $body, string $type, array &$state): void
    {
        $encoding = strtolower(trim($headers['content-transfer-encoding'][0] ?? '7bit'));
        try {
            $bytes = self::transferDecode($body, $encoding);
        } catch (RuntimeException) {
            // 壊れた添付は生のまま数える（照合時は無視される部分なので例外にしない）。
            $bytes = $body;
        }
        self::charge(strlen($bytes), $state);
        $name = self::attachmentName($headers) ?? ('unnamed.' . self::extensionFor($type));
        self::recordAttachment($name, $type, $bytes, $state);
    }

    private static function recordAttachment(string $name, string $type, string $bytes, array &$state): void
    {
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $entry = ['filename' => $name, 'content_type' => $type, 'size' => strlen($bytes),
            'sha256' => hash('sha256', $bytes), 'extension' => $extension, 'zip_entries' => []];
        if ($extension === 'zip' && class_exists('ZipArchive') && str_starts_with($bytes, 'PK')) {
            $entry['zip_entries'] = self::zipEntries($bytes);
        }
        $message = &self::currentMessage($state);
        $message['attachments'][] = $entry;
    }

    /** zip 内のエントリ名だけを読む（展開しない）。読めなければ空配列。 */
    private static function zipEntries(string $bytes): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'tet2-eml-zip-');
        if ($tmp === false) {
            return [];
        }
        try {
            file_put_contents($tmp, $bytes);
            $zip = new ZipArchive();
            if ($zip->open($tmp, ZipArchive::RDONLY) !== true) {
                return [];
            }
            $names = [];
            for ($i = 0; $i < min($zip->numFiles, self::COLLECT_ZIP_ENTRIES); $i++) {
                $name = $zip->getNameIndex($i);
                if (is_string($name) && $name !== '') {
                    $names[] = $name;
                }
            }
            $zip->close();
            return $names;
        } catch (Throwable) {
            return [];
        } finally {
            @unlink($tmp);
        }
    }

    private static function extensionFor(string $type): string
    {
        return match ($type) {
            'application/pdf' => 'pdf',
            'application/zip', 'application/x-zip-compressed' => 'zip',
            'image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif',
            default => 'bin',
        };
    }
}
