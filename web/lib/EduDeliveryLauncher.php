<?php
/**
 * 教育配信の開始(launch)。管理画面の API(api/edu_deliveries.php)と、予約の配信を自動で開始する
 * CLI(db/edu_scheduler.php)が同じ処理を呼ぶ。
 *
 * 開始の手順: 対象者と設問を確定し、受講割当(access_token)を採番し、状態を running にする。
 * 受講の案内メールは、配信の send_invites=1 のときだけ、commit の後に送る(既定は送らない)。
 */
declare(strict_types=1);

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/EduQuestionPicker.php';
require_once __DIR__ . '/EduMailer.php';
require_once __DIR__ . '/EduDeliverySeries.php';

/** 開始できない理由。code は API がそのまま返す HTTP の状態コード(400/404/409)。 */
final class EduDeliveryError extends RuntimeException
{
}

final class EduDeliveryLauncher
{
    /**
     * 訓練の結果の区分(target_type=risk の risk_results)。events の意味は既存の集計(report.php)と同じ:
     *   opened     = 開いた。リンクのクリック(click)か、偽サイトの HTML のビーコン(open)。
     *                open はメールの開封ではない(メール本文にビーコンはない)。
     *   submitted  = 偽サイトに入力した(auth)。
     *   reported   = 訓練メールを報告した(report)。
     *   not_opened = 送信済みで、開いた・入力したの記録がない(報告だけした人を含む)。
     */
    public const RISK_RESULTS = ['opened', 'submitted', 'reported', 'not_opened'];

    /** 終わったキャンペーンの状態。これ以外(予約中、配信中、一時停止、下書き)の結果では配信を作らない。 */
    public const FINISHED_CAMPAIGN_STATUSES = ['done', 'cancelled'];

    /**
     * 配信を開始する。
     *
     * @return array{assigned:int, mail_sent:int, question_count:int}
     * @throws EduDeliveryError 開始できないとき
     */
    public static function launch(array $delivery, int $tenantId, ?DateTimeImmutable $now = null): array
    {
        $id = (int) $delivery['id'];
        if (!in_array((string) $delivery['status'], ['draft', 'scheduled'], true)) {
            throw new EduDeliveryError('この配信は開始できません(status=' . $delivery['status'] . ')', 409);
        }
        $targetIds = self::resolveTargets($delivery, $tenantId, $now);
        if ($targetIds === []) {
            throw new EduDeliveryError('対象者がいません', 400);
        }
        $questionIds = self::resolveQuestions($delivery, $tenantId);
        if ($questionIds === []) {
            throw new EduDeliveryError('出題する設問がありません', 400);
        }

        // SMTP はブロッキングなので、割当と設問の確定だけを tx で行い、メールは commit の後に送る。
        $tokens = Db::txImmediate(static function () use ($id, $tenantId, $targetIds, $questionIds, $delivery): array {
            // 画面と CLI が同時に開始しても二重にならないよう、状態の遷移を最初に取る。
            $moved = Db::run(
                "UPDATE edu_deliveries SET status = 'running'
                 WHERE id = ? AND tenant_id = ? AND status IN ('draft','scheduled')",
                [$id, $tenantId]
            );
            if ($moved === 0) {
                throw new EduDeliveryError('この配信は既に開始されています', 409);
            }
            self::storeQuestions($id, $questionIds);
            return self::assign($id, $tenantId, $targetIds, self::tokenExpiry($delivery));
        });

        $mailSent = 0;
        if ((int) ($delivery['send_invites'] ?? 0) === 1) {
            $mailSent = self::sendInvites($id, $tenantId, (string) $delivery['title'], $tokens);
        }
        return ['assigned' => count($tokens), 'mail_sent' => $mailSent, 'question_count' => count($questionIds)];
    }

