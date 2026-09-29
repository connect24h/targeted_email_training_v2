<?php
declare(strict_types=1);

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/EduTags.php';
require_once __DIR__ . '/DeptPath.php';

/**
 * 分野(タグ)ごとの正答率(G09、G59、G60 の残り)。教育レポートの「分野」のタブと、受講者のマイページで使う。
 *
 * 数え方(カテゴリ別の正答率と同じ): 解答の1行(edu_response_answers、割当ごとの最新の提出)を1つと数え、正解の割合を出す。
 * - 親のタグの数字は、親か、その子のタグが付いた設問の解答。1つの解答は、同じ親に何本のタグで属しても1回だけ数える。
 * - 子のタグの数字は、そのタグが付いた設問の解答。
 * - 1つの設問に2つの親のタグが付いていれば、その解答は両方の親で数える(分野ごとの合計は解答の数と一致しない)。
 * - 使うタグは、そのテナントに見えるタグ(共有と自組織)だけ。解答が1つもなければ正答率は null(画面では空欄)。
 */
final class EduTagReport
{
    /** 設問 → 親のタグ(子のタグは親に寄せる)。パラメータは tenant_id 1つ。 */
    public const PARENT_MAP_SQL = 'SELECT DISTINCT qt.question_id, COALESCE(g.parent_id, g.id) AS tag_id
         FROM edu_question_tags qt INNER JOIN edu_tags g ON g.id = qt.tag_id
         WHERE g.tenant_id = ? OR g.tenant_id IS NULL';

    /** 設問 → 子のタグ。パラメータは tenant_id 1つ。 */
    private const CHILD_MAP_SQL = 'SELECT qt.question_id, qt.tag_id
         FROM edu_question_tags qt INNER JOIN edu_tags g ON g.id = qt.tag_id
         WHERE g.parent_id IS NOT NULL AND (g.tenant_id = ? OR g.tenant_id IS NULL)';

    private const DEPT_SQL = "CASE WHEN TRIM(COALESCE(t.department, '')) = '' THEN '(未設定)' ELSE TRIM(t.department) END";

    /**
     * 見えるタグ(親の直後に子)ごとの回答数と正答率。
     * @return list<array{id:int,parent_id:?int,name:string,path:string,is_shared:int,answered:int,correct:int,correct_rate:?float}>
     */
    public static function byTag(int $tenantId, string $realTargetSql): array
    {
        $stats = [];
        foreach ([self::PARENT_MAP_SQL, self::CHILD_MAP_SQL] as $map) {
            foreach (self::aggregate($tenantId, $realTargetSql, $map, 'm.tag_id', '') as $row) {
                $stats[(int) $row['tag_id']] = $row;
            }
        }
        return array_map(static function (array $tag) use ($stats): array {
            $s = $stats[(int) $tag['id']] ?? null;
            return [
                'id' => (int) $tag['id'],
                'parent_id' => $tag['parent_id'] !== null ? (int) $tag['parent_id'] : null,
                'name' => (string) $tag['name'],
                'path' => (string) $tag['path'],
                'is_shared' => (int) $tag['is_shared'],
                'answered' => (int) ($s['answered'] ?? 0),
                'correct' => (int) ($s['correct'] ?? 0),
                'correct_rate' => self::rate((int) ($s['correct'] ?? 0), (int) ($s['answered'] ?? 0)),
            ];
        }, EduTags::visible($tenantId));
    }

    /**
     * 部署 × 親のタグの正答率。列は解答のある親のタグ(タグの並び順)、行は部署の名前の順。解答のないマスは正答率 null。
     * $deptLevel が 1、2 なら部署を段でまとめ(DeptPath)、解答の数と正解の数を足してから正答率を計算し直す。
     * 1つの解答は部署と親のタグの組の1つにだけ入るので、足しても二重に数えない。
     * @return array{tags: list<array{id:int,name:string}>, departments: list<array{department:string,cells:list<array{tag_id:int,answered:int,correct:int,correct_rate:?float}>}>}
     */
    public static function departmentMatrix(int $tenantId, string $realTargetSql, int $deptLevel = 0): array
    {
        $cells = [];
        foreach (self::aggregate($tenantId, $realTargetSql, self::PARENT_MAP_SQL, 'm.tag_id, ' . self::DEPT_SQL, self::DEPT_SQL . ' AS department,') as $row) {
            if ($deptLevel === 0) {
                $cells[(string) $row['department']][(int) $row['tag_id']] = $row;
                continue;
            }
            $cell = &$cells[DeptPath::label((string) $row['department'], $deptLevel)][(int) $row['tag_id']];
            $cell = ['answered' => ($cell['answered'] ?? 0) + (int) $row['answered'], 'correct' => ($cell['correct'] ?? 0) + (int) $row['correct']];
            unset($cell);
        }
        $tags = self::parentsWithData($tenantId, array_merge(...array_map('array_keys', array_values($cells ?: [[]]))));
        ksort($cells, SORT_STRING);
        $departments = [];
        foreach ($cells as $department => $byTag) {
            $departments[] = ['department' => (string) $department, 'cells' => array_map(
                static fn(array $tag): array => self::cell($tag['id'], $byTag[$tag['id']] ?? null), $tags)];
        }
        return ['tags' => $tags, 'departments' => $departments];
    }

