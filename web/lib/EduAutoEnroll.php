<?php
/**
 * 訓練→教育の自動連携(オーケストレーション)。
 * 訓練で失敗(events の auth|click)した対象者を、トリガー配信へ自動投入しトークン発行する。
 *
 * トリガー配信 = edu_deliveries で triggered_by='phishing_failure' かつ status='running' のもの。
 *   - phish_campaign_id が指定されていれば、そのキャンペーンの失敗者だけを対象にする。
 *   - phish_campaign_id が NULL なら、同一テナントの全キャンペーンの失敗者を対象にする。
 *
 * 冪等: edu_assignments の UNIQUE(delivery_id, target_id) により、同じ対象者は二重投入されない。
 * 設計意図: 引っかかった直後の鮮明な記憶を活かすジャストインタイム教育(LRM オーケストレーション相当)。
 */
declare(strict_types=1);

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/EduQuestionPicker.php';
require_once __DIR__ . '/EduMailer.php';
require_once __DIR__ . '/TenantStatus.php';
require_once __DIR__ . '/EduDeliveryLauncher.php';

final class EduAutoEnroll
{
    /**
     * 全テナントのトリガー配信を処理する。
     * @return array{deliveries:int, assigned:int, details:array<int,array{delivery_id:int,assigned:int}>}
     */
    public static function run(): array
    {
        $deliveries = Db::all(
            "SELECT id, tenant_id, phish_campaign_id
             FROM edu_deliveries
             WHERE triggered_by = 'phishing_failure' AND status = 'running'
               AND " . TenantStatus::operationalSql('tenant_id')
        );

        $totalAssigned = 0;
        $details = [];
        foreach ($deliveries as $d) {
            $assigned = self::enrollForDelivery(
                (int) $d['id'],
                (int) $d['tenant_id'],
                $d['phish_campaign_id'] !== null ? (int) $d['phish_campaign_id'] : null
            );
            $totalAssigned += $assigned;
            $details[] = ['delivery_id' => (int) $d['id'], 'assigned' => $assigned];
        }

        return [
            'deliveries' => count($deliveries),
            'assigned' => $totalAssigned,
            'details' => $details,
        ];
    }