    /**
     * 受講割当を採番する。tx の中で呼ぶ。既に割当のある対象者は飛ばす(再実行しても二重にならない)。
     *
     * @param list<int> $targetIds
     * @return list<string> 新しく発行した access_token
     */
    public static function assign(int $deliveryId, int $tenantId, array $targetIds, ?string $expiry): array
    {
        $tokens = [];
        foreach ($targetIds as $targetId) {
            $dup = Db::one('SELECT 1 FROM edu_assignments WHERE delivery_id = ? AND target_id = ?', [$deliveryId, $targetId]);
            if ($dup !== null) {
                continue;
            }
            $token = self::generateToken();
            Db::run(
                'INSERT INTO edu_assignments (tenant_id, delivery_id, target_id, access_token, status, token_expiry)
                 VALUES (?, ?, ?, ?, \'assigned\', ?)',
                [$tenantId, $deliveryId, $targetId, $token, $expiry]
            );
            $tokens[] = $token;
        }
        return $tokens;
    }

    /** 設問が未確定(条件抽出)なら edu_delivery_questions に積む。明示指定済みならそのまま。 */
    private static function storeQuestions(int $deliveryId, array $questionIds): void
    {
        if (Db::one('SELECT 1 FROM edu_delivery_questions WHERE delivery_id = ?', [$deliveryId]) !== null) {
            return;
        }
        foreach ($questionIds as $sort => $questionId) {
            Db::run(
                'INSERT OR IGNORE INTO edu_delivery_questions (delivery_id, question_id, sort_order) VALUES (?, ?, ?)',
                [$deliveryId, $questionId, $sort]
            );
        }
    }

    /**
     * target_type に応じて対象 target_id を確定する(全てテナント内)。
     *
     * @return list<int>
     */
    public static function resolveTargets(array $delivery, int $tenantId, ?DateTimeImmutable $now = null): array
    {
        $type = (string) $delivery['target_type'];
        if ($type === 'all') {
            $rows = Db::all(
                "SELECT id FROM targets
                 WHERE tenant_id = ? AND status = 'active' AND is_test = 0 ORDER BY id",
                [$tenantId]
            );
        } elseif ($type === 'group') {
            $rows = self::groupTargets($delivery, $tenantId);
        } elseif ($type === 'individual') {
            $rows = Db::all(
                "SELECT t.id FROM edu_delivery_targets dt
                 INNER JOIN targets t ON t.id = dt.target_id
                 WHERE dt.delivery_id = ? AND t.tenant_id = ? AND t.status = 'active'
                 ORDER BY t.id",
                [(int) $delivery['id'], $tenantId]
            );
        } elseif ($type === 'risk') {
            $rows = self::riskTargets($delivery, $tenantId);
        } elseif ($type === 'position') {
            $rows = self::positionTargets($delivery, $tenantId);
        } else {
            throw new EduDeliveryError('target_type が不正です', 400);
        }
        return array_map(static fn(array $r): int => (int) $r['id'], $rows);
    }

    private static function groupTargets(array $delivery, int $tenantId): array
    {
        $groupId = $delivery['target_group_id'] !== null ? (int) $delivery['target_group_id'] : 0;
        if ($groupId < 1) {
            throw new EduDeliveryError('group 配信には target_group_id が必要です', 400);
        }
        if (Db::one('SELECT id FROM groups WHERE id = ? AND tenant_id = ?', [$groupId, $tenantId]) === null) {
            throw new EduDeliveryError('グループが見つかりません', 404);
        }
        return Db::all(
            "SELECT t.id
             FROM targets t
             INNER JOIN target_group tg ON tg.target_id = t.id
             WHERE t.tenant_id = ? AND tg.group_id = ? AND t.status = 'active'
             ORDER BY t.id",
            [$tenantId, $groupId]
        );
    }

    /** 役職区分(targets.position_category)の一覧に当たる、在籍中の実対象者。 */
    private static function positionTargets(array $delivery, int $tenantId): array
    {
        $positions = json_decode((string) ($delivery['target_positions'] ?? ''), true);
        if (!is_array($positions) || $positions === []) {
            throw new EduDeliveryError('役職の配信には役職区分の指定が必要です', 400);
        }
        $placeholders = implode(',', array_fill(0, count($positions), '?'));
        return Db::all(
            "SELECT id FROM targets
             WHERE tenant_id = ? AND status = 'active' AND is_test = 0 AND position_category IN ($placeholders)
             ORDER BY id",
            array_merge([$tenantId], array_map('strval', $positions))
        );
    }

    /**
     * 訓練の結果の区分(risk_results)に当たる、そのキャンペーンの実対象者。
     * 集計の形は TrainingLogRows(訓練結果の一覧)と同じく、対象者ごとに events を束ねる。
     */
    private static function riskResultTargets(array $results, int $campaignId, int $tenantId): array
    {
        self::assertCampaignOwned($campaignId, $tenantId);
        $rows = Db::all(
            "SELECT ct.target_id AS id,
                    MAX(CASE WHEN e.event_type IN ('click','open') THEN 1 ELSE 0 END) AS opened,
                    MAX(CASE WHEN e.event_type = 'auth' THEN 1 ELSE 0 END) AS submitted,
                    MAX(CASE WHEN e.event_type = 'report' THEN 1 ELSE 0 END) AS reported,
                    MAX(CASE WHEN ct.send_status = 'sent' THEN 1 ELSE 0 END) AS sent
             FROM campaign_targets ct
             INNER JOIN targets t ON t.id = ct.target_id
             LEFT JOIN events e ON e.tracking_id = ct.tracking_id AND e.campaign_id = ct.campaign_id
                   AND e.tenant_id = ? AND e.event_type IN ('open','click','auth','report')
             WHERE ct.campaign_id = ? AND t.tenant_id = ? AND t.status = 'active' AND t.is_test = 0
             GROUP BY ct.target_id
             ORDER BY ct.target_id",
            [$tenantId, $campaignId, $tenantId]
        );
        return array_values(array_filter($rows, static function (array $r) use ($results): bool {
            $flags = [
                'opened' => (int) $r['opened'] === 1 || (int) $r['submitted'] === 1,
                'submitted' => (int) $r['submitted'] === 1,
                'reported' => (int) $r['reported'] === 1,
                'not_opened' => (int) $r['sent'] === 1 && (int) $r['opened'] === 0 && (int) $r['submitted'] === 0,
            ];
            foreach ($results as $result) {
                if ($flags[$result] ?? false) {
                    return true;
                }
            }
            return false;
        }));
    }

    /**
     * risk の対象。結果の区分(risk_results)があればその区分、なければ従来どおり訓練で失敗
     * (auth or click)した実対象者(キャンペーンの指定がなければテナント全体)。
     */
    private static function riskTargets(array $delivery, int $tenantId): array
    {
        $campaignId = $delivery['phish_campaign_id'] !== null ? (int) $delivery['phish_campaign_id'] : 0;
        $results = json_decode((string) ($delivery['risk_results'] ?? ''), true);
        if (is_array($results) && $results !== []) {
            if ($campaignId < 1) {
                throw new EduDeliveryError('訓練の結果で対象を選ぶときは、キャンペーンの指定が必要です', 400);
            }
            return self::riskResultTargets($results, $campaignId, $tenantId);
        }
        $sql = "SELECT DISTINCT ct.target_id AS id
                FROM events e
                INNER JOIN campaigns c ON c.id = e.campaign_id AND c.tenant_id = e.tenant_id AND c.deleted_at IS NULL
                INNER JOIN campaign_targets ct ON ct.tracking_id = e.tracking_id
                INNER JOIN targets t ON t.id = ct.target_id
                WHERE e.tenant_id = ? AND e.event_type IN ('auth','click')
                  AND t.tenant_id = ? AND t.status = 'active' AND t.is_test = 0";
        $params = [$tenantId, $tenantId];
        if ($campaignId > 0) {
            self::assertCampaignOwned($campaignId, $tenantId);
            $sql .= ' AND e.campaign_id = ?';
            $params[] = $campaignId;
        }
        return Db::all($sql . ' ORDER BY ct.target_id', $params);
    }

    public static function assertCampaignOwned(int $campaignId, int $tenantId): array
    {
        $campaign = Db::one(
            'SELECT * FROM campaigns WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL',
            [$campaignId, $tenantId]
        );
        if ($campaign === null) {
            throw new EduDeliveryError('キャンペーンが見つかりません', 404);
        }
        return $campaign;
    }

    /**
     * 配信の設問を確定する。明示指定(edu_delivery_questions に積んである)を優先し、
     * なければ条件抽出(規則は EduQuestionPicker に一本化)。
     *
     * @return list<int>
     */
    public static function resolveQuestions(array $delivery, int $tenantId): array
    {
        $explicit = Db::all(
            'SELECT question_id FROM edu_delivery_questions WHERE delivery_id = ? ORDER BY sort_order, id',
            [(int) $delivery['id']]
        );
        if ($explicit !== []) {
            return array_map(static fn(array $r): int => (int) $r['question_id'], $explicit);
        }
        // 毎月の配信は、同じ系列の過去の回で出した設問を外す(足りなければ古い回から戻す)。
        $seriesId = isset($delivery['series_id']) ? (int) $delivery['series_id'] : 0;
        $used = $seriesId > 0
            ? EduDeliverySeries::usedQuestionIds($seriesId, $tenantId, (int) ($delivery['id'] ?? 0))
            : [];
        return EduQuestionPicker::pick($delivery, $tenantId, $used);
    }

    /**
     * 受講トークンの有効期限。配信に deadline があればそれを使う。
     * 日付のみ(YYYY-MM-DD)なら、その日いっぱいを有効にする。
     */
    public static function tokenExpiry(array $delivery): ?string
    {
        $deadline = $delivery['deadline'] ?? null;
        if (!is_string($deadline) || trim($deadline) === '') {
            return null;
        }
        $deadline = trim($deadline);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $deadline) === 1) {
            return $deadline . ' 23:59:59';
        }
        return $deadline;
    }

    /**
     * 新規割当者へ受講依頼メールを送る。送信成功数を返す。tx 外で呼ぶこと(SMTP はブロッキング)。
     *
     * @param list<string> $tokens
     */
    public static function sendInvites(int $deliveryId, int $tenantId, string $title, array $tokens): int
    {
        $sent = 0;
        foreach ($tokens as $token) {
            $row = Db::one(
                'SELECT t.email, t.name
                 FROM edu_assignments a
                 INNER JOIN targets t ON t.id = a.target_id
                 WHERE a.access_token = ? AND a.tenant_id = ? AND a.delivery_id = ?',
                [$token, $tenantId, $deliveryId]
            );
            if ($row === null) {
                continue;
            }
            $name = trim((string) ($row['name'] ?? ''));
            $greeting = $name !== '' ? ($name . ' 様') : 'ご担当者 様';
            $body = $greeting . "\n\n"
                . 'セキュリティ教育「' . $title . "」が配信されました。\n"
                . "下記URLよりご受講ください（所要5〜10分・ログイン不要）。\n\n"
                . EduMailer::takeUrl($token) . "\n\n"
                . "※本メールは自動送信です。ご不明点は管理者へお問い合わせください。\n";
            if (EduMailer::send((string) $row['email'], '【受講のご案内】' . $title, $body)) {
                $sent++;
            }
        }
        return $sent;
    }

    /**
     * 日時を 'YYYY-MM-DD HH:MM:SS' に揃える。画面の datetime-local('YYYY-MM-DDTHH:MM')も受け付ける。
     * 文字列のまま大小を比べるので、保存と比較の前に必ずこの形にする。読めなければ null。
     */
    public static function normalizeDateTime(string $value): ?string
    {
        $value = str_replace('T', ' ', trim($value));
        foreach (['!Y-m-d H:i:s', '!Y-m-d H:i'] as $format) {
            $parsed = DateTimeImmutable::createFromFormat($format, $value);
            $errors = DateTimeImmutable::getLastErrors();
            if ($parsed !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return $parsed->format('Y-m-d H:i:s');
            }
        }
        return null;
    }

    /** access_token: 32桁hex。受講者URLに露出するため campaign の10桁より長く、推測困難に。 */
    public static function generateToken(): string
    {
        for ($i = 0; $i < 10; $i++) {
            $token = bin2hex(random_bytes(16));
            if (Db::one('SELECT 1 FROM edu_assignments WHERE access_token = ?', [$token]) === null) {
                return $token;
            }
        }
        throw new RuntimeException('access_token を生成できません');
    }
}
