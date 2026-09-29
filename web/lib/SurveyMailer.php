<?php
/**
 * アンケートの案内メールと締切前の催促。
 *
 * 実在の従業員へメールが届くため、送信は環境変数 TET2_SURVEY_MAIL_ENABLED=1 のときだけ行う。
 * 既定は停止で、API は 409、CLI は何も送らずに終わる。有効化は利用者の明示の承認を得てから行う。
 * SMTP の投函は EduMailer を再利用する(テストでは TET2_EDU_MAIL_DISABLE=1 で投函だけを省く)。
 */
declare(strict_types=1);

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/EduMailer.php';
require_once __DIR__ . '/NotificationTemplates.php';
require_once __DIR__ . '/SurveyService.php';
require_once __DIR__ . '/TenantStatus.php';

final class SurveyMailer
{
    /** 締切のこの日数前から催促の対象にする。 */
    private const REMIND_BEFORE_DAYS = 2;

    public static function enabled(): bool
    {
        return getenv('TET2_SURVEY_MAIL_ENABLED') === '1';
    }

    public static function surveyUrl(string $token): string
    {
        return EduMailer::baseUrl() . '/survey.php?token=' . rawurlencode($token);
    }

    /**
     * まだ案内していない未回答者へ案内メールを送る。
     * @return array{targets:int, sent:int, failed:int}
     */
    public static function sendInvitations(int $tenantId, int $deliveryId): array
    {
        self::assertEnabled();
        self::assertTenantOperational($tenantId);
        $delivery = self::openDelivery($tenantId, $deliveryId);
        $rows = Db::all(
            "SELECT a.id, a.access_token, t.email, t.name FROM survey_assignments a
             INNER JOIN targets t ON t.id = a.target_id
             WHERE a.delivery_id = ? AND a.tenant_id = ? AND a.status = 'assigned' AND a.invited_at IS NULL
               AND t.status = 'active'",
            [$deliveryId, $tenantId]
        );
        $result = self::sendAll($tenantId, $rows, (string) $delivery['survey_title'], $delivery['deadline'], false, 'invited_at');
        if ($result['sent'] > 0) {
            Db::run("UPDATE survey_deliveries SET invited_at = datetime('now','localtime') WHERE id = ?", [$deliveryId]);
        }
        return $result;
    }

    /**
     * 締切前の催促。案内済みの未回答者に、締切の2日前から締切までの間に1回だけ送る。
     * $tenantId と $deliveryId が null なら、全テナントの受付中の配信が対象(CLI 用)。
     * @return array{targets:int, sent:int, failed:int}
     */
    public static function remind(?int $tenantId = null, ?int $deliveryId = null, ?int $now = null): array
    {
        self::assertEnabled();
        if ($tenantId !== null) {
            self::assertTenantOperational($tenantId);
        }
        $now ??= time();
        $sql = "SELECT a.id, a.tenant_id, a.access_token, t.email, t.name, s.title AS survey_title, d.deadline
                FROM survey_assignments a
                INNER JOIN survey_deliveries d ON d.id = a.delivery_id
                INNER JOIN surveys s ON s.id = d.survey_id
                INNER JOIN targets t ON t.id = a.target_id
                WHERE d.status = 'open' AND d.deadline IS NOT NULL
                  AND a.status = 'assigned' AND a.invited_at IS NOT NULL AND a.last_reminded_at IS NULL
                  AND t.status = 'active'
                  AND " . TenantStatus::operationalSql('d.tenant_id') . "
                  AND d.deadline > ? AND d.deadline <= ?";
        $params = [date('Y-m-d H:i:s', $now), date('Y-m-d H:i:s', $now + self::REMIND_BEFORE_DAYS * 86400)];
        if ($tenantId !== null) {
            $sql .= ' AND a.tenant_id = ?';
            $params[] = $tenantId;
        }
        if ($deliveryId !== null) {
            $sql .= ' AND a.delivery_id = ?';
            $params[] = $deliveryId;
        }
        $total = ['targets' => 0, 'sent' => 0, 'failed' => 0];
        foreach (Db::all($sql, $params) as $row) {
            $r = self::sendAll((int) $row['tenant_id'], [$row], (string) $row['survey_title'], $row['deadline'], true, 'last_reminded_at');
            foreach ($total as $k => $_) {
                $total[$k] += $r[$k];
            }
        }
        return $total;
    }

    private static function assertEnabled(): void
    {
        if (!self::enabled()) {
            throw new SurveyException('アンケートのメール送信は無効です。受講用 URL の一覧(CSV)を社内のメールで配布してください', 409);
        }
    }

    private static function assertTenantOperational(int $tenantId): void
    {
        $reason = TenantStatus::sendBlockReason($tenantId);
        if ($reason !== null) {
            throw new SurveyException($reason, 409);
        }
    }

    /** @return array<string,mixed> */
    private static function openDelivery(int $tenantId, int $deliveryId): array
    {
        $d = Db::one(
            'SELECT d.*, s.title AS survey_title FROM survey_deliveries d INNER JOIN surveys s ON s.id = d.survey_id
             WHERE d.id = ? AND d.tenant_id = ?',
            [$deliveryId, $tenantId]
        );
        if ($d === null) {
            throw new SurveyException('配信が見つかりません', 404);
        }
        if ((string) $d['status'] !== 'open') {
            throw new SurveyException('終了した配信には送れません', 409);
        }
        return $d;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array{targets:int, sent:int, failed:int}
     */
    private static function sendAll(int $tenantId, array $rows, string $title, ?string $deadline, bool $reminder, string $stampColumn): array
    {
        $sent = 0;
        $failed = 0;
        foreach ($rows as $r) {
            // 文面はテナントの上書きがあればそれ、なければ既定(NotificationTemplates の survey_invite / survey_reminder)
            $mail = NotificationTemplates::render($tenantId, $reminder ? 'survey_reminder' : 'survey_invite', [
                '氏名' => (string) ($r['name'] ?? ''),
                'アンケート名' => $title,
                '回答URL' => self::surveyUrl((string) $r['access_token']),
                '期限' => $deadline !== null ? substr((string) $deadline, 0, 16) : '',
            ]);
            if (EduMailer::send((string) $r['email'], $mail['subject'], $mail['body'])) {
                $sent++;
                // 列名は呼び出し側の固定値(invited_at / last_reminded_at)だけ。
                Db::run("UPDATE survey_assignments SET {$stampColumn} = datetime('now','localtime') WHERE id = ?", [(int) $r['id']]);
            } else {
                $failed++;
            }
        }
        return ['targets' => count($rows), 'sent' => $sent, 'failed' => $failed];
    }
}
