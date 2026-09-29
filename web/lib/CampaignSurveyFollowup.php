<?php
/**
 * 訓練後のアンケートの自動配信(段D の D6、G64)。
 *
 * - キャンペーンごとの設定(campaign_survey_followups)。行がないか enabled=0 なら何もしない(既定は切)。
 * - 動くのは訓練を閉じた時(api/report.php の close)だけ。閉じる前に配ると、訓練だと気付かれて測定が崩れるため。
 * - 閉じた時に1回だけ処理する(processed_at を条件付きで立てた方だけが配る)。
 * - 配り先は、防衛に失敗した人(クリックか認証の入力。装置の行動は数えない。followup.php と同じ条件)か、送った全員。
 *   テナントの有効な対象者だけ。テスト用のキャンペーンでは配らない(本物の対象者へ届いてしまうため)。
 * - 配信は既存のアンケートの配信(割当と回答用のトークン)で作る。案内メールは SurveyMailer に任せ、
 *   TET2_SURVEY_MAIL_ENABLED=1 の時だけ送る(無効なら配信だけ作り、回答用 URL の CSV で配る)。
 */
declare(strict_types=1);

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/SurveyService.php';
require_once __DIR__ . '/SurveyMailer.php';
require_once __DIR__ . '/TenantStatus.php';

final class CampaignSurveyFollowupException extends RuntimeException
{
    public function __construct(string $message, public readonly int $httpCode = 400)
    {
        parent::__construct($message);
    }
}

final class CampaignSurveyFollowup
{
    public const AUDIENCES = ['failed', 'all'];
    public const MAX_DEADLINE_DAYS = 90;

    /** @return array{enabled:bool, survey_id:?int, audience:string, deadline_days:?int, delivery_id:?int, processed_at:?string, result:?string, updated_by:?string, updated_at:?string} */
    public static function get(int $tenantId, int $campaignId): array
    {
        $row = Db::one('SELECT * FROM campaign_survey_followups WHERE campaign_id = ? AND tenant_id = ?', [$campaignId, $tenantId]);
        if ($row === null) {
            return ['enabled' => false, 'survey_id' => null, 'audience' => 'failed', 'deadline_days' => 14, 'delivery_id' => null,
                'processed_at' => null, 'result' => null, 'updated_by' => null, 'updated_at' => null];
        }
        return [
            'enabled' => (int) $row['enabled'] === 1,
            'survey_id' => $row['survey_id'] !== null ? (int) $row['survey_id'] : null,
            'audience' => (string) $row['audience'],
            'deadline_days' => $row['deadline_days'] !== null ? (int) $row['deadline_days'] : null,
            'delivery_id' => $row['delivery_id'] !== null ? (int) $row['delivery_id'] : null,
            'processed_at' => $row['processed_at'],
            'result' => $row['result'],
            'updated_by' => $row['updated_by'],
            'updated_at' => $row['updated_at'],
        ];
    }

    /**
     * 設定を保存する。閉じたキャンペーンは変えられない(もう配った後か、配らないと決まった後のため)。
     * @param array<string,mixed> $data enabled(bool)、survey_id(int|null)、audience、deadline_days(int|null)
     */
    public static function save(int $tenantId, int $campaignId, array $data, string $actor): array
    {
        $campaign = Db::one('SELECT id, closed_at FROM campaigns WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL', [$campaignId, $tenantId]);
        if ($campaign === null) {
            throw new CampaignSurveyFollowupException('キャンペーンが見つかりません', 404);
        }
        if ($campaign['closed_at'] !== null) {
            throw new CampaignSurveyFollowupException('クローズしたキャンペーンの設定は変えられません', 409);
        }
        if (!is_bool($data['enabled'] ?? null)) {
            throw new CampaignSurveyFollowupException('enabled が不正です');
        }
        $audience = $data['audience'] ?? 'failed';
        if (!is_string($audience) || !in_array($audience, self::AUDIENCES, true)) {
            throw new CampaignSurveyFollowupException('配り先が正しくありません');
        }
        $days = $data['deadline_days'] ?? null;
        if ($days !== null && (!is_int($days) || $days < 1 || $days > self::MAX_DEADLINE_DAYS)) {
            throw new CampaignSurveyFollowupException('回答の締切は1〜' . self::MAX_DEADLINE_DAYS . '日で指定してください');
        }
        $surveyId = $data['survey_id'] ?? null;
        if ($surveyId !== null && (!is_int($surveyId) || $surveyId < 1)) {
            throw new CampaignSurveyFollowupException('survey_id が不正です');
        }
        if ($data['enabled'] && $surveyId === null) {
            throw new CampaignSurveyFollowupException('配るアンケートを選んでください');
        }
        if ($surveyId !== null) {
            try {
                SurveyService::assertDeliverable($tenantId, $surveyId);
            } catch (SurveyException $e) {
                throw new CampaignSurveyFollowupException($e->getMessage(), $e->httpCode);
            }
        }
        Db::run(
            "INSERT INTO campaign_survey_followups (campaign_id, tenant_id, enabled, survey_id, audience, deadline_days, updated_by, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, datetime('now','localtime'))
             ON CONFLICT (campaign_id) DO UPDATE SET enabled = excluded.enabled, survey_id = excluded.survey_id,
                 audience = excluded.audience, deadline_days = excluded.deadline_days,
                 updated_by = excluded.updated_by, updated_at = excluded.updated_at",
            [$campaignId, $tenantId, $data['enabled'] ? 1 : 0, $surveyId, $audience, $days, $actor]
        );
        return self::get($tenantId, $campaignId);
    }

