<?php
/**
 * 担当者への不審メールの報告の通知(段D の D3、G38)。
 *
 * - テナントが登録した社内の担当者のアドレス(tenant_report_notify.emails)へ、報告用のアドレスに届いた不審メールの報告
 *   (suspicious_mails の source='maildir'、訓練のメールでないもの)を1件ずつ知らせる。既定は空で、空なら1通も送らない。
 * - 通知先を入れた日時(since)より前に取り込んだ報告と、LOOKBACK_DAYS 日より前の報告には送らない(入れた時に過去の分が一度に届かないように)。
 * - 本文に入れるのは、件名(先頭の120文字)、差出人のアドレス、報告者、受信日時、管理画面の URL だけ(本文と添付は入れない)。
 * - 1つのテナントへ、直近 RATE_WINDOW_MINUTES 分に RATE_LIMIT 通まで。超えた分は送らずに残し、次の実行で送る。
 * - 報告と担当者のアドレスの組で1通(notification_sends)。
 */
declare(strict_types=1);

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/EduMailer.php';
require_once __DIR__ . '/NotificationTemplates.php';
require_once __DIR__ . '/NotificationSends.php';
require_once __DIR__ . '/TenantStatus.php';
require_once __DIR__ . '/UserPasswordTokens.php';

final class ReportNotify
{
    public const KIND = 'report_notify';
    public const MAX_EMAILS = 10;
    public const RATE_LIMIT = 30;
    public const RATE_WINDOW_MINUTES = 60;
    public const LOOKBACK_DAYS = 7;
    /** 報告者のアドレスが形の悪い時に入れる文字。 */
    public const UNKNOWN = '(不明)';

