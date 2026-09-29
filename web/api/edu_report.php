<?php declare(strict_types=1); require __DIR__."/../lib/bootstrap.php";
require_once __DIR__ . '/../lib/EduAnswerReport.php';
require_once __DIR__ . '/../lib/EduAutoEnrollRuns.php';

/**
 * 教育レポート API(集計・閲覧のみ)。report.php と同じ流儀。
 * - action=delivery  : 配信1件の集計(受講率/平均正答率/合格率/カテゴリ別/設問別)
 * - action=deliveries: 配信一覧の集計サマリ
 * - action=overview  : テナント全体(受講率、種類ごとの平均点と合格率、部署別ランキング、カテゴリ別)。テスト用と削除済みの対象者を除く
 * - action=trend     : 月ごとの推移(受講の記録から直接集計、種類ごとの系列)
 * - action=cross     : 訓練×教育クロス(訓練失敗者が教育で改善したか)
 * - action=delivery_people: 配信1件の受講者ごと(状態、点数、合否、受講回数、期限、完了日時)。format=csv で CSV
 * - action=delivery_depts : 配信1件の部署ごと(対象、完了、未完了、合格、合格率、期限内合格率)。format=csv で CSV
 * - action=learners  : 配信の割当がある受講者の検索(受講者ごとのタブの一覧)
 * - action=person    : 受講者1人の配信ごとの状態(配信を横断)
 * - action=awareness_people: アウェアネスの受講者ごとの正解、不正解、未回答(配信か配信日の期間で絞る)。format=csv で CSV
 * - action=answers   : 解答の1問1行の CSV。id で配信1件、なければ from と to(提出日)でテナント全体。上限 50000 行
 * - action=auto_runs : 自動の教育配信(訓練の失敗、新入社員)の実行履歴と、当てはまって入った人
 * すべて viewer 以上。テナント分離を機械付与。
 *
 * 合否と期限の決まり(delivery_people、delivery_depts、deliveries、person で共通):
 * - 合格: 合格点のある配信で、最新の提出の回の点数(edu_responses.percentage)が合格点以上。
 *   合格点のない配信(アウェアネス)は合否を出さず null、合格率も null。
 * - 期限: 配信の deadline、なければ割当の token_expiry(受講者のマイページと同じ順)。日付だけの値はその日の 23:59:59。
 * - 期限内合格: 合格し、完了日時(edu_assignments.completed_at)が期限以前。期限のない配信は、合格をすべて期限内として数える。
 * - 合格率と期限内合格率の分母は対象者(割当)の数。
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
            SUM(CASE WHEN a.status = 'started'   THEN 1 ELSE 0 END) AS started,
            SUM(CASE WHEN a.status = 'completed' THEN 1 ELSE 0 END) AS completed
         FROM edu_assignments a
         INNER JOIN targets t ON t.id = a.target_id AND t.tenant_id = a.tenant_id
         WHERE a.tenant_id = ? AND a.delivery_id = ? AND " . EDU_REP_REAL_TARGET_SQL,
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
         INNER JOIN targets t ON t.id = a.target_id AND t.tenant_id = a.tenant_id
         WHERE a.tenant_id = ? AND a.delivery_id = ? AND ' . EDU_REP_REAL_TARGET_SQL,
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
                  INNER JOIN targets t ON t.id = a.target_id AND t.tenant_id = a.tenant_id
                  WHERE a.tenant_id = ? AND a.delivery_id = ? AND " . EDU_REP_REAL_TARGET_SQL . "
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
                d.scheduled_at, d.deadline, d.series_id, d.send_invites, d.triggered_by,
                COUNT(a.id) AS assigned,
                SUM(CASE WHEN a.status = 'started' THEN 1 ELSE 0 END) AS started_count,
                SUM(CASE WHEN a.status = 'completed' THEN 1 ELSE 0 END) AS completed,
                AVG(r.percentage) AS avg_pct
         FROM edu_deliveries d
         LEFT JOIN edu_assignments a ON a.delivery_id = d.id
              AND EXISTS (SELECT 1 FROM targets t WHERE t.id = a.target_id AND t.tenant_id = a.tenant_id AND " . EDU_REP_REAL_TARGET_SQL . ")
         LEFT JOIN edu_responses r ON r.assignment_id = a.id
         WHERE d.tenant_id = ?
         GROUP BY d.id
         ORDER BY d.id DESC",
        [$tenantId]
    );
    $people = edu_rep_people_by_delivery($tenantId);
    $out = [];
    foreach ($rows as $r) {
        $assigned = (int) $r['assigned'];
        $completed = (int) $r['completed'];
        $agg = edu_rep_aggregate($people[(int) $r['id']] ?? [], $r['pass_score'] !== null);
        $out[] = [
            'id' => (int) $r['id'],
            'title' => (string) $r['title'],
            'delivery_type' => (string) $r['delivery_type'],
            'status' => (string) $r['status'],
            'pass_score' => $r['pass_score'] !== null ? (int) $r['pass_score'] : null,
            'scheduled_at' => $r['scheduled_at'],
            'series_id' => $r['series_id'] !== null ? (int) $r['series_id'] : null,
            'send_invites' => (int) $r['send_invites'],
            'triggered_by' => $r['triggered_by'],
            'assigned' => $assigned,
            'started_count' => (int) $r['started_count'],
            'completed' => $completed,
            'completion_rate' => edu_rep_rate($completed, $assigned),
            'average_score' => $r['avg_pct'] !== null ? round((float) $r['avg_pct'], 1) : 0.0,
            'created_at' => $r['created_at'],
            'deadline' => edu_rep_norm_deadline($r['deadline']),
            'incomplete' => $assigned - $completed,
            'passed' => $agg['passed'],
            'on_time_passed' => $agg['on_time_passed'],
            'pass_rate' => $agg['pass_rate'],
            'on_time_pass_rate' => $agg['on_time_pass_rate'],
        ];
    }
    json_out(['success' => true, 'deliveries' => $out]);
}

/**
 * 全社の数字に入れる受講者の条件(対象者の別名 t)。テスト用の対象者と、削除済み(active でない)の対象者を除く。
 * 部署別、種類別、カテゴリ別、推移で同じ条件を使う。
 */
