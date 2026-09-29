<?php
declare(strict_types=1);

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/DeliveryStateIngest.php';
require_once __DIR__ . '/ReplyMaildir.php';

/**
 * 訓練メールへの返信と戻りメール(DSN)を、訓練の送信元の Maildir から取り込む(段B1、G26 と G04)。Maildir は読むだけ。
 *
 * 送信の処理(bin/send_email.py、v1 と共用)は訓練メールの Message-ID を <t{tracking_id}.{乱数}@{ドメイン}> にしている
 * (2026-09-05 から)。返信のメールソフトは In-Reply-To と References にこの Message-ID を入れるので、そこから
 * tracking_id を取り、campaign_targets でテナント、キャンペーン、宛先を決めて events の reply に入れる。
 *
 * 誤って別のテナントや別の人に数えないための条件(どれかが欠けたら入れず、今の superadmin の返信者の一覧に残す):
 *  - 外側のヘッダの In-Reply-To か References に、tracking の Message-ID がちょうど1つある(本文や転送の中の Message-ID は見ない)
 *  - 届いた Maildir のアドレスが、そのキャンペーンの送信元のアドレスと同じ(報告の Maildir は読まない)
 *  - 差出人が宛先の人のアドレスと同じ(テストの訓練は、振り替え先のテスト用のアドレス)
 *  - 自動の応答(Auto-Submitted、不在の通知など)ではない
 * 戻りメール(multipart/report; report-type=delivery-status、Action: failed)は、中の元のメールの Message-ID から宛先を
 * 決めて「届かない」にする(mail.log と同じ扱い)。どのメールも reply_mails に1行残し、2回数えない。
 */
final class ReplyIngest
{
    private const MAX_BYTES = 512 * 1024;
    private const REPORT_MAILDIR = '/home/report/Maildir';

    /** @return array{reply:int, dsn:int, unmatched:int, ignored:int, scanned:int, skipped:int} */
    public static function run(array $opts = []): array
    {
        $counts = ['reply' => 0, 'dsn' => 0, 'unmatched' => 0, 'ignored' => 0, 'scanned' => 0, 'skipped' => 0];
        $max = max(0, (int) ($opts['max_files'] ?? (getenv('TET2_REPLY_INGEST_MAX_FILES') !== false ? getenv('TET2_REPLY_INGEST_MAX_FILES') : 500)));
        $known = [];
        foreach (Db::all('SELECT maildir_file FROM reply_mails') as $row) {
            $known[$row['maildir_file']] = true;
        }
        foreach (self::maildirs($opts) as $email => $dir) {
            foreach (self::files($dir) as $file) {
                if (isset($known[$file])) {
                    continue;
                }
                if ($counts['scanned'] >= $max) {
                    return $counts;
                }
                $counts['scanned']++;
                try {
                    $status = self::ingestFile($file, strtolower($email));
                } catch (Throwable $error) {
                    // DB の障害で記録できなかったメールは、台帳に残さず次の回にもう一度読む
                    error_log('ReplyIngest: retry ' . basename($file) . ' (' . get_class($error) . ')');
                    $status = null;
                }
                if ($status === null) {
                    $counts['skipped']++;
                    continue;
                }
                $known[$file] = true;
                $key = in_array($status, ['ignored_auto', 'duplicate'], true) ? 'ignored' : ($status === 'from_mismatch' ? 'unmatched' : $status);
                $counts[$key]++;
            }
        }
        return $counts;
    }

    /**
     * 読む Maildir(アドレス => パス)。既定は返信者の一覧と同じ見つけ方(/home/*\/Maildir と postfix の対応表)から、
     * 報告の Maildir を除いたもの。テストは opts['maildirs'] で合成の Maildir を渡す。
     * @return array<string, string>
     */
    private static function maildirs(array $opts): array
    {
        if (isset($opts['maildirs'])) {
            $dirs = $opts['maildirs'];
        } else {
            $dirs = [];
            foreach (discover_maildirs() as $config) {
                $dirs[(string) $config['email']] = (string) $config['maildir'];
            }
        }
        $report = realpath((string) ($opts['report_maildir'] ?? (getenv('TET2_REPORT_MAILDIR') ?: self::REPORT_MAILDIR)));
        $out = [];
        foreach ($dirs as $email => $dir) {
            $dir = rtrim((string) $dir, '/');
            if (!is_dir($dir) || is_link($dir) || ($report !== false && realpath($dir) === $report)) {
                continue;
            }
            $out[(string) $email] = $dir;
        }
        return $out;
    }

    private static function files(string $dir): array
    {
        $files = [];
        foreach (['new', 'cur'] as $sub) {
            $path = $dir . '/' . $sub;
            if (!is_dir($path) || is_link($path)) {
                continue;
            }
            foreach (@scandir($path) ?: [] as $name) {
                $file = $path . '/' . $name;
                if ($name !== '.' && $name !== '..' && !is_link($file) && is_file($file)) {
                    $files[] = $file;
                }
            }
        }
        sort($files);
        return $files;
    }