    /** @return array{emails:list<string>, since:?string, updated_by:?string, updated_at:?string} */
    public static function settings(int $tenantId): array
    {
        $row = Db::one('SELECT emails, since, updated_by, updated_at FROM tenant_report_notify WHERE tenant_id = ?', [$tenantId]);
        return [
            'emails' => $row === null ? [] : self::split((string) $row['emails']),
            'since' => $row['since'] ?? null,
            'updated_by' => $row['updated_by'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    /**
     * 通知先を保存する。空にすると通知しない。空から入れた時だけ since を今にする。
     * @param list<string>|string $emails 改行・カンマ区切りの文字列か配列
     * @throws DomainException 形の悪いアドレス、多すぎる
     */
    public static function save(int $tenantId, array|string $emails, ?string $actor): array
    {
        $list = self::normalize($emails);
        $before = self::settings($tenantId);
        $since = $list === [] ? null : ($before['emails'] !== [] && $before['since'] !== null ? $before['since'] : date('Y-m-d H:i:s'));
        Db::run(
            "INSERT INTO tenant_report_notify (tenant_id, emails, since, updated_by, updated_at)
             VALUES (?, ?, ?, ?, datetime('now','localtime'))
             ON CONFLICT (tenant_id) DO UPDATE SET emails = excluded.emails, since = excluded.since,
                 updated_by = excluded.updated_by, updated_at = excluded.updated_at",
            [$tenantId, implode("\n", $list), $since, $actor]
        );
        return self::settings($tenantId);
    }

    /** @return list<string> 重複を除いた、小文字にしたアドレス */
    public static function normalize(array|string $emails): array
    {
        $parts = is_array($emails) ? $emails : preg_split('/[\s,;、]+/u', $emails);
        $out = [];
        foreach ($parts as $p) {
            if (!is_string($p)) {
                throw new DomainException('アドレスは文字列で指定してください', 400);
            }
            $p = strtolower(trim($p));
            if ($p === '') {
                continue;
            }
            if (filter_var($p, FILTER_VALIDATE_EMAIL) === false || preg_match('/[\r\n]/', $p) === 1) {
                throw new DomainException('メールアドレスの形が正しくありません: ' . mb_substr($p, 0, 100), 400);
            }
            $out[$p] = true;
        }
        if (count($out) > self::MAX_EMAILS) {
            throw new DomainException('通知先は' . self::MAX_EMAILS . '件までです', 400);
        }
        return array_keys($out);
    }

    /**
     * 送る(timer の CLI から)。
     * @return array{sent:int, failed:int, deferred:int}
     */
    public static function run(array $opts = []): array
    {
        $result = ['sent' => 0, 'failed' => 0, 'deferred' => 0];
        $tenantSql = TenantStatus::operationalSql('r.tenant_id');
        $params = [];
        $filter = '';
        if (isset($opts['tenant_id'])) {
            $filter = ' AND r.tenant_id = ?';
            $params[] = (int) $opts['tenant_id'];
        }
        $settings = Db::all("SELECT r.tenant_id, r.emails, r.since FROM tenant_report_notify r
                             WHERE r.emails <> '' AND r.since IS NOT NULL AND {$tenantSql}{$filter} ORDER BY r.tenant_id", $params);
        foreach ($settings as $s) {
            $tenantId = (int) $s['tenant_id'];
            $emails = self::split((string) $s['emails']);
            $reports = Db::all(
                "SELECT id, reporter_email, subject, from_email, received_at FROM suspicious_mails
                 WHERE tenant_id = ? AND source = 'maildir' AND is_training = 0
                   AND created_at >= ? AND created_at >= datetime('now','localtime','-" . self::LOOKBACK_DAYS . " days')
                 ORDER BY id",
                [$tenantId, (string) $s['since']]
            );
            foreach ($reports as $report) {
                foreach ($emails as $to) {
                    $key = 's' . (int) $report['id'] . ':' . $to;
                    if (NotificationSends::done($tenantId, self::KIND, $key)) {
                        continue;
                    }
                    if (NotificationSends::recentCount($tenantId, self::KIND, self::RATE_WINDOW_MINUTES) >= self::RATE_LIMIT) {
                        $result['deferred']++;
                        continue;
                    }
                    $outcome = self::sendOne($tenantId, $key, $to, $report);
                    if ($outcome === true) {
                        $result['sent']++;
                    } elseif ($outcome === false) {
                        $result['failed']++;
                    }
                }
            }
        }
        return $result;
    }

    private static function sendOne(int $tenantId, string $key, string $to, array $report): ?bool
    {
        $claim = NotificationSends::claim($tenantId, self::KIND, $key, $to, ['suspicious_mail_id' => (int) $report['id']]);
        if ($claim === null) {
            return null;
        }
        $mail = NotificationTemplates::render($tenantId, self::KIND, self::vars($report));
        $ok = EduMailer::send($to, $mail['subject'], $mail['body']);
        NotificationSends::finish($claim['id'], $ok);
        return $ok;
    }

    /**
     * 差し込みの値。報告のメールの件名・差出人・報告者は外から来る値なので、どれも1行に直す(改行と制御文字を空白に)。
     * 報告者はアドレスの形でなければ「(不明)」にする。
     * @return array<string,string>
     */
    public static function vars(array $report): array
    {
        $reporter = self::oneLine((string) ($report['reporter_email'] ?? ''));
        return [
            '報告者' => filter_var($reporter, FILTER_VALIDATE_EMAIL) !== false ? $reporter : self::UNKNOWN,
            '件名' => mb_substr(self::oneLine((string) ($report['subject'] ?? '')), 0, 120),
            '差出人' => mb_substr(self::oneLine((string) ($report['from_email'] ?? '')), 0, 254),
            '受信日時' => substr(self::oneLine((string) ($report['received_at'] ?? '')), 0, 16),
            '管理画面URL' => UserPasswordTokens::adminBaseUrl() . '/#suspiciousMails',
        ];
    }

    /** @return list<string> */
    private static function split(string $emails): array
    {
        return array_values(array_filter(array_map('trim', explode("\n", $emails)), static fn(string $e): bool => $e !== ''));
    }

    private static function oneLine(string $s): string
    {
        return trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $s) ?? '');
    }
}