const EDU_REP_REAL_TARGET_SQL = "t.is_test = 0 AND t.status = 'active'";

/** 配信の種類(概要と推移で分けて出す順)。 */
const EDU_REP_DELIVERY_TYPES = ['elearning', 'awareness_quiz'];

/**
 * 配信の種類ごとの割当、完了、提出の平均点と合格率。合格率は合格点のある配信(eラーニング)の提出だけで数え、
 * 合格点のない種類は null。
 * @return array<string, array<string, mixed>>
 */
function edu_rep_overview_by_type(int $tenantId): array
{
    $counts = Db::all(
        "SELECT d.delivery_type, COUNT(*) AS assigned,
                SUM(CASE WHEN a.status = 'completed' THEN 1 ELSE 0 END) AS completed
         FROM edu_assignments a
         INNER JOIN edu_deliveries d ON d.id = a.delivery_id AND d.tenant_id = a.tenant_id
         INNER JOIN targets t ON t.id = a.target_id AND t.tenant_id = a.tenant_id
         WHERE a.tenant_id = ? AND " . EDU_REP_REAL_TARGET_SQL . '
         GROUP BY d.delivery_type',
        [$tenantId]
    );
    $scores = Db::all(
        'SELECT d.delivery_type, COUNT(r.id) AS n, AVG(r.percentage) AS avg_pct,
                SUM(CASE WHEN d.pass_score IS NOT NULL THEN 1 ELSE 0 END) AS judged,
                SUM(CASE WHEN d.pass_score IS NOT NULL AND r.percentage >= d.pass_score THEN 1 ELSE 0 END) AS passed
         FROM edu_responses r
         INNER JOIN edu_assignments a ON a.id = r.assignment_id AND a.tenant_id = r.tenant_id
         INNER JOIN edu_deliveries d ON d.id = a.delivery_id AND d.tenant_id = a.tenant_id
         INNER JOIN targets t ON t.id = a.target_id AND t.tenant_id = a.tenant_id
         WHERE r.tenant_id = ? AND ' . EDU_REP_REAL_TARGET_SQL . '
         GROUP BY d.delivery_type',
        [$tenantId]
    );
    $countBy = array_column($counts, null, 'delivery_type');
    $scoreBy = array_column($scores, null, 'delivery_type');
    $out = [];
    foreach (EDU_REP_DELIVERY_TYPES as $type) {
        $assigned = (int) ($countBy[$type]['assigned'] ?? 0);
        $completed = (int) ($countBy[$type]['completed'] ?? 0);
        $n = (int) ($scoreBy[$type]['n'] ?? 0);
        $judged = (int) ($scoreBy[$type]['judged'] ?? 0);
        $avg = $scoreBy[$type]['avg_pct'] ?? null;
        $out[$type] = [
            'assigned' => $assigned,
            'completed' => $completed,
            'completion_rate' => edu_rep_rate($completed, $assigned),
            'respondent_count' => $n,
            'average_score' => $avg !== null ? round((float) $avg, 1) : null,
            'pass_rate' => $judged > 0 ? edu_rep_rate((int) $scoreBy[$type]['passed'], $judged) : null,
        ];
    }
    return $out;
}