    /** 1通を読み、台帳に1行残す。読めないメールは null(台帳に残さず、権限が直った後の回に読む)。 */
    private static function ingestFile(string $file, string $maildirEmail): ?string
    {
        if (!is_readable($file)) {
            return null;
        }
        $raw = @file_get_contents($file, false, null, 0, self::MAX_BYTES);
        if ($raw === false || $raw === '') {
            return null;
        }
        $h = self::headers($raw);
        $mail = [
            'message_id_hash' => hash('sha256', $h['message-id'] ?? ('nomsgid:' . hash('sha256', $raw) . ':' . basename($file))),
            'maildir_file' => $file,
            'received_at' => self::receivedAt($file, $h['date'] ?? null),
            'from_email' => self::address($h['from'] ?? ''),
        ];
        if (Db::one('SELECT 1 FROM reply_mails WHERE message_id_hash = ?', [$mail['message_id_hash']]) !== null) {
            // 同じメールが new から cur に移った、または2つの Maildir に届いた。ファイルだけ台帳に足す
            self::record(array_merge($mail, ['message_id_hash' => $mail['message_id_hash'] . ':' . hash('sha256', $file), 'status' => 'duplicate']));
            return 'duplicate';
        }
        if (self::isDsn($h)) {
            return Db::tx(static fn (): string => self::ingestDsn($mail, $raw, $maildirEmail));
        }
        if (self::isAutoReply($h)) {
            self::record($mail + ['status' => 'ignored_auto']);
            return 'ignored_auto';
        }
        return Db::tx(static fn (): string => self::ingestReply($mail, $h, $maildirEmail));
    }

    private static function ingestReply(array $mail, array $h, string $maildirEmail): string
    {
        $ids = [];
        foreach (['in-reply-to', 'references'] as $name) {
            if (preg_match_all('/<t([0-9]{10})\.[^<>\s@]+@[^<>\s@]+>/', $h[$name] ?? '', $m)) {
                $ids = array_merge($ids, $m[1]);
            }
        }
        $ids = array_values(array_unique($ids));
        $target = count($ids) === 1 ? self::campaignTarget($ids[0]) : null;
        if ($target === null || !self::senderMatches($target, $maildirEmail)) {
            self::record($mail + ['status' => 'unmatched']);
            return 'unmatched';
        }
        if (!self::fromMatches($target, (string) $mail['from_email'])) {
            self::record($mail + ['status' => 'from_mismatch']);
            return 'from_mismatch';
        }
        Db::run("INSERT OR IGNORE INTO events (tenant_id, campaign_id, tracking_id, event_type, occurred_at, source, raw)
                 VALUES (?, ?, ?, 'reply', ?, 'reply_mail', ?)",
            [$target['tenant_id'], $target['campaign_id'], $target['tracking_id'], $mail['received_at'],
             json_encode(['message_id_hash' => $mail['message_id_hash']], JSON_UNESCAPED_UNICODE)]);
        $event = Db::one("SELECT id FROM events WHERE tracking_id = ? AND event_type = 'reply' AND occurred_at = ?",
            [$target['tracking_id'], $mail['received_at']]);
        self::record($mail + ['status' => 'reply', 'tracking_id' => $target['tracking_id'], 'tenant_id' => $target['tenant_id'],
            'campaign_id' => $target['campaign_id'], 'event_id' => $event['id'] ?? null]);
        return 'reply';
    }

    /** 戻りメール。Action: failed で、中の元のメールの Message-ID から宛先が1つに決まる時だけ「届かない」にする。 */
    private static function ingestDsn(array $mail, string $raw, string $maildirEmail): string
    {
        $failed = preg_match('/^Action:\s*failed/mi', $raw) === 1;
        $ids = preg_match_all('/^Message-ID:\s*<t([0-9]{10})\./mi', $raw, $m) ? array_values(array_unique($m[1])) : [];
        $target = $failed && count($ids) === 1 ? self::campaignTarget($ids[0]) : null;
        $recipient = preg_match('/^(?:Final|Original)-Recipient:\s*rfc822;\s*<?([^>\s]+)>?/mi', $raw, $r) ? strtolower($r[1]) : '';
        if ($target === null || !self::senderMatches($target, $maildirEmail)
            || ((int) $target['is_test'] === 0 && $recipient !== '' && strcasecmp($recipient, (string) $target['email']) !== 0)) {
            self::record($mail + ['status' => 'unmatched']);
            return 'unmatched';
        }
        $status = preg_match('/^Status:\s*([0-9.]+)/mi', $raw, $s) ? $s[1] : '';
        $diag = preg_match('/^Diagnostic-Code:\s*(.+)$/mi', $raw, $dg) ? trim($dg[1]) : '';
        DeliveryStateIngest::setState((int) $target['id'], 'undeliverable', $mail['received_at'],
            trim('戻りメール' . ($status !== '' ? ' status=' . $status : '') . ' ' . mb_substr($diag, 0, 200)));
        self::record($mail + ['status' => 'dsn', 'tracking_id' => $target['tracking_id'], 'tenant_id' => $target['tenant_id'],
            'campaign_id' => $target['campaign_id']]);
        return 'dsn';
    }

