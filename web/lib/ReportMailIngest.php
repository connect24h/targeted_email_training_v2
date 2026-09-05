<?php
declare(strict_types=1);

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/ReportMailParser.php';

/** Maildir は読み取り専用。確定には対象者の From 一致が必要。 */
final class ReportMailIngest
{
    private const MAX_BYTES = 2 * 1024 * 1024;

    /** @return array{report:int, report_pending:int, scanned:int, skipped:int} */
    public static function run(array $opts = []): array
    {
        $counts = ['report'=>0, 'report_pending'=>0, 'scanned'=>0, 'skipped'=>0];
        $dir = rtrim((string) ($opts['maildir'] ?? (getenv('TET2_REPORT_MAILDIR') ?: '/home/report/Maildir')), '/');
        if (!is_dir($dir) || self::hasSymlink($dir)) { return $counts; }
        $mode = ($opts['mode'] ?? getenv('TET2_REPORT_INGEST_MODE')) === 'match_only' ? 'match_only' : 'normal';
        $max = max(0, (int) ($opts['max_files'] ?? (getenv('TET2_REPORT_INGEST_MAX_FILES') !== false ? getenv('TET2_REPORT_INGEST_MAX_FILES') : 200)));
        $now = $opts['now'] ?? date('Y-m-d H:i:s');
        $now = is_int($now) ? date('Y-m-d H:i:s', $now) : (string) $now;
        $known = $paths = [];
        foreach (Db::all('SELECT message_id_hash, maildir_file FROM report_mails') as $row) {
            $known[$row['message_id_hash']] = true;
            $paths[$row['maildir_file']] = true;
        }
        foreach (self::files($dir) as $file) {
            if (isset($paths[$file])) { $counts['skipped']++; continue; }
            if ($counts['scanned'] >= $max) { break; }
            $counts['scanned']++;
            try {
                if (!is_readable($file) || !(($opts['readable'] ?? 'is_readable')($file))) {
                    $counts['skipped']++; continue;
                }
                $mail = self::readMail($file, $mode, $now);
                if ($mail === null || isset($known[$mail['message_id_hash']])) {
                    $counts['skipped']++; continue;
                }
                $result = Db::tx(static fn(): array => self::persist($mail));
                $known[$mail['message_id_hash']] = true;
                $counts['report'] += $result['report'];
                $counts['report_pending'] += $result['report_pending'];
            } catch (Throwable $error) {
                // DB障害をparse_errorに変換しない。未記録のまま次回再試行する。
                error_log('ReportMailIngest: retry ' . basename($file) . ' (' . get_class($error) . ')');
                $counts['skipped']++;
            }
        }
        return $counts;
    }

    private static function hasSymlink(string $path): bool
    {
        for ($p = $path; $p !== '.' && $p !== '/'; $p = dirname($p)) {
            if (is_link($p)) { return true; }
        }
        return false;
    }

    private static function files(string $dir): array
    {
        $files = [];
        foreach (['new', 'cur'] as $sub) {
            $path = $dir . '/' . $sub;
            if (!is_dir($path) || is_link($path)) { continue; }
            foreach (@scandir($path) ?: [] as $name) {
                $file = $path . '/' . $name;
                if ($name !== '.' && $name !== '..' && !is_link($file) && is_file($file)) { $files[] = $file; }
            }
        }
        usort($files, static fn(string $a, string $b): int => strcmp(basename($a), basename($b)) ?: strcmp($a, $b));
        return $files;
    }