function edu_rep_handle_overview(array $user): never
{
    $tenantId = effective_tenant_id($user, edu_rep_query_int('tenant_id'));

    // eラーニング(合格制)とアウェアネス(小問)は点数の意味が違うので、平均点は種類ごとに出す(1つにまとめない)
    $byType = edu_rep_overview_by_type($tenantId);
    $assigned = array_sum(array_column($byType, 'assigned'));
    $completed = array_sum(array_column($byType, 'completed'));

    // 部署別ランキング(target.department 単位の平均%・完了数)
    $byDept = Db::all(
        "SELECT COALESCE(NULLIF(t.department, ''), '(未設定)') AS department,
                COUNT(DISTINCT r.id) AS respondent_count,
                AVG(r.percentage) AS avg_pct
         FROM edu_responses r
         INNER JOIN edu_assignments a ON a.id = r.assignment_id
         INNER JOIN targets t ON t.id = a.target_id
         WHERE r.tenant_id = ? AND " . EDU_REP_REAL_TARGET_SQL . '
         GROUP BY department
         ORDER BY avg_pct DESC',
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
        'SELECT c.slug, c.name,
                COUNT(ra.id) AS answered,
                SUM(CASE WHEN ra.is_correct = 1 THEN 1 ELSE 0 END) AS correct
         FROM edu_response_answers ra
         INNER JOIN edu_responses r ON r.id = ra.response_id
         INNER JOIN edu_assignments a ON a.id = r.assignment_id AND a.tenant_id = r.tenant_id
         INNER JOIN targets t ON t.id = a.target_id AND t.tenant_id = a.tenant_id
         INNER JOIN edu_questions q ON q.id = ra.question_id
         INNER JOIN edu_categories c ON c.id = q.category_id
         WHERE r.tenant_id = ? AND ' . EDU_REP_REAL_TARGET_SQL . '
         GROUP BY c.id
         ORDER BY c.sort_order, c.id',
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
        ],
        'by_type' => $byType,
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
         WHERE e.tenant_id = ? AND e.campaign_id = ? AND e.event_type IN ('auth','click') AND e.verdict = 'user'",
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
 * 経年トレンド: 受講の記録(edu_responses の完了日時)から月ごとに直接集計する。種類(eラーニング、アウェアネス)ごとの系列で、
 * 月ごとに平均点、受講完了の数(割当が完了の提出)、回答者の数(提出した対象者の数)を返す。
 * edu_responses は割当ごとの最新の提出なので、受け直した人はその提出の月に数える。
 * テスト用と削除済みの対象者は入れない(概要と同じ条件)。積んだ記録(edu_score_snapshots)は読まない。
 */
function edu_rep_handle_trend(array $user): never
{
    $tenantId = effective_tenant_id($user, edu_rep_query_int('tenant_id'));
    $rows = Db::all(
        "SELECT substr(r.completed_at, 1, 7) AS month, d.delivery_type,
                AVG(r.percentage) AS avg_pct,
                SUM(CASE WHEN a.status = 'completed' THEN 1 ELSE 0 END) AS completions,
                COUNT(DISTINCT a.target_id) AS respondents
         FROM edu_responses r
         INNER JOIN edu_assignments a ON a.id = r.assignment_id AND a.tenant_id = r.tenant_id
         INNER JOIN edu_deliveries d ON d.id = a.delivery_id AND d.tenant_id = a.tenant_id
         INNER JOIN targets t ON t.id = a.target_id AND t.tenant_id = a.tenant_id
         WHERE r.tenant_id = ? AND r.completed_at IS NOT NULL AND " . EDU_REP_REAL_TARGET_SQL . '
         GROUP BY month, d.delivery_type
         ORDER BY month',
        [$tenantId]
    );
    $months = array_values(array_unique(array_map(static fn(array $r): string => (string) $r['month'], $rows)));
    $cells = [];
    foreach ($rows as $r) {
        $cells[(string) $r['delivery_type']][(string) $r['month']] = $r;
    }
    // 系列は月の並びをそろえ、受講のない月は平均なし(null)、数は0にする(折れ線のすき間になる)
    $series = [];
    foreach (EDU_REP_DELIVERY_TYPES as $type) {
        $series[$type] = array_map(static function (string $month) use ($cells, $type): array {
            $cell = $cells[$type][$month] ?? null;
            return [
                'month' => $month,
                'average_score' => $cell !== null ? round((float) $cell['avg_pct'], 1) : null,
                'completions' => $cell !== null ? (int) $cell['completions'] : 0,
                'respondents' => $cell !== null ? (int) $cell['respondents'] : 0,
            ];
        }, $months);
    }
    json_out(['success' => true, 'months' => $months, 'series' => $series]);
}

/** 期限などの日時をそろえる('YYYY-MM-DD HH:MM:SS')。空は null、日付だけはその日の終わり。 */
function edu_rep_norm_deadline(?string $v): ?string
{
    $v = $v === null ? '' : trim(str_replace('T', ' ', $v));
    if ($v === '') {
        return null;
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1) {
        return $v . ' 23:59:59';
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $v) === 1) {
        return $v . ':00';
    }
    return $v;
}

