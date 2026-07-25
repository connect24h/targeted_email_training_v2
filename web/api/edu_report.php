<?php declare(strict_types=1); require __DIR__."/../lib/bootstrap.php";

/**
 * 教育レポート API(集計・閲覧のみ)。report.php と同じ流儀。
 * - action=delivery  : 配信1件の集計(受講率/平均正答率/合格率/カテゴリ別/設問別)
 * - action=deliveries: 配信一覧の集計サマリ
 * - action=overview  : テナント全体(受講率/平均リテラシースコア/部署別ランキング/カテゴリ別)
 * - action=cross     : 訓練×教育クロス(訓練失敗者が教育で改善したか)
 * すべて viewer 以上。テナント分離を機械付与。
 */

function edu_rep_query_int(string $key): ?int
{
    if (!array_key_exists($key, $_GET) || $_GET[$key] === '') {
        return null;
    }
    $v = intval($_GET[$key]);
    if ($v < 1) {
        json_error($key . ' が不正です', 400);
    }
    return $v;
}

/** 割合(%)。ゼロ割回避 + 小数1桁。report_rate と同じ挙動。 */
function edu_rep_rate(int $count, int $denom): float
{
    if ($denom === 0) {
        return 0.0;
    }
    return round($count / $denom * 100, 1);
}

/** 平均(小数1桁)。母数0なら0。 */
function edu_rep_avg(float $sum, int $count): float
{
    if ($count === 0) {
        return 0.0;
    }
    return round($sum / $count, 1);
}

function edu_rep_assert_delivery(int $id, int $tenantId): array
{
    $d = Db::one('SELECT * FROM edu_deliveries WHERE id = ? AND tenant_id = ?', [$id, $tenantId]);
    if ($d === null) {
        json_error('配信が見つかりません', 404);
    }
    return $d;
}

/** 配信1件の受講状況カウント。 */
function edu_rep_delivery_counts(int $deliveryId, int $tenantId): array
{
    $row = Db::one(
        "SELECT
            COUNT(*) AS assigned,
            SUM(CASE WHEN status = 'started'   THEN 1 ELSE 0 END) AS started,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed
         FROM edu_assignments
         WHERE tenant_id = ? AND delivery_id = ?",
        [$tenantId, $deliveryId]
    ) ?? [];
    return [
        'assigned' => (int) ($row['assigned'] ?? 0),
        'started' => (int) ($row['started'] ?? 0),
        'completed' => (int) ($row['completed'] ?? 0),
    ];
}

/** 配信1件の平均正答率と合格率(完了者ベース)。 */
function edu_rep_delivery_scores(int $deliveryId, int $tenantId, ?int $passScore): array
{
    $rows = Db::all(
        'SELECT r.percentage
         FROM edu_responses r
         INNER JOIN edu_assignments a ON a.id = r.assignment_id
         WHERE a.tenant_id = ? AND a.delivery_id = ?',
        [$tenantId, $deliveryId]
    );
    $n = count($rows);
    $sum = 0.0;
    $passed = 0;
    foreach ($rows as $r) {
        $p = (int) $r['percentage'];
        $sum += $p;
        if ($passScore !== null && $p >= $passScore) {
            $passed++;
        }
    }
    return [
        'respondent_count' => $n,
        'average_score' => edu_rep_avg($sum, $n),
        'pass_rate' => $passScore !== null ? edu_rep_rate($passed, $n) : null,
    ];
}

/** 配信内の設問別正答率(苦手把握用)。 */
function edu_rep_delivery_by_question(int $deliveryId, int $tenantId): array
{
    return Db::all(
        "SELECT q.id, q.title, q.difficulty,
                COUNT(ra.id) AS answered,
                SUM(CASE WHEN ra.is_correct = 1 THEN 1 ELSE 0 END) AS correct
         FROM edu_delivery_questions dq
         INNER JOIN edu_questions q ON q.id = dq.question_id
         LEFT JOIN edu_response_answers ra ON ra.question_id = q.id
              AND ra.response_id IN (
                  SELECT r.id FROM edu_responses r
                  INNER JOIN edu_assignments a ON a.id = r.assignment_id
                  WHERE a.tenant_id = ? AND a.delivery_id = ?
              )
         WHERE dq.delivery_id = ?
         GROUP BY q.id
         ORDER BY dq.sort_order, dq.id",
        [$tenantId, $deliveryId, $deliveryId]
    );
}

