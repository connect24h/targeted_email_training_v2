<?php
/**
 * 教育スコアの経年スナップショット生成。
 * 日次で company(テナント全体) と group(部署=target.department 単位) の
 * 平均スコア + カテゴリ別正答率を edu_score_snapshots に1行ずつ積む。
 *
 * 集計ロジックは api/edu_report.php の全体/部署別/カテゴリ別クエリと一致させている
 * (経年トレンドが当日レポートと同じ土俵で読めるように)。
 *
 * 冪等: 同一 (tenant_id, snapshot_type, group_id, snapshot_date) が既にあればスキップ。
 *   → 1日に複数回実行しても二重に積まない。日付は snapshot_date(YYYY-MM-DD)で判定。
 *
 * group スナップショットの group_id 列は「部署の識別子」ではなくスキーマ上 groups(id) FK。
 *   部署(department)は targets の自由文字列でありグループ実体を持たないため、
 *   group_id は NULL とし、部署名は category_scores JSON の "department" キーに保持する。
 *   (経年トレンドは department 文字列で突き合わせる)
 */
declare(strict_types=1);

require_once __DIR__ . '/Db.php';

final class EduSnapshot
{
    /**
     * 全テナントの当日スナップショットを生成する。
     * @param string|null $date snapshot_date(YYYY-MM-DD)。null なら本日(localtime)。
     * @return array{tenants:int, inserted:int, skipped:int, details:array<int,array{tenant_id:int,inserted:int,skipped:int}>}
     */
    public static function run(?string $date = null): array
    {
        $date = $date ?? date('Y-m-d');

        $tenants = Db::all('SELECT id FROM tenants ORDER BY id');
        $inserted = 0;
        $skipped  = 0;
        $details  = [];

        foreach ($tenants as $t) {
            $tenantId = (int) $t['id'];
            $r = self::snapshotTenant($tenantId, $date);
            $inserted += $r['inserted'];
            $skipped  += $r['skipped'];
            $details[] = ['tenant_id' => $tenantId, 'inserted' => $r['inserted'], 'skipped' => $r['skipped']];
        }

        return [
            'tenants'  => count($tenants),
            'inserted' => $inserted,
            'skipped'  => $skipped,
            'details'  => $details,
        ];
    }

    /**
     * 1テナント分の company + group スナップショットを積む。
     * @return array{inserted:int, skipped:int}
     */
    private static function snapshotTenant(int $tenantId, string $date): array
    {
        $inserted = 0;
        $skipped  = 0;

        // --- company: テナント全体の平均% + 回答者数 + カテゴリ別正答率 ---
        $lit = Db::one(
            'SELECT AVG(percentage) AS avg_pct, COUNT(*) AS n FROM edu_responses WHERE tenant_id = ?',
            [$tenantId]
        ) ?? [];
        $companyRespondents = (int) ($lit['n'] ?? 0);

        // 回答者ゼロのテナントは積まない(空トレンド行でグラフを汚さない)
        if ($companyRespondents > 0) {
            if (self::exists($tenantId, 'company', null, $date)) {
                $skipped++;
            } else {
                $avg = $lit['avg_pct'] !== null ? round((float) $lit['avg_pct'], 1) : 0.0;
                $catScores = self::categoryScores($tenantId, null);
                self::insertSnapshot($tenantId, 'company', null, null, $avg, $catScores, $companyRespondents, $date);
                $inserted++;
            }
        }

        // --- group: 部署(department)単位 ---
        $byDept = Db::all(
            "SELECT COALESCE(NULLIF(t.department, ''), '(未設定)') AS department,
                    COUNT(DISTINCT r.id) AS respondent_count,
                    AVG(r.percentage) AS avg_pct
             FROM edu_responses r
             INNER JOIN edu_assignments a ON a.id = r.assignment_id
             INNER JOIN targets t ON t.id = a.target_id
             WHERE r.tenant_id = ?
             GROUP BY department",
            [$tenantId]
        );

        foreach ($byDept as $d) {
            $department = (string) $d['department'];
            $respondents = (int) $d['respondent_count'];
            if ($respondents <= 0) {
                continue;
            }
            // 部署は文字列キー。冪等判定は (tenant,type=group,date) + JSON内 department で行うため
            // ここでは同一部署の当日行が既にあるかを department 込みで確認する。
            if (self::groupExists($tenantId, $department, $date)) {
                $skipped++;
                continue;
            }
            $avg = $d['avg_pct'] !== null ? round((float) $d['avg_pct'], 1) : 0.0;
            $catScores = self::categoryScores($tenantId, $department);
            $catScores['department'] = $department;
            self::insertSnapshot($tenantId, 'group', null, null, $avg, $catScores, $respondents, $date);
            $inserted++;
        }

        return ['inserted' => $inserted, 'skipped' => $skipped];
    }