    private static function campaignTarget(string $trackingId): ?array
    {
        return Db::one(
            'SELECT ct.id, ct.tracking_id, ct.campaign_id, ct.from_address AS ct_from, ct.content_no, c.tenant_id, c.is_test,
                    c.from_address AS campaign_from, c.test_redirect_emails, t.email
             FROM campaign_targets ct
             INNER JOIN campaigns c ON c.id = ct.campaign_id AND c.deleted_at IS NULL
             INNER JOIN targets t ON t.id = ct.target_id AND t.tenant_id = c.tenant_id
             WHERE ct.tracking_id = ? AND ct.sent_at IS NOT NULL',
            [$trackingId]
        );
    }

    /** 届いた Maildir がそのキャンペーンの送信元か(返信者の一覧と同じく、ドメインが違えばローカル部で比べる)。 */
    private static function senderMatches(array $target, string $maildirEmail): bool
    {
        $froms = [strtolower((string) $target['ct_from']), strtolower((string) $target['campaign_from'])];
        foreach (Db::all('SELECT from_address FROM campaign_contents WHERE campaign_id = ?', [$target['campaign_id']]) as $row) {
            $froms[] = strtolower((string) $row['from_address']);
        }
        $froms = array_values(array_filter(array_unique($froms), static fn (string $f): bool => $f !== ''));
        return $froms !== [] && reply_maildir_email_matches($maildirEmail, $froms);
    }

    /** 差出人が宛先の人か。テストの訓練は宛先を振り替えるので、振り替え先のアドレスのどれか。 */
    private static function fromMatches(array $target, string $from): bool
    {
        if ($from === '') {
            return false;
        }
        if ((int) $target['is_test'] === 1) {
            preg_match_all('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+/', (string) $target['test_redirect_emails'], $m);
            return in_array(strtolower($from), array_map('strtolower', $m[0]), true);
        }
        return strcasecmp($from, (string) $target['email']) === 0;
    }

    private static function isDsn(array $h): bool
    {
        $type = strtolower($h['content-type'] ?? '');
        return str_contains($type, 'multipart/report') && str_contains($type, 'delivery-status');
    }

    private static function isAutoReply(array $h): bool
    {
        $auto = strtolower(trim($h['auto-submitted'] ?? 'no'));
        if ($auto !== '' && !str_starts_with($auto, 'no')) {
            return true;
        }
        if (isset($h['x-autoreply']) || isset($h['x-autorespond']) || isset($h['x-auto-response-suppress']) && str_contains(strtolower($h['x-auto-response-suppress']), 'oof')) {
            return true;
        }
        return preg_match('/^(auto_reply|bulk|junk|list)$/i', trim($h['precedence'] ?? '')) === 1;
    }

    /** 外側のヘッダだけを小文字の名前で読む(折り返しをつなぐ)。同じ名前は最初のものを使う。 */
    private static function headers(string $raw): array
    {
        $header = preg_split('/\r?\n\r?\n/', $raw, 2)[0];
        $header = preg_replace('/\r?\n[ \t]+/', ' ', $header) ?? '';
        $out = [];
        foreach (preg_split('/\r?\n/', $header) ?: [] as $line) {
            if (preg_match('/^([A-Za-z0-9-]+):[ \t]*(.*)$/', $line, $m)) {
                $out[strtolower($m[1])] ??= trim($m[2]);
            }
        }
        if (isset($out['message-id']) && preg_match('/<[^<>\s]+>/', $out['message-id'], $id)) {
            $out['message-id'] = $id[0];
        }
        return $out;
    }

    private static function address(string $from): ?string
    {
        $from = decode_mime_header($from);
        if (preg_match('/<([^<>\s]+@[^<>\s]+)>/', $from, $m) || preg_match('/([^\s<>"]+@[^\s<>"]+)/', $from, $m)) {
            return strtolower($m[1]);
        }
        return null;
    }

    /** Maildir のファイル名の先頭は届いた時刻(epoch)。なければ Date、それもなければファイルの時刻。 */
    private static function receivedAt(string $file, ?string $date): string
    {
        if (preg_match('/^(\d{9,})\./', basename($file), $m)) {
            return date('Y-m-d H:i:s', (int) $m[1]);
        }
        $ts = $date !== null ? strtotime($date) : false;
        return date('Y-m-d H:i:s', $ts !== false ? $ts : ((int) @filemtime($file) ?: time()));
    }

    private static function record(array $row): void
    {
        $row += ['tracking_id' => null, 'tenant_id' => null, 'campaign_id' => null, 'event_id' => null];
        Db::run('INSERT OR IGNORE INTO reply_mails (message_id_hash, maildir_file, received_at, from_email, tracking_id, tenant_id, campaign_id, status, event_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$row['message_id_hash'], $row['maildir_file'], $row['received_at'], $row['from_email'], $row['tracking_id'],
             $row['tenant_id'], $row['campaign_id'], $row['status'], $row['event_id']]);
    }
}
