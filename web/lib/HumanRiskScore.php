<?php
/**
 * 個人リスクスコア(Human Risk Score)の算出。
 *
 * 訓練の行動(開封/クリック/認証入力/報告)と教育の受講状況を合成し、対象者ごとに
 * 0-100 のスコアと low/medium/high の帯を出す。目的は「誰に次の手を打つか」を
 * 決めることであり、絶対値の精度より順序と説明可能性を優先している。
 *
 * 設計上の判断:
 *  - 基準点50から始める。訓練を一度も受けていない人を 0(=安全) にしないため。
 *    データが無いことと安全であることは違う。
 *  - 内訳(phish_component / edu_component / report_credit / detail)を必ず保存する。
 *    一人運用では「なぜこの人が high なのか」を後から説明できることが重要。
 *  - event_type は必ず明示列挙する。LIKE や NOT IN で書くと、コード上どこからも
 *    生成されない残骸データ click_bot(176件) が click と同じ重みで混入する。
 *  - is_test のキャンペーン・対象者は除外する。運用の動作確認がスコアに乗ると
 *    全員が同じ値になり、指標として機能しなくなる。
 *
 * 重みと半減期には実証的な裏付けがない。業界慣行から置いた初期値であり、
 * 本番データでの分布を見て調整する前提。high が過半数になるようなら設計が誤り。
 */
declare(strict_types=1);

require_once __DIR__ . '/Db.php';

final class HumanRiskScore
{
    /** 帯の境界。介入の出し分けに使う。 */
    private const BAND_HIGH   = 60.0;
    private const BAND_MEDIUM = 30.0;

    /** 出発点。訓練履歴がない人はここに留まる(=不明であって安全ではない)。 */
    private const BASELINE = 50.0;

    /** 時間減衰の半減期(日)。直近の行動を重く見る。 */
    private const HALF_LIFE_DAYS = 90;

    /** 訓練行動の重み。auth(認証情報の入力)が実害に最も近い。 */
    private const WEIGHT_OPEN   = 1.0;
    private const WEIGHT_CLICK  = 8.0;
    private const WEIGHT_AUTH   = 20.0;
    /** 報告(正しい行動)の減点。クリック1回分を概ね打ち消す程度。 */
    private const WEIGHT_REPORT = 6.0;

    /** 教育の受講状況による加減点。 */
    private const EDU_OVERDUE     = 12.0;  // 期限超過かつ未完了
    private const EDU_INCOMPLETE  = 5.0;   // 未完了
    private const EDU_FAILED      = 8.0;   // 受講したが不合格
    private const EDU_PASSED      = -6.0;  // 合格

    /**
     * 訓練を受けて一度も反応しなかった(=正しく無視した)ことへの減点。1キャンペーンあたり。
     *
     * これがないと「訓練を受けて正しく無視した人」と「まだ訓練対象になっていない人」が
     * どちらも基準点50で並び、褒めるべき人を見つけられない。回数の効果は逓減させる
     * (10回無視しても20回分にはしない)。
     */
    private const CLEAN_CAMPAIGN_CREDIT = 4.0;
    private const CLEAN_CAMPAIGN_MAX    = 20.0;

    /**
     * 全テナントの対象者スコアを計算して保存する。
     *
     * @param string|null $date YYYY-MM-DD。省略時は当日。
     * @return array{date:string, scored:int, bands:array<string,int>}
     */
    public static function run(?string $date = null): array
    {
        $date = $date ?? date('Y-m-d');
        $tenants = Db::all('SELECT id FROM tenants ORDER BY id');

        $scored = 0;
        $bands = ['low' => 0, 'medium' => 0, 'high' => 0];
        foreach ($tenants as $t) {
            $r = self::scoreTenant((int) $t['id'], $date);
            $scored += $r['scored'];
            foreach ($bands as $band => $_) {
                $bands[$band] += $r['bands'][$band];
            }
        }
        return ['date' => $date, 'scored' => $scored, 'bands' => $bands];
    }