/**
 * 割当1件の合否と期限内合格。$row は percentage、completed_at、pass_score、deadline、token_expiry を持つ。
 * late は期限の後に完了したか(期限の後の受講を認める配信で起きる。合格点のない配信も出す)。
 * @return array{passed: ?bool, on_time: ?bool, deadline: ?string, late: bool}
 */
function edu_rep_judge(array $row): array
{
    $deadline = edu_rep_norm_deadline($row['deadline'] ?? null) ?? edu_rep_norm_deadline($row['token_expiry'] ?? null);
    $completedAt = edu_rep_norm_deadline($row['completed_at'] ?? null);
    $late = $deadline !== null && $completedAt !== null && $completedAt > $deadline;
    if ($row['pass_score'] === null) {
        return ['passed' => null, 'on_time' => null, 'deadline' => $deadline, 'late' => $late];
    }
    $passed = $row['percentage'] !== null && (int) $row['percentage'] >= (int) $row['pass_score'];
    // 期限のない配信は、合格をすべて期限内として数える
    $onTime = $passed && ($deadline === null || ($completedAt !== null && $completedAt <= $deadline));
    return ['passed' => $passed, 'on_time' => $onTime, 'deadline' => $deadline, 'late' => $late];
}

/**
 * 割当の行(受講者、配信、最新の結果、提出した回の数つき)。テナントで絞り、配信か受講者でさらに絞る。
 * @param array{delivery_id?: int, target_id?: int} $filter
 */
function edu_rep_assignment_rows(int $tenantId, array $filter): array
{
    $where = ['a.tenant_id = ?'];
    $params = [$tenantId, $tenantId];
    // 配信ごとの表と一覧は概要と同じく実対象者だけ。受講者を1人指定した表示(person)は指定された人をそのまま見せる
    if (!isset($filter['target_id'])) {
        $where[] = EDU_REP_REAL_TARGET_SQL;
    }
    foreach (['delivery_id', 'target_id'] as $key) {
        if (isset($filter[$key])) {
            $where[] = "a.$key = ?";
            $params[] = $filter[$key];
        }
    }
    return Db::all(
        "SELECT a.id AS assignment_id, a.delivery_id, a.target_id, a.status, a.completed_at, a.token_expiry,
                d.title AS delivery_title, d.delivery_type, d.pass_score, d.deadline,
                t.name, t.email, t.department, t.is_test,
                r.percentage, COALESCE(att.n, 0) AS attempt_count,
                (SELECT tt.material_version FROM edu_attempts tt
                  WHERE tt.assignment_id = a.id AND tt.completed_at IS NOT NULL
                  ORDER BY tt.attempt_no DESC LIMIT 1) AS material_version
         FROM edu_assignments a
         INNER JOIN edu_deliveries d ON d.id = a.delivery_id AND d.tenant_id = a.tenant_id
         INNER JOIN targets t ON t.id = a.target_id AND t.tenant_id = a.tenant_id
         LEFT JOIN edu_responses r ON r.assignment_id = a.id
         LEFT JOIN (SELECT assignment_id, COUNT(*) AS n FROM edu_attempts
                    WHERE tenant_id = ? AND completed_at IS NOT NULL GROUP BY assignment_id) att
                ON att.assignment_id = a.id
         WHERE " . implode(' AND ', $where) . '
         ORDER BY a.delivery_id DESC, t.name, t.id',
        $params
    );
}

function edu_rep_dept_label(?string $department): string
{
    $department = trim((string) $department);
    return $department === '' ? '(未設定)' : $department;
}

