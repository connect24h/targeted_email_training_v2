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
             WHERE triggered_by = 'phishing_failure' AND status = 'running'"
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
     */
    public static function enrollForDelivery(int $deliveryId, int $tenantId, ?int $phishCampaignId): int
    {
        $failerIds = self::failerTargetIds($tenantId, $phishCampaignId);
        if ($failerIds === []) {
            return 0;
        }

        // 配信の設問が未確定なら確定する(条件抽出)。E2 と同じ規則。
        self::ensureDeliveryQuestions($deliveryId, $tenantId);

        return Db::tx(function () use ($deliveryId, $tenantId, $failerIds): int {
            $assigned = 0;
            foreach ($failerIds as $targetId) {
                // 冪等: 既に割当済みならスキップ(UNIQUE(delivery_id,target_id) の事前チェック)
                $dup = Db::one(
                    'SELECT 1 FROM edu_assignments WHERE delivery_id = ? AND target_id = ?',
                    [$deliveryId, $targetId]
                );
                if ($dup !== null) {
                    continue;
                }
                Db::run(
                    'INSERT INTO edu_assignments (tenant_id, delivery_id, target_id, access_token, status)
                     VALUES (?, ?, ?, ?, \'assigned\')',
                    [$tenantId, $deliveryId, $targetId, self::generateToken()]
                );
                $assigned++;
            }
            return $assigned;
        });
    }

    /** 訓練失敗者(auth|click)の target_id 一覧(テナント内)。 */
    private static function failerTargetIds(int $tenantId, ?int $phishCampaignId): array
    {
        if ($phishCampaignId !== null) {
            $rows = Db::all(
                "SELECT DISTINCT ct.target_id AS id
                 FROM events e
                 INNER JOIN campaign_targets ct ON ct.tracking_id = e.tracking_id
                 WHERE e.tenant_id = ? AND e.campaign_id = ? AND e.event_type IN ('auth','click')",
                [$tenantId, $phishCampaignId]
            );
        } else {
            $rows = Db::all(
                "SELECT DISTINCT ct.target_id AS id
                 FROM events e
                 INNER JOIN campaign_targets ct ON ct.tracking_id = e.tracking_id
                 WHERE e.tenant_id = ? AND e.event_type IN ('auth','click')",
                [$tenantId]
            );
        }
        $ids = [];
        foreach ($rows as $r) {
            $ids[] = (int) $r['id'];
        }
        return $ids;
    }

    /** 配信の設問が未確定なら条件抽出で確定する。 */
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

        $categoryIds = $d['category_ids'] !== null ? (json_decode((string) $d['category_ids'], true) ?: []) : [];
        $diffRange   = $d['difficulty_range'] !== null ? (json_decode((string) $d['difficulty_range'], true) ?: []) : [];
        $count       = $d['question_count'] !== null ? (int) $d['question_count'] : 0;
        $randomize   = (int) $d['randomize'] === 1;

        $where  = ['tenant_id = ?', 'is_active = 1'];
        $params = [$tenantId];
        if (is_array($categoryIds) && $categoryIds !== []) {
            $ph = implode(',', array_fill(0, count($categoryIds), '?'));
            $where[] = "category_id IN ($ph)";
            foreach ($categoryIds as $cid) {
                $params[] = (int) $cid;
            }
        }
        if (is_array($diffRange) && count($diffRange) === 2) {
            $where[] = 'difficulty BETWEEN ? AND ?';
            $params[] = (int) $diffRange[0];
            $params[] = (int) $diffRange[1];
        }
        $order = $randomize ? 'RANDOM()' : 'id';
        $sql = 'SELECT id FROM edu_questions WHERE ' . implode(' AND ', $where) . " ORDER BY $order";
        if ($count > 0) {
            $sql .= ' LIMIT ' . $count;
        }
        $rows = Db::all($sql, $params);
        $sort = 0;
        foreach ($rows as $r) {
            Db::run(
                'INSERT OR IGNORE INTO edu_delivery_questions (delivery_id, question_id, sort_order) VALUES (?, ?, ?)',
                [$deliveryId, (int) $r['id'], $sort]
            );
            $sort++;
        }
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