    /** テナント1件分。1,900件規模の INSERT を1トランザクションにまとめる。 */
    private static function scoreTenant(int $tenantId, string $date): array
    {
        // 在籍中かつ検証用でない対象者だけを採点する。
        $targets = Db::all(
            "SELECT id FROM targets
             WHERE tenant_id = ? AND status = 'active' AND is_test = 0
             ORDER BY id",
            [$tenantId]
        );
        if ($targets === []) {
            return ['scored' => 0, 'bands' => ['low' => 0, 'medium' => 0, 'high' => 0]];
        }

        $bands = ['low' => 0, 'medium' => 0, 'high' => 0];
        $scored = Db::tx(function () use ($targets, $tenantId, $date, &$bands): int {
            $n = 0;
            foreach ($targets as $t) {
                $s = self::computeForTarget((int) $t['id'], $tenantId, $date);
                Db::run(
                    'INSERT INTO human_risk_scores
                     (tenant_id, target_id, score, band, phish_component, edu_component, report_credit, detail, computed_date)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                     ON CONFLICT (tenant_id, target_id, computed_date) DO UPDATE SET
                       score = excluded.score, band = excluded.band,
                       phish_component = excluded.phish_component,
                       edu_component = excluded.edu_component,
                       report_credit = excluded.report_credit,
                       detail = excluded.detail',
                    [
                        $tenantId, (int) $t['id'], $s['score'], $s['band'],
                        $s['phish_component'], $s['edu_component'], $s['report_credit'],
                        json_encode($s['detail'], JSON_UNESCAPED_UNICODE), $date,
                    ]
                );
                $bands[$s['band']]++;
                $n++;
            }
            return $n;
        });

        return ['scored' => $scored, 'bands' => $bands];
    }

    /**
     * 対象者1人分のスコアを計算する(保存はしない)。
     *
     * @return array{score:float, band:string, phish_component:float, edu_component:float,
     *               report_credit:float, detail:array<string,int|float>}
     */
    public static function computeForTarget(int $targetId, int $tenantId, string $date): array
    {
        $phish = 0.0;
        $reportCredit = 0.0;
        $counts = ['open' => 0, 'click' => 0, 'auth' => 0, 'report' => 0];

        // event_type は明示列挙。click_bot(生成コードのない残骸データ)を混ぜない。
        // テストキャンペーン(c.is_test=1)は運用の動作確認なので除外する。
        $rows = Db::all(
            "SELECT e.event_type,
                    MAX(0, julianday(?) - julianday(e.occurred_at)) AS days_ago
             FROM events e
             INNER JOIN campaign_targets ct ON ct.tracking_id = e.tracking_id
             INNER JOIN campaigns c ON c.id = ct.campaign_id
             WHERE ct.target_id = ? AND e.tenant_id = ?
               AND c.is_test = 0 AND c.deleted_at IS NULL
               AND e.event_type IN ('open','click','auth','report')",
            [$date . ' 23:59:59', $targetId, $tenantId]
        );

        foreach ($rows as $r) {
            $type = (string) $r['event_type'];
            $decay = self::decay((float) $r['days_ago']);
            $counts[$type]++;
            if ($type === 'report') {
                $reportCredit += self::WEIGHT_REPORT * $decay;
                continue;
            }
            $phish += self::eventWeight($type) * $decay;
        }

        [$edu, $eduDetail] = self::educationComponent($targetId, $tenantId, $date);

        // 訓練を受けて反応しなかった実績を評価する。これを入れないと、
        // 正しく無視した人が未参加者と同じ基準点に並んでしまう。
        $clean = self::cleanCampaignCount($targetId, $tenantId);
        $cleanCredit = min(self::CLEAN_CAMPAIGN_MAX, $clean * self::CLEAN_CAMPAIGN_CREDIT);

        $score = self::BASELINE + $phish - $reportCredit - $cleanCredit + $edu;
        $score = max(0.0, min(100.0, $score));

        return [
            'score' => round($score, 1),
            'band' => self::band($score),
            'phish_component' => round($phish, 2),
            'edu_component' => round($edu, 2),
            // 「正しい行動」の合計。報告と、踏まなかったことの両方を含む。
            'report_credit' => round($reportCredit + $cleanCredit, 2),
            'detail' => $counts + $eduDetail + ['clean_campaigns' => $clean],
        ];
    }