    private static function readMail(string $file, string $mode, string $now): ?array
    {
        // サイズ判定はparserより先。大きな本文は保持せずストリームでハッシュ化する。
        if (self::hasSymlink($file) || !is_file($file)) { return null; }
        $size = @filesize($file);
        if ($size === false) { return null; }
        $oversize = $size > self::MAX_BYTES;
        $raw = $oversize ? self::readHeader($file) : @file_get_contents($file, false, null, 0, self::MAX_BYTES + 1);
        if ($raw === false) { return null; }
        $oversize = $oversize || strlen($raw) > self::MAX_BYTES;
        $contentHash = $oversize ? @hash_file('sha256', $file) : hash('sha256', $raw);
        if ($contentHash === false) { return null; }
        $parsed = $oversize ? null : ReportMailParser::parse($raw);
        $headers = $parsed['headers'] ?? self::fallbackHeaders($raw);
        $messageId = $headers['message_id'] ?? self::outerMessageId($raw);
        preg_match('/^(\d+)\./', basename($file), $epoch);
        $status = $oversize ? 'ignored_oversize' : (($parsed['ok'] ?? false) ? 'parsed' : 'parse_error');
        if ($status === 'parsed') {
            if (self::isSelf($headers['from_email'])) { $status = 'ignored_self'; }
            elseif ($parsed['is_auto_submitted']) { $status = 'ignored_auto'; }
        }
        return ['message_id_hash'=>hash('sha256', $messageId ?? ('nomsgid:' . $contentHash . ':' . ($epoch[1] ?? ''))),
            'message_id'=>$messageId, 'content_hash'=>$contentHash, 'maildir_file'=>$file,
            'received_at'=>self::receivedAt($file, $headers['received_first'] ?? null),
            'date_header'=>$headers['date'] ?? null, 'from_email'=>$headers['from_email'] ?? null,
            'subject_head'=>mb_substr($headers['subject'] ?? '', 0, 120, 'UTF-8'),
            'parse_status'=>$status, 'parse_error'=>$parsed['error'] ?? null,
            'ingest_mode'=>$mode, 'created_at'=>$now, 'parsed'=>$parsed];
    }

    /** サイズ超過メールの本文は保持せず、外側ヘッダだけ保存用に読む。 */
    private static function readHeader(string $file): string|false
    {
        $handle = @fopen($file, 'rb');
        if ($handle === false) { return false; }
        try {
            $header = '';
            while (($line = fgets($handle)) !== false) {
                if (rtrim($line, "\r\n") === '') { break; }
                $header .= $line;
            }
            return $header;
        } finally { fclose($handle); }
    }

    /** oversizeではMIME parserを呼ばず、保存に必要なヘッダのみ抽出する。 */
    private static function fallbackHeaders(string $raw): array
    {
        $header = preg_split('/\r?\n\r?\n/', $raw, 2)[0];
        $header = preg_replace('/\r?\n[ \t]+/', ' ', $header);
        $values = [];
        foreach (['from', 'subject', 'date', 'received'] as $name) {
            if (preg_match('/^' . $name . ':[ \t]*(.*)$/mi', $header, $m)) { $values[$name] = trim($m[1]); }
        }
        $from = $values['from'] ?? '';
        if (preg_match('/<([^<>]+)>/', $from, $m)) { $from = trim($m[1]); }
        preg_match('/[a-z0-9.!#$%&\x27*+\/=?^_`{|}~-]+@[a-z0-9.-]+/i', $from, $email);
        return ['from_email'=>$email[0] ?? null, 'subject'=>mb_decode_mimeheader($values['subject'] ?? ''),
            'date'=>$values['date'] ?? null, 'received_first'=>$values['received'] ?? null];
    }

    /** 外側ヘッダだけを読む。oversize/構文エラーでもMessage-IDで冪等にする。 */
    private static function outerMessageId(string $raw): ?string
    {
        $header = preg_split('/\r?\n\r?\n/', $raw, 2)[0];
        $header = preg_replace('/\r?\n[ \t]+/', ' ', $header);
        if (preg_match('/^Message-ID:[ \t]*(.*)$/mi', $header, $m)
            && preg_match('/<[^<>\s@]+@[^<>\s@]+>/', $m[1], $id)) { return $id[0]; }
        return null;
    }