    /**
     * カテゴリ別正答率。$department 指定時はその部署の受講者に絞る。
     * @return array<string,mixed> slug => correct_rate(%) の連想。
     */
    private static function categoryScores(int $tenantId, ?string $department): array
    {
        if ($department === null) {
            $rows = Db::all(
                "SELECT c.slug,
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
        } else {
            $rows = Db::all(
                "SELECT c.slug,
                        COUNT(ra.id) AS answered,
                        SUM(CASE WHEN ra.is_correct = 1 THEN 1 ELSE 0 END) AS correct
                 FROM edu_response_answers ra
                 INNER JOIN edu_responses r ON r.id = ra.response_id
                 INNER JOIN edu_assignments a ON a.id = r.assignment_id
                 INNER JOIN targets t ON t.id = a.target_id
                 INNER JOIN edu_questions q ON q.id = ra.question_id
                 INNER JOIN edu_categories c ON c.id = q.category_id
                 WHERE r.tenant_id = ? AND COALESCE(NULLIF(t.department, ''), '(未設定)') = ?
                 GROUP BY c.id
                 ORDER BY c.sort_order, c.id",
                [$tenantId, $department]
            );
        }

        $out = [];
        foreach ($rows as $row) {
            $answered = (int) $row['answered'];
            $correct  = (int) $row['correct'];
            $out[(string) $row['slug']] = $answered > 0 ? round($correct * 100 / $answered, 1) : 0.0;
        }
        return $out;
    }

    private static function exists(int $tenantId, string $type, ?int $groupId, string $date): bool
    {
        $row = Db::one(
            'SELECT 1 FROM edu_score_snapshots
             WHERE tenant_id = ? AND snapshot_type = ? AND snapshot_date = ?
               AND ((group_id IS NULL AND ? IS NULL) OR group_id = ?)',
            [$tenantId, $type, $date, $groupId, $groupId]
        );
        return $row !== null;
    }

    /** group 行の当日重複を department(JSON) 込みで確認する。 */
    private static function groupExists(int $tenantId, string $department, string $date): bool
    {
        $row = Db::one(
            "SELECT 1 FROM edu_score_snapshots
             WHERE tenant_id = ? AND snapshot_type = 'group' AND snapshot_date = ?
               AND json_extract(category_scores, '$.department') = ?",
            [$tenantId, $date, $department]
        );
        return $row !== null;
    }

    private static function insertSnapshot(
        int $tenantId,
        string $type,
        ?int $targetId,
        ?int $groupId,
        float $avg,
        array $catScores,
        int $respondents,
        string $date
    ): void {
        Db::run(
            'INSERT INTO edu_score_snapshots
                (tenant_id, snapshot_type, target_id, group_id, average_score, category_scores, respondent_count, snapshot_date)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $tenantId,
                $type,
                $targetId,
                $groupId,
                $avg,
                json_encode($catScores, JSON_UNESCAPED_UNICODE),
                $respondents,
                $date,
            ]
        );
    }
}