/** 割当の行を画面と CSV の形にする。 */
function edu_rep_present_person(array $row): array
{
    $judge = edu_rep_judge($row);
    return [
        'assignment_id' => (int) $row['assignment_id'],
        'delivery_id' => (int) $row['delivery_id'],
        'delivery_title' => (string) $row['delivery_title'],
        'delivery_type' => (string) $row['delivery_type'],
        'target_id' => (int) $row['target_id'],
        'name' => (string) ($row['name'] ?? ''),
        'email' => (string) $row['email'],
        'department' => edu_rep_dept_label($row['department']),
        'is_test' => (int) $row['is_test'],
        'status' => (string) $row['status'],
        'score' => $row['percentage'] !== null ? (int) $row['percentage'] : null,
        'passed' => $judge['passed'],
        'on_time' => $judge['on_time'],
        'late' => $judge['late'],
        'attempt_count' => (int) $row['attempt_count'],
        'material_version' => isset($row['material_version']) && $row['material_version'] !== null ? (int) $row['material_version'] : null,
        'deadline' => $judge['deadline'],
        'completed_at' => $row['completed_at'],
    ];
}

/** 配信 id → その配信の受講者ごとの行(配信一覧の合格率に使う)。 */
function edu_rep_people_by_delivery(int $tenantId): array
{
    $byDelivery = [];
    foreach (edu_rep_assignment_rows($tenantId, []) as $row) {
        $byDelivery[(int) $row['delivery_id']][] = edu_rep_present_person($row);
    }
    return $byDelivery;
}

/**
 * 受講者ごとの行の集計(配信、部署で共通)。合格点のない配信は合格の数と率を null にする。
 * @param list<array<string,mixed>> $people edu_rep_present_person の行
 */
function edu_rep_aggregate(array $people, bool $hasPassScore): array
{
    $assigned = count($people);
    $completed = count(array_filter($people, static fn(array $p): bool => $p['status'] === 'completed'));
    $passed = count(array_filter($people, static fn(array $p): bool => $p['passed'] === true));
    $onTime = count(array_filter($people, static fn(array $p): bool => $p['on_time'] === true));
    return [
        'assigned' => $assigned,
        'completed' => $completed,
        'incomplete' => $assigned - $completed,
        'passed' => $hasPassScore ? $passed : null,
        'on_time_passed' => $hasPassScore ? $onTime : null,
        'pass_rate' => $hasPassScore ? edu_rep_rate($passed, $assigned) : null,
        'on_time_pass_rate' => $hasPassScore ? edu_rep_rate($onTime, $assigned) : null,
    ];
}

function edu_rep_delivery_meta(array $delivery): array
{
    return [
        'id' => (int) $delivery['id'],
        'title' => (string) $delivery['title'],
        'delivery_type' => (string) $delivery['delivery_type'],
        'status' => (string) $delivery['status'],
        'pass_score' => $delivery['pass_score'] !== null ? (int) $delivery['pass_score'] : null,
        'deadline' => edu_rep_norm_deadline($delivery['deadline'] ?? null),
        'scheduled_at' => $delivery['scheduled_at'],
        'created_at' => $delivery['created_at'],
        'triggered_by' => $delivery['triggered_by'],
    ];
}

function edu_rep_query_format(): string
{
    $format = $_GET['format'] ?? '';
    if (!in_array($format, ['', 'csv'], true)) {
        json_error('format が不正です', 400);
    }
    return $format;
}

/** 検索の語(100文字まで)。 */
function edu_rep_query_q(): string
{
    $q = trim((string) ($_GET['q'] ?? ''));
    if (mb_strlen($q) > 100) {
        json_error('q が長すぎます', 400);
    }
    return $q;
}

/** 配信 id(必須)。ほかのテナントの配信は 404。 */
function edu_rep_required_delivery(int $tenantId): array
{
    $id = edu_rep_query_int('id');
    if ($id === null) {
        json_error('id は必須です', 400);
    }
    return edu_rep_assert_delivery($id, $tenantId);
}

const EDU_REP_STATUS_LABELS = ['assigned' => '未受講', 'started' => '受講中', 'completed' => '完了', 'expired' => '期限切れ'];

/**
 * CSV の本文。Excel で文字化けしないよう UTF-8 の BOM を付け、行末は CRLF。
 * 先頭が = + - @ などの値は ' を付けて式として扱われないようにする(tet2_csv_sanitize)。
 */