    /**
     * 指定トリガー配信について、訓練失敗者を自動投入する。新規割当数を返す。
     *
     * 割当の作成はトランザクション内、受講案内メールの送信はトランザクション外で行う。
     * SMTP はブロッキングなので、tx 内で送ると WAL のロックを長時間保持し、
     * 送信worker(tet2-worker)と競合する。
     */
    public static function enrollForDelivery(int $deliveryId, int $tenantId, ?int $phishCampaignId): int
    {
        $failerIds = self::failerTargetIds($tenantId, $phishCampaignId, $deliveryId);
        if ($failerIds === []) {
            return 0;
        }

        // 配信の設問が未確定なら確定する(EduQuestionPicker と同じ規則)。
        self::ensureDeliveryQuestions($deliveryId, $tenantId);

        // 受講リンクの有効期限。配信の deadline を使う(無ければ無期限)。
        $expiry = self::tokenExpiry($deliveryId, $tenantId);

        /** @var list<int> $newAssignmentIds */
        $newAssignmentIds = Db::tx(function () use ($deliveryId, $tenantId, $failerIds, $expiry): array {
            $created = [];
            foreach ($failerIds as $targetId) {
                // 冪等: 既に割当済みならスキップ(UNIQUE(delivery_id,target_id) の事前チェック)
                $dup = Db::one(
                    'SELECT 1 FROM edu_assignments WHERE delivery_id = ? AND target_id = ?',
                    [$deliveryId, $targetId]
                );
                if ($dup !== null) {
                    continue;
                }
                $created[] = Db::insert(
                    'INSERT INTO edu_assignments (tenant_id, delivery_id, target_id, access_token, status, token_expiry)
                     VALUES (?, ?, ?, ?, \'assigned\', ?)',
                    [$tenantId, $deliveryId, $targetId, self::generateToken(), $expiry]
                );
            }
            return $created;
        });

        // --- ここから tx 外 ---
        self::sendInvites($newAssignmentIds, $deliveryId, $tenantId);

        return count($newAssignmentIds);
    }

    /**
     * 新規割当者へ受講案内メールを送る(配信の send_invites=1 のときだけ)。
     *
     * 送信できた分だけ last_reminded_at を立てる。この列は EduReminder の再送間隔にも
     * 使われるため、案内直後に催促メールが飛ぶ二重送信も同時に防げる(専用列を足さない理由)。
     */
    private static function sendInvites(array $assignmentIds, int $deliveryId, int $tenantId): void
    {
        if ($assignmentIds === []) {
            return;
        }
        $delivery = Db::one('SELECT title, send_invites FROM edu_deliveries WHERE id = ?', [$deliveryId]);
        // 教育のメールは既定で送らない。配信で「案内メールを送る」を選んだときだけ送る。
        if ($delivery === null || (int) $delivery['send_invites'] !== 1) {
            return;
        }
        $title = (string) $delivery['title'];

        foreach ($assignmentIds as $assignmentId) {
            $row = Db::one(
                'SELECT a.access_token, t.email, t.name
                 FROM edu_assignments a
                 INNER JOIN targets t ON t.id = a.target_id
                 WHERE a.id = ? AND a.tenant_id = ?',
                [$assignmentId, $tenantId]
            );
            if ($row === null) {
                continue;
            }
            $sent = EduMailer::send(
                (string) $row['email'],
                self::inviteSubject($title),
                self::inviteBody((string) ($row['name'] ?? ''), $title, (string) $row['access_token'])
            );
            if ($sent) {
                Db::run(
                    "UPDATE edu_assignments SET last_reminded_at = datetime('now','localtime') WHERE id = ?",
                    [$assignmentId]
                );
            }
        }
    }

    /** 訓練失敗直後の案内なので、責める文面にしない(受講率を下げるため)。 */
    private static function inviteSubject(string $title): string
    {
        return '【受講のご案内】' . $title;
    }

    private static function inviteBody(string $name, string $title, string $token): string
    {
        $greeting = trim($name) !== '' ? (trim($name) . ' 様') : 'ご担当者 様';
        return $greeting . "\n\n"
            . "先日の標的型メール訓練の結果にもとづき、フォローアップ教育「" . $title . "」をご案内します。\n"
            . "訓練で気づけなかった点を短時間で確認できます。下記URLよりご受講ください"
            . "（所要5〜10分・ログイン不要）。\n\n"
            . EduMailer::takeUrl($token) . "\n\n"
            . "※本メールは自動送信です。ご不明点は管理者へお問い合わせください。\n";
    }

    /**
     * 訓練失敗者(auth|click)の target_id 一覧(テナント内)。risk_results のある配信は、その区分に当たる人。
     *
     * 除外規則は api/edu_deliveries.php の risk 配信(edu_d_resolve_targets)と揃える。
     * 検証用ユーザ(is_test)と退職者(archived)へ受講案内が飛ぶ事故を防ぐ。
     *
     * さらに「配信を作成した日時以降のイベント」だけを対象にする。これがないと、
     * トリガー配信を running にした瞬間に過去全期間の失敗者へ一斉送信され、
     * 1年前の失敗に対して今さら教育案内が届くことになる。
     */
    private static function failerTargetIds(int $tenantId, ?int $phishCampaignId, int $deliveryId): array
    {
        // 訓練の結果の区分(risk_results)を持つ配信は、手動の開始と同じ判定で対象を選ぶ(画面の指定と実際の対象をずらさない)。
        // 区分のない配信は、従来どおりクリックか入力をした人。
        $delivery = Db::one('SELECT * FROM edu_deliveries WHERE id = ? AND tenant_id = ?', [$deliveryId, $tenantId]);
        if ($delivery !== null && EduDeliveryLauncher::hasRiskResults($delivery)) {
            try {
                return EduDeliveryLauncher::resolveTargets($delivery, $tenantId);
            } catch (EduDeliveryError $e) {
                // キャンペーンの削除などで対象を決められない配信は、投入しない(ほかの配信の処理は続ける)
                error_log('edu_auto_enroll: delivery_id=' . $deliveryId . ' ' . $e->getMessage());
                return [];
            }
        }
        $sql = "SELECT DISTINCT ct.target_id AS id
                FROM events e
                INNER JOIN campaigns c ON c.id = e.campaign_id AND c.tenant_id = e.tenant_id AND c.deleted_at IS NULL
                INNER JOIN campaign_targets ct ON ct.tracking_id = e.tracking_id
                INNER JOIN targets t ON t.id = ct.target_id
                WHERE e.tenant_id = ? AND e.event_type IN ('auth','click') AND e.verdict = 'user'
                  AND t.tenant_id = ? AND t.status = 'active' AND t.is_test = 0
                  AND e.occurred_at >= (SELECT created_at FROM edu_deliveries WHERE id = ?)";
        $params = [$tenantId, $tenantId, $deliveryId];
        if ($phishCampaignId !== null) {
            $sql .= ' AND e.campaign_id = ?';
            $params[] = $phishCampaignId;
        }
        $sql .= ' ORDER BY ct.target_id';

        $ids = [];
        foreach (Db::all($sql, $params) as $r) {
            $ids[] = (int) $r['id'];
        }
        return $ids;
    }

    /**
     * 配信の設問が未確定なら確定する。
     * 出題規則は EduQuestionPicker に一本化してある(共有設問を拾い漏らす事故の再発防止)。
     */
    private static function ensureDeliveryQuestions(int $deliveryId, int $tenantId): void
    {
        $existing = Db::one('SELECT 1 FROM edu_delivery_questions WHERE delivery_id = ?', [$deliveryId]);
        if ($existing !== null) {
            return;
        }
        $d = Db::one('SELECT * FROM edu_deliveries WHERE id = ? AND tenant_id = ?', [$deliveryId, $tenantId]);
        if ($d === null) {
            return;
        }

        $sort = 0;
        foreach (EduQuestionPicker::pick($d, $tenantId) as $questionId) {
            Db::run(
                'INSERT OR IGNORE INTO edu_delivery_questions (delivery_id, question_id, sort_order) VALUES (?, ?, ?)',
                [$deliveryId, $questionId, $sort]
            );
            $sort++;
        }
    }

    /**
     * 受講トークンの有効期限。配信の deadline をそのまま使う(規則は edu_deliveries.php と同じ)。
     * deadline が日付のみなら、その日いっぱいを有効にする。
     */
    private static function tokenExpiry(int $deliveryId, int $tenantId): ?string
    {
        $row = Db::one(
            'SELECT deadline FROM edu_deliveries WHERE id = ? AND tenant_id = ?',
            [$deliveryId, $tenantId]
        );
        $deadline = $row['deadline'] ?? null;
        if (!is_string($deadline) || trim($deadline) === '') {
            return null;
        }
        $deadline = trim($deadline);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $deadline) === 1) {
            return $deadline . ' 23:59:59';
        }
        return $deadline;
    }

    /** access_token: 32桁hex。edu_deliveries.php と同じ規則。 */
    private static function generateToken(): string
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