function edu_rep_handle_delivery(array $user): never
{
    $tenantId = effective_tenant_id($user, edu_rep_query_int('tenant_id'));
    $id = edu_rep_query_int('id');
    if ($id === null) {
        json_error('id は必須です', 400);
    }
    $delivery = edu_rep_assert_delivery($id, $tenantId);
    $passScore = $delivery['pass_score'] !== null ? (int) $delivery['pass_score'] : null;

    $counts = edu_rep_delivery_counts($id, $tenantId);
    $scores = edu_rep_delivery_scores($id, $tenantId, $passScore);

    $byQuestion = [];
    foreach (edu_rep_delivery_by_question($id, $tenantId) as $q) {
        $answered = (int) $q['answered'];
        $correct = (int) $q['correct'];
        $byQuestion[] = [
            'id' => (int) $q['id'],
            'title' => (string) $q['title'],
            'difficulty' => (int) $q['difficulty'],
            'answered' => $answered,
            'correct' => $correct,
            'correct_rate' => edu_rep_rate($correct, $answered),
        ];
    }

    json_out([
        'success' => true,
        'delivery' => [
            'id' => (int) $delivery['id'],
            'title' => (string) $delivery['title'],
            'delivery_type' => (string) $delivery['delivery_type'],
            'status' => (string) $delivery['status'],
            'pass_score' => $passScore,
        ],
        'summary' => [
            'assigned' => $counts['assigned'],
            'started' => $counts['started'],
            'completed' => $counts['completed'],
            'completion_rate' => edu_rep_rate($counts['completed'], $counts['assigned']),
            'respondent_count' => $scores['respondent_count'],
            'average_score' => $scores['average_score'],
            'pass_rate' => $scores['pass_rate'],
        ],
        'by_question' => $byQuestion,
    ]);
}

function edu_rep_handle_deliveries(array $user): never
{
    $tenantId = effective_tenant_id($user, edu_rep_query_int('tenant_id'));
    // 配信ごとの assigned/completed/平均% を1クエリで
    $rows = Db::all(
        "SELECT d.id, d.title, d.delivery_type, d.status, d.pass_score, d.created_at,
                COUNT(a.id) AS assigned,
                SUM(CASE WHEN a.status = 'completed' THEN 1 ELSE 0 END) AS completed,
                AVG(r.percentage) AS avg_pct
         FROM edu_deliveries d
         LEFT JOIN edu_assignments a ON a.delivery_id = d.id
         LEFT JOIN edu_responses r ON r.assignment_id = a.id
         WHERE d.tenant_id = ?
         GROUP BY d.id
         ORDER BY d.id DESC",
        [$tenantId]
    );
    $out = [];
    foreach ($rows as $r) {
        $assigned = (int) $r['assigned'];
        $completed = (int) $r['completed'];
        $out[] = [
            'id' => (int) $r['id'],
            'title' => (string) $r['title'],
            'delivery_type' => (string) $r['delivery_type'],
            'status' => (string) $r['status'],
            'pass_score' => $r['pass_score'] !== null ? (int) $r['pass_score'] : null,
            'assigned' => $assigned,
            'completed' => $completed,
            'completion_rate' => edu_rep_rate($completed, $assigned),
            'average_score' => $r['avg_pct'] !== null ? round((float) $r['avg_pct'], 1) : 0.0,
        ];
    }
    json_out(['success' => true, 'deliveries' => $out]);
}