    /**
     * 訓練を閉じた直後に呼ぶ。設定が有効なら1回だけ配る。切なら何もしない(表にも書かない)。
     * @return array{status:string, delivery_id?:int, assigned?:int, mail_sent?:int, mail_failed?:int, message?:string}
     */
    public static function onClosed(int $tenantId, int $campaignId, ?int $userId = null): array
    {
        $row = Db::one('SELECT * FROM campaign_survey_followups WHERE campaign_id = ? AND tenant_id = ?', [$campaignId, $tenantId]);
        if ($row === null || (int) $row['enabled'] !== 1) {
            return ['status' => 'off'];
        }
        $campaign = Db::one('SELECT name, is_test, closed_at FROM campaigns WHERE id = ? AND tenant_id = ?', [$campaignId, $tenantId]);
        if ($campaign === null || $campaign['closed_at'] === null) {
            // 閉じる前には配らない(呼び出しの誤りでも、訓練だと気付かれないように)
            return ['status' => 'not_closed'];
        }
        $claimed = Db::run("UPDATE campaign_survey_followups SET processed_at = datetime('now','localtime')
            WHERE campaign_id = ? AND tenant_id = ? AND enabled = 1 AND processed_at IS NULL", [$campaignId, $tenantId]);
        if ($claimed !== 1) {
            return ['status' => 'already'];
        }
        try {
            $result = self::deliver($tenantId, $campaignId, $campaign, $row, $userId);
        } catch (Throwable $e) {
            error_log('CampaignSurveyFollowup: campaign ' . $campaignId . ' ' . get_class($e) . ': ' . $e->getMessage());
            $result = ['status' => 'error', 'message' => $e instanceof SurveyException ? $e->getMessage() : '内部のエラーで配れませんでした（サーバーのログを見てください）'];
        }
        Db::run('UPDATE campaign_survey_followups SET result = ?, delivery_id = ? WHERE campaign_id = ?',
            [self::describe($result), $result['delivery_id'] ?? null, $campaignId]);
        return $result;
    }

    /**
     * 配り先の対象者(テナントの有効な人)。failed は防衛に失敗した人、all は訓練のメールを送った全員。
     * @return list<int>
     */
    public static function targetIds(int $tenantId, int $campaignId, string $audience): array
    {
        $sql = $audience === 'all'
            ? "SELECT DISTINCT t.id FROM campaign_targets ct JOIN targets t ON t.id = ct.target_id
               WHERE ct.campaign_id = ? AND t.tenant_id = ? AND t.status = 'active' AND ct.sent_at IS NOT NULL ORDER BY t.id"
            : "SELECT DISTINCT t.id FROM events e
               JOIN campaign_targets ct ON ct.tracking_id = e.tracking_id AND ct.campaign_id = e.campaign_id
               JOIN targets t ON t.id = ct.target_id
               WHERE e.campaign_id = ? AND e.tenant_id = ? AND e.event_type IN ('click','auth') AND e.verdict = 'user'
                 AND t.tenant_id = e.tenant_id AND t.status = 'active' ORDER BY t.id";
        return array_map(static fn(array $r): int => (int) $r['id'], Db::all($sql, [$campaignId, $tenantId]));
    }

    /**
     * @param array<string,mixed> $campaign
     * @param array<string,mixed> $row
     */
    private static function deliver(int $tenantId, int $campaignId, array $campaign, array $row, ?int $userId): array
    {
        if ((int) $campaign['is_test'] === 1) {
            return ['status' => 'skipped', 'message' => 'テスト用のキャンペーンなので配っていません'];
        }
        $block = TenantStatus::sendBlockReason($tenantId);
        if ($block !== null) {
            return ['status' => 'skipped', 'message' => $block];
        }
        if ($row['survey_id'] === null) {
            return ['status' => 'skipped', 'message' => '配るアンケートがありません（削除された可能性があります）'];
        }
        $targetIds = self::targetIds($tenantId, $campaignId, (string) $row['audience']);
        if ($targetIds === []) {
            return ['status' => 'skipped', 'message' => $row['audience'] === 'all' ? '配り先の対象者がいません' : '防衛に失敗した人がいないので配っていません'];
        }
        $deadline = $row['deadline_days'] !== null ? date('Y-m-d 17:00:00', time() + (int) $row['deadline_days'] * 86400) : null;
        $title = mb_substr('訓練後のアンケート（' . (string) $campaign['name'] . '）', 0, 500);
        $created = SurveyService::createDeliveryForTargets($tenantId, (int) $row['survey_id'], $userId, $title, $deadline, $targetIds);
        $result = ['status' => 'delivered', 'delivery_id' => $created['delivery_id'], 'assigned' => $created['assigned'],
            'mail_sent' => 0, 'mail_failed' => 0, 'mail' => 'disabled'];
        if (SurveyMailer::enabled()) {
            $mail = SurveyMailer::sendInvitations($tenantId, $created['delivery_id']);
            $result['mail_sent'] = $mail['sent'];
            $result['mail_failed'] = $mail['failed'];
            $result['mail'] = 'sent';
        }
        return $result;
    }

    private static function describe(array $r): string
    {
        return match ($r['status']) {
            'delivered' => $r['assigned'] . '人に配信を作りました。'
                . ($r['mail'] === 'sent' ? '案内メール: 送信 ' . $r['mail_sent'] . ' / 失敗 ' . $r['mail_failed']
                    : 'アンケートのメール送信は無効なので、回答用 URL の CSV で配ってください'),
            default => (string) ($r['message'] ?? $r['status']),
        };
    }
}
