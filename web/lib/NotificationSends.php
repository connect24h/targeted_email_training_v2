<?php
/**
 * 自動の通知(種明かしメール、報告の通知)を送った記録(notification_sends)。段D の D2 と D3。
 *
 * - (テナント、種類、重複の鍵)で1行。送る前に行を取り(status='sending')、取れた時だけ送る。
 *   timer の CLI と、訓練を閉じる操作が同時に動いても、同じ人へ同じ条件で2通は送らない。
 * - 送れなかった行(failed)は、MAX_ATTEMPTS 回まで次の実行で取り直して送る。
 * - 送る途中で止まった行(sending のまま)は送り直さない(届いたか分からないので、二重に送る方を避ける)。
 */
declare(strict_types=1);

require_once __DIR__ . '/Db.php';

final class NotificationSends
{
    public const MAX_ATTEMPTS = 3;

    /**
     * 送る権利を取る。取れたら行の id とトークンを返し、取れなければ(送った・送っている・回数切れ) null。
     * @param array{campaign_id?:?int, target_id?:?int, suspicious_mail_id?:?int, token?:bool} $ref
     * @return array{id:int, token:?string}|null
     */
    public static function claim(int $tenantId, string $kind, string $key, string $recipient, array $ref = []): ?array
    {
        $token = !empty($ref['token']) ? bin2hex(random_bytes(24)) : null;
        $inserted = Db::run(
            "INSERT OR IGNORE INTO notification_sends
               (tenant_id, kind, dedupe_key, campaign_id, target_id, suspicious_mail_id, recipient, token, status, attempts)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'sending', 1)",
            [$tenantId, $kind, $key, $ref['campaign_id'] ?? null, $ref['target_id'] ?? null,
                $ref['suspicious_mail_id'] ?? null, $recipient, $token]
        );
        $row = Db::one('SELECT id, token, status, attempts FROM notification_sends WHERE tenant_id = ? AND kind = ? AND dedupe_key = ?',
            [$tenantId, $kind, $key]);
        if ($row === null) {
            return null;
        }
        if ($inserted === 1) {
            return ['id' => (int) $row['id'], 'token' => $row['token'] !== null ? (string) $row['token'] : null];
        }
        // 前に送れなかった行だけ、回数の上限まで取り直す(UPDATE の条件で、同時に取った方の1つだけが取れる)
        $retaken = Db::run(
            "UPDATE notification_sends SET status = 'sending', attempts = attempts + 1
             WHERE id = ? AND status = 'failed' AND attempts < ?",
            [(int) $row['id'], self::MAX_ATTEMPTS]
        );
        return $retaken === 1 ? ['id' => (int) $row['id'], 'token' => $row['token'] !== null ? (string) $row['token'] : null] : null;
    }

    /** 送った結果を残す。 */
    public static function finish(int $id, bool $ok): void
    {
        Db::run(
            $ok ? "UPDATE notification_sends SET status = 'sent', sent_at = datetime('now','localtime') WHERE id = ?"
                : "UPDATE notification_sends SET status = 'failed' WHERE id = ?",
            [$id]
        );
    }

    /** 既に送った(送っている)か。送れずに回数が残っている行は「まだ」。 */
    public static function done(int $tenantId, string $kind, string $key): bool
    {
        $row = Db::one('SELECT status, attempts FROM notification_sends WHERE tenant_id = ? AND kind = ? AND dedupe_key = ?',
            [$tenantId, $kind, $key]);
        if ($row === null) {
            return false;
        }
        return !($row['status'] === 'failed' && (int) $row['attempts'] < self::MAX_ATTEMPTS);
    }

    /** 直近 $minutes 分にテナントへ送った(送っている)通数。報告の通知の上限に使う。 */
    public static function recentCount(int $tenantId, string $kind, int $minutes): int
    {
        $row = Db::one(
            "SELECT COUNT(*) AS n FROM notification_sends
             WHERE tenant_id = ? AND kind = ? AND status IN ('sent','sending')
               AND created_at >= datetime('now','localtime','-' || ? || ' minutes')",
            [$tenantId, $kind, $minutes]
        );
        return (int) ($row['n'] ?? 0);
    }
}