function edu_rep_handle_overview(array $user): never
{
    $tenantId = effective_tenant_id($user, edu_rep_query_int('tenant_id'));

    // 全体の受講状況
    $counts = Db::one(
        "SELECT COUNT(*) AS assigned,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed
         FROM edu_assignments WHERE tenant_id = ?",
        [$tenantId]
    ) ?? [];
    $assigned = (int) ($counts['assigned'] ?? 0);
    $completed = (int) ($counts['completed'] ?? 0);

    // 平均リテラシースコア(完了レスポンスの平均%)
    $litRow = Db::one(
        'SELECT AVG(percentage) AS avg_pct, COUNT(*) AS n FROM edu_responses WHERE tenant_id = ?',
        [$tenantId]
    ) ?? [];
    $literacy = $litRow['avg_pct'] !== null ? round((float) $litRow['avg_pct'], 1) : 0.0;

    // 部署別ランキング(target.department 単位の平均%・完了数)
    $byDept = Db::all(
        "SELECT COALESCE(NULLIF(t.department, ''), '(未設定)') AS department,
                COUNT(DISTINCT r.id) AS respondent_count,
                AVG(r.percentage) AS avg_pct
         FROM edu_responses r
         INNER JOIN edu_assignments a ON a.id = r.assignment_id
         INNER JOIN targets t ON t.id = a.target_id
         WHERE r.tenant_id = ?
         GROUP BY department
         ORDER BY avg_pct DESC",
        [$tenantId]
    );
    $dept = [];
    foreach ($byDept as $d) {
        $dept[] = [
            'department' => (string) $d['department'],
            'respondent_count' => (int) $d['respondent_count'],
            'average_score' => $d['avg_pct'] !== null ? round((float) $d['avg_pct'], 1) : 0.0,
        ];
    }

    // カテゴリ別正答率(全配信横断・苦手カテゴリ分布)
    $byCat = Db::all(
        "SELECT c.slug, c.name,
                COUNT(ra.id) AS answered,
                SUM(CASE WHEN ra.is_correct = 1 THEN 1 ELSE 0 END) AS correct
         FROM edu_response_answers ra
         INNER JOIN edu_responses r ON r.id = ra.response_id
         INNER JOIN edu_questions q ON q.id = ra.question_id
         INNER JOIN edu_categories c ON c.id = q.category_id
         WHERE r.tenant_id = ?
         GROUP BY c.id
         ORDER BY c.sort_order, c.id",
        [$tenantId]
    );
    $cat = [];
    foreach ($byCat as $c) {
        $answered = (int) $c['answered'];
        $correct = (int) $c['correct'];
        $cat[] = [
            'slug' => (string) $c['slug'],
            'name' => (string) $c['name'],
            'answered' => $answered,
            'correct' => $correct,
            'correct_rate' => edu_rep_rate($correct, $answered),
        ];
    }

    json_out([
        'success' => true,
        'summary' => [
            'assigned' => $assigned,
            'completed' => $completed,
            'completion_rate' => edu_rep_rate($completed, $assigned),
            'literacy_score' => $literacy,
        ],
        'by_department' => $dept,
        'by_category' => $cat,
    ]);
}

function edu_rep_handle_cross(array $user): never
{
    // 訓練×教育クロス: 指定キャンペーンの失敗者(auth|click)が、その後の教育で
    // どれだけ受講・合格したか。ジャストインタイム教育の効果測定。
    $tenantId = effective_tenant_id($user, edu_rep_query_int('tenant_id'));
    $campaignId = edu_rep_query_int('campaign_id');
    if ($campaignId === null) {
        json_error('campaign_id は必須です', 400);
    }
    assert_campaign_owned($campaignId, $tenantId);

    // 訓練失敗者(target_id の集合)
    $failers = Db::all(
        "SELECT DISTINCT ct.target_id AS id
         FROM events e
         INNER JOIN campaign_targets ct ON ct.tracking_id = e.tracking_id
         WHERE e.tenant_id = ? AND e.campaign_id = ? AND e.event_type IN ('auth','click')",
        [$tenantId, $campaignId]
    );
    $failerIds = array_map(fn($r) => (int) $r['id'], $failers);
    $failCount = count($failerIds);

    $educated = 0;   // 失敗者のうち教育を割り当てられた人数
    $completed = 0;  // うち完了した人数
    $avgSum = 0.0;
    $avgN = 0;

    if ($failCount > 0) {
        $ph = implode(',', array_fill(0, $failCount, '?'));
        // 失敗者に紐づく edu_assignments(この campaign を起点にした配信、または全教育)
        $params = array_merge([$tenantId], $failerIds);
        $rows = Db::all(
            "SELECT a.target_id, a.status, r.percentage
             FROM edu_assignments a
             LEFT JOIN edu_responses r ON r.assignment_id = a.id
             WHERE a.tenant_id = ? AND a.target_id IN ($ph)",
            $params
        );
        $educatedSet = [];
        $completedSet = [];
        foreach ($rows as $r) {
            $tid = (int) $r['target_id'];
            $educatedSet[$tid] = true;
            if ((string) $r['status'] === 'completed') {
                $completedSet[$tid] = true;
                if ($r['percentage'] !== null) {
                    $avgSum += (int) $r['percentage'];
                    $avgN++;
                }
            }
        }
        $educated = count($educatedSet);
        $completed = count($completedSet);
    }

    json_out([
        'success' => true,
        'cross' => [
            'campaign_id' => $campaignId,
            'failer_count' => $failCount,
            'educated_count' => $educated,
            'education_coverage_rate' => edu_rep_rate($educated, $failCount),
            'completed_count' => $completed,
            'education_completion_rate' => edu_rep_rate($completed, $educated),
            'average_score_after' => edu_rep_avg($avgSum, $avgN),
        ],
    ]);
}