    /**
     * 反応(open/click/auth)が1件も無かったキャンペーンの数。
     * 「訓練メールが届いたが何もしなかった」= 望ましい対応。
     */
    private static function cleanCampaignCount(int $targetId, int $tenantId): int
    {
        $row = Db::one(
            "SELECT COUNT(DISTINCT ct.campaign_id) AS c
             FROM campaign_targets ct
             INNER JOIN campaigns c ON c.id = ct.campaign_id
             WHERE ct.target_id = ? AND c.tenant_id = ?
               AND c.is_test = 0 AND c.deleted_at IS NULL
               AND NOT EXISTS (
                     SELECT 1 FROM events e
                     WHERE e.tracking_id = ct.tracking_id
                       AND e.event_type IN ('open','click','auth')
                   )",
            [$targetId, $tenantId]
        );
        return $row !== null ? (int) $row['c'] : 0;
    }

    /** 訓練イベント1件の重み。 */
    private static function eventWeight(string $eventType): float
    {
        return match ($eventType) {
            'auth'  => self::WEIGHT_AUTH,
            'click' => self::WEIGHT_CLICK,
            'open'  => self::WEIGHT_OPEN,
            default => 0.0,
        };
    }

    /** 経過日数に応じた減衰係数。半減期 HALF_LIFE_DAYS で 0.5 になる。 */
    private static function decay(float $daysAgo): float
    {
        if ($daysAgo <= 0) {
            return 1.0;
        }
        return 2 ** (-$daysAgo / self::HALF_LIFE_DAYS);
    }

    /**
     * 教育の受講状況による加減点。
     * 未受講・期限超過を加点し、合格を減点する。
     *
     * @return array{0:float, 1:array<string,int>}
     */
    private static function educationComponent(int $targetId, int $tenantId, string $date): array
    {
        $rows = Db::all(
            "SELECT a.status, a.completed_at, d.deadline, d.pass_score, r.percentage
             FROM edu_assignments a
             INNER JOIN edu_deliveries d ON d.id = a.delivery_id
             LEFT JOIN edu_responses r ON r.assignment_id = a.id
             WHERE a.target_id = ? AND a.tenant_id = ?",
            [$targetId, $tenantId]
        );

        $score = 0.0;
        $detail = ['edu_assigned' => 0, 'edu_completed' => 0, 'edu_overdue' => 0];
        foreach ($rows as $r) {
            $detail['edu_assigned']++;
            $completed = (string) $r['status'] === 'completed';
            if (!$completed) {
                $deadline = $r['deadline'] !== null ? (string) $r['deadline'] : null;
                if ($deadline !== null && $deadline < $date) {
                    $score += self::EDU_OVERDUE;
                    $detail['edu_overdue']++;
                } else {
                    $score += self::EDU_INCOMPLETE;
                }
                continue;
            }
            $detail['edu_completed']++;
            $passScore = $r['pass_score'] !== null ? (int) $r['pass_score'] : null;
            $percentage = $r['percentage'] !== null ? (float) $r['percentage'] : null;
            if ($passScore !== null && $percentage !== null && $percentage < $passScore) {
                $score += self::EDU_FAILED;
                continue;
            }
            $score += self::EDU_PASSED;
        }
        return [$score, $detail];
    }

    /** スコアから帯を決める。 */
    public static function band(float $score): string
    {
        if ($score >= self::BAND_HIGH) {
            return 'high';
        }
        if ($score >= self::BAND_MEDIUM) {
            return 'medium';
        }
        return 'low';
    }
}