    /**
     * 親のタグごとの月の正答率(提出の月、$currentMonth を含む直近12か月)。系列は期間に解答のある親のタグだけ。
     * @return array{months: list<string>, series: list<array{tag_id:int,name:string,points:list<array{month:string,answered:int,correct:int,correct_rate:?float}>}>}
     */
    public static function monthlyTrend(int $tenantId, string $realTargetSql, string $currentMonth): array
    {
        $end = DateTimeImmutable::createFromFormat('!Y-m', $currentMonth);
        if ($end === false) {
            throw new InvalidArgumentException('月の形が不正です');
        }
        $months = [];
        for ($i = 11; $i >= 0; $i--) {
            $months[] = $end->modify("-{$i} months")->format('Y-m');
        }
        $byTag = [];
        $rows = self::aggregate($tenantId, $realTargetSql, self::PARENT_MAP_SQL, 'm.tag_id, substr(r.completed_at, 1, 7)',
            'substr(r.completed_at, 1, 7) AS month,', ['r.completed_at >= ?', 'r.completed_at < ?'],
            [$months[0] . '-01', $end->modify('+1 month')->format('Y-m-01')]);
        foreach ($rows as $row) {
            $byTag[(int) $row['tag_id']][(string) $row['month']] = $row;
        }
        $series = array_map(static fn(array $tag): array => [
            'tag_id' => $tag['id'],
            'name' => $tag['name'],
            'points' => array_map(static fn(string $m): array => ['month' => $m] + self::cell($tag['id'], $byTag[$tag['id']][$m] ?? null), $months),
        ], self::parentsWithData($tenantId, array_keys($byTag)));
        return ['months' => $months, 'series' => $series];
    }

    /**
     * 受講者1人の親のタグごとの正答率(自分の解答だけ。$deliveryFilterSql は配信 d で見せる条件)。
     * @return list<array{tag_id:int,name:string,answered:int,correct:int,correct_rate:?float}>
     */
    public static function learnerByParent(int $tenantId, int $targetId, string $deliveryFilterSql): array
    {
        $rows = Db::all(
            'SELECT m.tag_id, COUNT(ra.id) AS answered, SUM(CASE WHEN ra.is_correct = 1 THEN 1 ELSE 0 END) AS correct
             FROM edu_response_answers ra
             INNER JOIN edu_responses r ON r.id = ra.response_id
             INNER JOIN edu_assignments a ON a.id = r.assignment_id AND a.tenant_id = r.tenant_id
             INNER JOIN edu_deliveries d ON d.id = a.delivery_id AND d.tenant_id = a.tenant_id
             INNER JOIN (' . self::PARENT_MAP_SQL . ') m ON m.question_id = ra.question_id
             WHERE r.tenant_id = ? AND a.target_id = ? AND ' . $deliveryFilterSql . '
             GROUP BY m.tag_id',
            [$tenantId, $tenantId, $targetId]
        );
        $stats = array_column($rows, null, 'tag_id');
        return array_map(static fn(array $tag): array => ['tag_id' => $tag['id'], 'name' => $tag['name']]
            + self::cell($tag['id'], $stats[$tag['id']] ?? null), self::parentsWithData($tenantId, array_map('intval', array_keys($stats))));
    }

    /**
     * 解答を、設問と分野の対応($mapSql)で結んで数える。テナントと実対象者($realTargetSql、対象者の別名 t)で絞る。
     * @param list<string> $extraWhere
     * @param list<string> $extraParams
     */
    private static function aggregate(int $tenantId, string $realTargetSql, string $mapSql, string $groupBy, string $select,
        array $extraWhere = [], array $extraParams = []): array
    {
        return Db::all(
            'SELECT ' . $select . ' m.tag_id, COUNT(ra.id) AS answered, SUM(CASE WHEN ra.is_correct = 1 THEN 1 ELSE 0 END) AS correct
             FROM edu_response_answers ra
             INNER JOIN edu_responses r ON r.id = ra.response_id
             INNER JOIN edu_assignments a ON a.id = r.assignment_id AND a.tenant_id = r.tenant_id
             INNER JOIN targets t ON t.id = a.target_id AND t.tenant_id = a.tenant_id
             INNER JOIN (' . $mapSql . ') m ON m.question_id = ra.question_id
             WHERE ' . implode(' AND ', array_merge(['r.tenant_id = ?', $realTargetSql], $extraWhere)) . '
             GROUP BY ' . $groupBy,
            array_merge([$tenantId, $tenantId], $extraParams)
        );
    }

    /**
     * 解答のある親のタグ(見えるタグの並び順)。
     * @param list<int> $ids
     * @return list<array{id:int,name:string}>
     */
    private static function parentsWithData(int $tenantId, array $ids): array
    {
        $wanted = array_flip($ids);
        $out = [];
        foreach (EduTags::visible($tenantId) as $tag) {
            if ($tag['parent_id'] === null && isset($wanted[(int) $tag['id']])) {
                $out[] = ['id' => (int) $tag['id'], 'name' => (string) $tag['name']];
            }
        }
        return $out;
    }

    private static function cell(int $tagId, ?array $row): array
    {
        $answered = (int) ($row['answered'] ?? 0);
        $correct = (int) ($row['correct'] ?? 0);
        return ['tag_id' => $tagId, 'answered' => $answered, 'correct' => $correct, 'correct_rate' => self::rate($correct, $answered)];
    }

    /** 正答率(%、小数1桁)。解答がなければ null(0% と区別する)。 */
    private static function rate(int $correct, int $answered): ?float
    {
        return $answered === 0 ? null : round($correct / $answered * 100, 1);
    }
}