function edu_rep_csv(array $header, array $rows): string
{
    $out = fopen('php://temp', 'r+');
    fputcsv($out, array_map('tet2_csv_sanitize', $header), ',', '"', '', "\r\n");
    foreach ($rows as $cells) {
        fputcsv($out, array_map('tet2_csv_sanitize', $cells), ',', '"', '', "\r\n");
    }
    rewind($out);
    $csv = (string) stream_get_contents($out);
    fclose($out);
    return "\xEF\xBB\xBF" . $csv;
}

/** @param array<string,string> $extraHeaders 打ち切りの知らせなど */
function edu_rep_send_csv(string $filename, string $body, string $auditAction, string $detail, array $extraHeaders = []): never
{
    audit($auditAction, $detail);
    http_response_code(200);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    foreach ($extraHeaders as $name => $value) {
        header($name . ': ' . $value);
    }
    echo $body;
    exit;
}

function edu_rep_people_csv(array $people): string
{
    // 合否は、合格点のない配信と点数のない人(未受講)は空にする('-' は式の無害化で '- になるため使わない)
    $passed = static fn(array $p): string => $p['passed'] === null || $p['score'] === null ? '' : ($p['passed'] ? '合格' : '不合格');
    $rows = array_map(static fn(array $p): array => [
        EDU_REP_STATUS_LABELS[$p['status']] ?? $p['status'], $p['name'], $p['email'], $p['department'],
        $p['score'] ?? '', $passed($p), $p['attempt_count'], $p['deadline'] ?? '', $p['completed_at'] ?? '',
    ], $people);
    return edu_rep_csv(['状態', '氏名', 'メール', '部署', '点数', '合否', '受講回数', '期限', '完了日時'], $rows);
}

function edu_rep_depts_csv(array $depts): string
{
    $rows = array_map(static fn(array $d): array => [
        $d['department'], $d['assigned'], $d['completed'], $d['incomplete'], $d['passed'] ?? '',
        $d['pass_rate'] ?? '', $d['on_time_pass_rate'] ?? '',
    ], $depts);
    return edu_rep_csv(['部署', '対象', '完了', '未完了', '合格', '合格率(%)', '期限内合格率(%)'], $rows);
}

/** 配信1件の受講者ごと。incomplete=1 で未完了だけ、q で氏名かメールの部分一致。 */
function edu_rep_handle_delivery_people(array $user): never
{
    $tenantId = effective_tenant_id($user, edu_rep_query_int('tenant_id'));
    $format = edu_rep_query_format();
    $q = edu_rep_query_q();
    $delivery = edu_rep_required_delivery($tenantId);
    $id = (int) $delivery['id'];
    $incompleteOnly = ($_GET['incomplete'] ?? '') === '1';
    $all = array_map('edu_rep_present_person', edu_rep_assignment_rows($tenantId, ['delivery_id' => $id]));
    $people = array_values(array_filter($all, static fn(array $p): bool =>
        (!$incompleteOnly || $p['status'] !== 'completed')
        && ($q === '' || mb_stripos($p['name'], $q) !== false || mb_stripos($p['email'], $q) !== false)));
    if ($format === 'csv') {
        edu_rep_send_csv('edu_delivery_' . $id . '_people_' . date('Ymd') . '.csv', edu_rep_people_csv($people),
            'edu_report.delivery_people_csv', 'delivery_id=' . $id . ',rows=' . count($people));
    }
    json_out([
        'success' => true,
        'delivery' => edu_rep_delivery_meta($delivery),
        'summary' => edu_rep_aggregate($all, $delivery['pass_score'] !== null),
        'people' => $people,
    ]);
}

/** 配信1件の部署ごと(部署が空の人は「(未設定)」にまとめる)。 */
function edu_rep_handle_delivery_depts(array $user): never
{
    $tenantId = effective_tenant_id($user, edu_rep_query_int('tenant_id'));
    $format = edu_rep_query_format();
    $delivery = edu_rep_required_delivery($tenantId);
    $id = (int) $delivery['id'];
    $groups = [];
    foreach (edu_rep_assignment_rows($tenantId, ['delivery_id' => $id]) as $row) {
        $person = edu_rep_present_person($row);
        $groups[$person['department']][] = $person;
    }
    ksort($groups, SORT_STRING);
    $depts = [];
    foreach ($groups as $department => $people) {
        $depts[] = ['department' => (string) $department] + edu_rep_aggregate($people, $delivery['pass_score'] !== null);
    }
    if ($format === 'csv') {
        edu_rep_send_csv('edu_delivery_' . $id . '_depts_' . date('Ymd') . '.csv', edu_rep_depts_csv($depts),
            'edu_report.delivery_depts_csv', 'delivery_id=' . $id . ',rows=' . count($depts));
    }
    json_out(['success' => true, 'delivery' => edu_rep_delivery_meta($delivery), 'departments' => $depts]);
}