/**
 * 経年トレンド: edu_score_snapshots から company(全体)の日次系列 +
 * group(部署別)の直近日の系列を返す。company は折れ線、group は最新日の部署比較用。
 */
function edu_rep_handle_trend(array $user): never
{
    $tenantId = effective_tenant_id($user, edu_rep_query_int('tenant_id'));

    // company: 日付昇順の平均スコア + 回答者数(折れ線グラフ用)
    $companyRows = Db::all(
        "SELECT snapshot_date, average_score, respondent_count
         FROM edu_score_snapshots
         WHERE tenant_id = ? AND snapshot_type = 'company'
         ORDER BY snapshot_date ASC",
        [$tenantId]
    );
    $company = [];
    foreach ($companyRows as $r) {
        $company[] = [
            'date' => (string) $r['snapshot_date'],
            'average_score' => $r['average_score'] !== null ? round((float) $r['average_score'], 1) : 0.0,
            'respondent_count' => (int) $r['respondent_count'],
        ];
    }

    // group: 最新スナップショット日の部署別平均(横並び比較用)
    $latest = Db::one(
        "SELECT MAX(snapshot_date) AS d FROM edu_score_snapshots WHERE tenant_id = ? AND snapshot_type = 'group'",
        [$tenantId]
    );
    $groups = [];
    if ($latest !== null && $latest['d'] !== null) {
        $groupRows = Db::all(
            "SELECT average_score, respondent_count, category_scores
             FROM edu_score_snapshots
             WHERE tenant_id = ? AND snapshot_type = 'group' AND snapshot_date = ?
             ORDER BY average_score DESC",
            [$tenantId, (string) $latest['d']]
        );
        foreach ($groupRows as $r) {
            $cs = $r['category_scores'] !== null ? (json_decode((string) $r['category_scores'], true) ?: []) : [];
            $groups[] = [
                'department' => isset($cs['department']) ? (string) $cs['department'] : '(不明)',
                'average_score' => $r['average_score'] !== null ? round((float) $r['average_score'], 1) : 0.0,
                'respondent_count' => (int) $r['respondent_count'],
            ];
        }
    }

    json_out([
        'success' => true,
        'company' => $company,
        'group_latest_date' => $latest !== null ? ($latest['d'] ?? null) : null,
        'groups' => $groups,
    ]);
}

try {
    $action = $_GET['action'] ?? '';
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method !== 'GET') {
        json_error('不正なアクションです', 400);
    }
    $user = require_role('viewer');

    if ($action === 'delivery') {
        edu_rep_handle_delivery($user);
    }
    if ($action === 'deliveries') {
        edu_rep_handle_deliveries($user);
    }
    if ($action === 'overview') {
        edu_rep_handle_overview($user);
    }
    if ($action === 'cross') {
        edu_rep_handle_cross($user);
    }
    if ($action === 'trend') {
        edu_rep_handle_trend($user);
    }
    json_error('不正なアクションです', 400);
} catch (Throwable $e) {
    error_log($e->getMessage());
    json_error('サーバエラー', 500);
}