    private static function receivedAt(string $file, ?string $received): string
    {
        if (preg_match('/^(\d+)\./', basename($file), $m)) { return date('Y-m-d H:i:s', (int) $m[1]); }
        if ($received !== null) {
            $date = str_contains($received, ';') ? substr($received, strrpos($received, ';') + 1) : $received;
            $epoch = strtotime(trim($date));
            if ($epoch !== false) { return date('Y-m-d H:i:s', $epoch); }
        }
        $mtime = @filemtime($file);
        if ($mtime === false) { throw new RuntimeException('filemtime failed'); }
        return date('Y-m-d H:i:s', $mtime);
    }

    private static function isSelf(?string $from): bool
    {
        if ($from === null) { return false; }
        return Db::one('SELECT 1 FROM campaigns WHERE from_address = ? COLLATE NOCASE
                       UNION ALL SELECT 1 FROM campaign_contents WHERE from_address = ? COLLATE NOCASE LIMIT 1', [$from, $from]) !== null;
    }

    private static function persist(array $mail): array
    {
        $parsed = $mail['parsed'];
        unset($mail['parsed']);
        $cols = implode(', ', array_keys($mail));
        $marks = implode(',', array_fill(0, count($mail), '?'));
        Db::run("INSERT INTO report_mails ($cols) VALUES ($marks)", array_values($mail));
        $mail['id'] = Db::one('SELECT id FROM report_mails WHERE message_id_hash=?', [$mail['message_id_hash']])['id'];
        $counts = ['report'=>0, 'report_pending'=>0];
        if ($mail['parse_status'] !== 'parsed') { return $counts; }
        $ids = array_unique(array_merge($parsed['tracking_ids_from_msgid'], $parsed['tracking_ids_from_body']));
        if ($ids === []) {
            $candidates = self::senderCandidates($mail);
            if (count($candidates) === 1) { self::saveMatch($mail, $candidates[0], ['method'=>'sender', 'reason'=>'sender_unique', 'confirmed'=>false]); $counts['report_pending']++; }
            // parsed + matchesなし が unmatched を表す。
            return $counts;
        }
        foreach ($ids as $id) {
            $candidate = Db::one('SELECT ct.tracking_id, ct.campaign_id, ct.sent_at, c.tenant_id, c.deleted_at,
                                 t.email, t.tenant_id AS target_tenant FROM campaign_targets ct
                                 JOIN campaigns c ON c.id=ct.campaign_id JOIN targets t ON t.id=ct.target_id
                                 WHERE ct.tracking_id=?', [$id]);
            $reason = self::reason($candidate, $mail['from_email']);
            $meta = ['method'=>in_array($id, $parsed['tracking_ids_from_msgid'], true) ? 'msgid' : 'body',
                'reason'=>$reason, 'confirmed'=>$reason === 'from_match'];
            $r = self::saveMatch($mail, $candidate ?? ['tracking_id'=>$id], $meta);
            $counts['report'] += $r['report'];
            $counts['report_pending'] += $r['report_pending'];
        }
        return $counts;
    }

    private static function reason(?array $c, ?string $from): string
    {
        if ($c === null) { return 'unknown_tracking_id'; }
        if ($c['tenant_id'] !== $c['target_tenant']) { return 'target_tenant_mismatch'; }
        if ($c['sent_at'] === null) { return 'not_sent'; }
        if ($c['deleted_at'] !== null) { return 'campaign_deleted'; }
        return $from !== null && strcasecmp($c['email'], $from) === 0 ? 'from_match' : 'from_mismatch';
    }

    private static function senderCandidates(array $mail): array
    {
        return Db::all("SELECT ct.tracking_id, ct.campaign_id, c.tenant_id FROM targets t
                       JOIN campaign_targets ct ON ct.target_id=t.id
                       JOIN campaigns c ON c.id=ct.campaign_id AND c.tenant_id=t.tenant_id
                       WHERE t.email=? COLLATE NOCASE AND t.status='active'
                         AND ct.sent_at IS NOT NULL AND c.deleted_at IS NULL
                         AND julianday(?) BETWEEN julianday(ct.sent_at) AND julianday(ct.sent_at, '+60 days')",
            [$mail['from_email'], $mail['received_at']]);
    }

    /** 呼び出し側でDb::txを開始する。手動判断はingest_modeに優先する。 */
    public static function confirmMatch(int $matchId, string $decidedBy): array
    {
        $match = self::pendingMatch($matchId);
        $mail = Db::one('SELECT * FROM report_mails WHERE id=?', [$match['report_mail_id']]);
        [$eventId] = self::reportEvent($match, $mail);
        Db::run("UPDATE report_mail_matches SET status='confirmed',event_id=?,decided_by=?,decided_at=? WHERE id=? AND status='pending'",
            [$eventId, $decidedBy, date('Y-m-d H:i:s'), $matchId]);
        return ['id'=>$matchId, 'tracking_id'=>$match['tracking_id'], 'event_id'=>$eventId];
    }

    /** 呼び出し側でDb::txを開始する。 */
    public static function rejectMatch(int $matchId, string $decidedBy): void
    {
        self::pendingMatch($matchId);
        Db::run("UPDATE report_mail_matches SET status='rejected',decided_by=?,decided_at=? WHERE id=? AND status='pending'",
            [$decidedBy, date('Y-m-d H:i:s'), $matchId]);
    }

    private static function pendingMatch(int $matchId): array
    {
        $match = Db::one('SELECT * FROM report_mail_matches WHERE id=?', [$matchId]);
        if ($match === null) { throw new DomainException('報告メールが見つかりません', 404); }
        if ($match['status'] !== 'pending') { throw new DomainException('保留中の報告メールではありません', 409); }
        return $match;
    }

    /** 自動・手動とも追跡ID単位の既存reportを再利用する。 */
    private static function reportEvent(array $candidate, array $mail): array
    {
        $inserted = 0;
        $existing = Db::one("SELECT id FROM events WHERE tracking_id=? AND event_type='report' ORDER BY occurred_at, id LIMIT 1", [$candidate['tracking_id']]);
        if ($existing === null) {
            $inserted = Db::run("INSERT INTO events (tenant_id,campaign_id,tracking_id,event_type,occurred_at,source,raw)
                        VALUES (?,?,?,'report',?,'report_mail',?)",
                [$candidate['tenant_id'], $candidate['campaign_id'], $candidate['tracking_id'], $mail['received_at'],
                 json_encode(['message_id'=>$mail['message_id'], 'subject_head'=>$mail['subject_head']], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)]);
            $existing = Db::one("SELECT id FROM events WHERE tracking_id=? AND event_type='report' ORDER BY occurred_at, id LIMIT 1", [$candidate['tracking_id']]);
        }
        $eventId = $existing['id'];
        return [$eventId, $inserted];
    }

    private static function saveMatch(array $mail, array $candidate, array $meta): array
    {
        $status = $meta['reason'] === 'unknown_tracking_id' ? 'rejected' : 'pending';
        $eventId = null;
        $inserted = 0;
        if ($meta['confirmed'] && $mail['ingest_mode'] === 'normal') {
            $status = 'confirmed';
            [$eventId, $inserted] = self::reportEvent($candidate, $mail);
        }
        Db::run('INSERT INTO report_mail_matches (report_mail_id,tracking_id,tenant_id,campaign_id,method,evidence,status,event_id,decided_by,decided_at,created_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?)',
            [$mail['id'], $candidate['tracking_id'], $candidate['tenant_id'] ?? null, $candidate['campaign_id'] ?? null,
             $meta['method'], json_encode(['reason'=>$meta['reason']], JSON_UNESCAPED_UNICODE), $status, $eventId,
             $status === 'confirmed' ? 'system' : null, $status === 'confirmed' ? $mail['created_at'] : null, $mail['created_at']]);
        return ['report'=>$inserted, 'report_pending'=>$status === 'pending' ? 1 : 0];
    }
}