/** 配信の割当がある受講者の検索(氏名、メール、部署の部分一致。最大100人)。 */
function edu_rep_handle_learners(array $user): never
{
    $tenantId = effective_tenant_id($user, edu_rep_query_int('tenant_id'));
    $q = edu_rep_query_q();
    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
    $rows = Db::all(
        "SELECT t.id, t.name, t.email, t.department, t.is_test,
                COUNT(a.id) AS assigned,
                SUM(CASE WHEN a.status = 'completed' THEN 1 ELSE 0 END) AS completed
         FROM targets t
         INNER JOIN edu_assignments a ON a.target_id = t.id AND a.tenant_id = t.tenant_id
         WHERE t.tenant_id = ? AND " . EDU_REP_REAL_TARGET_SQL . "
           AND (? = '' OR t.name LIKE ? ESCAPE '\\' OR t.email LIKE ? ESCAPE '\\' OR t.department LIKE ? ESCAPE '\\')
         GROUP BY t.id
         ORDER BY t.name, t.id
         LIMIT 100",
        [$tenantId, $q, $like, $like, $like]
    );
    $learners = array_map(static fn(array $r): array => [
        'id' => (int) $r['id'], 'name' => (string) ($r['name'] ?? ''), 'email' => (string) $r['email'],
        'department' => edu_rep_dept_label($r['department']), 'is_test' => (int) $r['is_test'],
        'assigned' => (int) $r['assigned'], 'completed' => (int) $r['completed'],
        'incomplete' => (int) $r['assigned'] - (int) $r['completed'],
    ], $rows);
    json_out(['success' => true, 'learners' => $learners]);
}

/** 受講者1人の配信ごとの状態、点数、合否、受講回数、完了日時。ほかのテナントの受講者は 404。 */
function edu_rep_handle_person(array $user): never
{
    $tenantId = effective_tenant_id($user, edu_rep_query_int('tenant_id'));
    $targetId = edu_rep_query_int('target_id');
    if ($targetId === null) {
        json_error('target_id は必須です', 400);
    }
    $target = Db::one('SELECT id, name, email, department, is_test FROM targets WHERE id = ? AND tenant_id = ?', [$targetId, $tenantId]);
    if ($target === null) {
        json_error('受講者が見つかりません', 404);
    }
    json_out([
        'success' => true,
        'person' => [
            'id' => (int) $target['id'], 'name' => (string) ($target['name'] ?? ''), 'email' => (string) $target['email'],
            'department' => edu_rep_dept_label($target['department']), 'is_test' => (int) $target['is_test'],
        ],
        'deliveries' => array_map('edu_rep_present_person', edu_rep_assignment_rows($tenantId, ['target_id' => $targetId])),
    ]);
}

/** 日付の絞り込み('YYYY-MM-DD')。空は null。 */
function edu_rep_query_date(string $key): ?string
{
    $v = trim((string) ($_GET[$key] ?? ''));
    if ($v === '') {
        return null;
    }
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) !== 1 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
        json_error($key . ' が不正です（YYYY-MM-DD）', 400);
    }
    return $v;
}

/** from と to。from が to より後なら 400。 */
function edu_rep_query_period(): array
{
    $from = edu_rep_query_date('from');
    $to = edu_rep_query_date('to');
    if ($from !== null && $to !== null && $from > $to) {
        json_error('期間の始まりが終わりより後です', 400);
    }
    return [$from, $to];
}

function edu_rep_awareness_csv(array $people): string
{
    $rows = array_map(static fn(array $p): array => [
        $p['name'], $p['email'], $p['employee_no'] ?? '', $p['department'], $p['deliveries'], $p['completed'],
        $p['total'], $p['correct'], $p['incorrect'], $p['unanswered'], $p['correct_rate'] ?? '',
    ], $people);
    return edu_rep_csv(['氏名', 'メール', '従業員番号', '部署', '配信', '受講完了', '設問', '正解', '不正解', '未回答', '正答率(%)'], $rows);
}

/** アウェアネスの受講者ごとの成績(G58)。delivery_id で配信1件、from と to で配信日の期間に絞る。 */
function edu_rep_handle_awareness_people(array $user): never
{
    $tenantId = effective_tenant_id($user, edu_rep_query_int('tenant_id'));
    $format = edu_rep_query_format();
    $deliveryId = edu_rep_query_int('delivery_id');
    if ($deliveryId !== null && (string) edu_rep_assert_delivery($deliveryId, $tenantId)['delivery_type'] !== 'awareness_quiz') {
        json_error('アウェアネスの配信ではありません', 400);
    }
    [$from, $to] = edu_rep_query_period();
    $people = EduAnswerReport::awarenessByLearner($tenantId, EDU_REP_REAL_TARGET_SQL, ['delivery_id' => $deliveryId, 'from' => $from, 'to' => $to]);
    if ($format === 'csv') {
        edu_rep_send_csv('edu_awareness_people_' . date('Ymd') . '.csv', edu_rep_awareness_csv($people), 'edu_report.awareness_people_csv',
            'delivery_id=' . ($deliveryId ?? '') . ',from=' . ($from ?? '') . ',to=' . ($to ?? '') . ',rows=' . count($people));
    }
    $sum = static fn(string $k): int => array_sum(array_column($people, $k));
    json_out(['success' => true, 'people' => $people, 'summary' => [
        'learners' => count($people), 'total' => $sum('total'), 'correct' => $sum('correct'),
        'incorrect' => $sum('incorrect'), 'unanswered' => $sum('unanswered'),
    ]]);
}

/** 解答の1問1行の CSV(G22)。id で配信1件、なければ from と to(提出日)が要る。上限を超えたら打ち切り、ヘッダーで知らせる。 */
function edu_rep_handle_answers(array $user): never
{
    $tenantId = effective_tenant_id($user, edu_rep_query_int('tenant_id'));
    $id = edu_rep_query_int('id');
    [$from, $to] = edu_rep_query_period();
    if ($id === null && ($from === null || $to === null)) {
        json_error('配信(id)か、期間(from と to)を指定してください', 400);
    }
    if ($id !== null) {
        edu_rep_assert_delivery($id, $tenantId);
    }
    $result = EduAnswerReport::answerRows($tenantId, EDU_REP_REAL_TARGET_SQL, ['delivery_id' => $id, 'from' => $from, 'to' => $to]);
    $rows = array_map(static fn(array $r): array => [
        $r['delivery_date'], $r['delivery_title'], $r['delivery_type'] === 'awareness_quiz' ? 'アウェアネス' : 'eラーニング',
        $r['name'], $r['email'], $r['employee_no'], $r['department'], $r['category'], $r['question'], $r['answer'],
        $r['is_correct'] ? '正解' : '不正解', $r['answered_at'],
    ], $result['rows']);
    $body = edu_rep_csv(['配信日', '配信', '種類', '氏名', 'メール', '従業員番号', '部署', 'カテゴリ', '設問', '解答', '正誤', '解答日時'], $rows);
    $name = $id !== null ? 'edu_delivery_' . $id . '_answers_' : 'edu_answers_' . str_replace('-', '', (string) $from) . '_' . str_replace('-', '', (string) $to) . '_';
    edu_rep_send_csv($name . date('Ymd') . '.csv', $body, 'edu_report.answers_csv',
        'delivery_id=' . ($id ?? '') . ',from=' . ($from ?? '') . ',to=' . ($to ?? '') . ',rows=' . count($rows) . ($result['truncated'] ? ',truncated=1' : ''),
        $result['truncated'] ? ['X-Tet2-Truncated' => (string) EduAnswerReport::ANSWER_ROW_LIMIT] : []);
}

/** 自動の教育配信の実行履歴と、当てはまって入った人(G61)。 */
function edu_rep_handle_auto_runs(array $user): never
{
    $tenantId = effective_tenant_id($user, edu_rep_query_int('tenant_id'));
    $delivery = edu_rep_required_delivery($tenantId);
    json_out(['success' => true, 'delivery' => edu_rep_delivery_meta($delivery)]
        + EduAutoEnrollRuns::forDelivery($tenantId, (int) $delivery['id']));
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
    if ($action === 'delivery_people') {
        edu_rep_handle_delivery_people($user);
    }
    if ($action === 'delivery_depts') {
        edu_rep_handle_delivery_depts($user);
    }
    if ($action === 'learners') {
        edu_rep_handle_learners($user);
    }
    if ($action === 'person') {
        edu_rep_handle_person($user);
    }
    if ($action === 'awareness_people') {
        edu_rep_handle_awareness_people($user);
    }
    if ($action === 'answers') {
        edu_rep_handle_answers($user);
    }
    if ($action === 'auto_runs') {
        edu_rep_handle_auto_runs($user);
    }
    json_error('不正なアクションです', 400);
} catch (Throwable $e) {
    error_log($e->getMessage());
    json_error('サーバエラー', 500);
}
