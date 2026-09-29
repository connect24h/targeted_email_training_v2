<?php
/**
 * 教育レポートの解答の集計と出力(段B2 の G58 と G22)。api/edu_report.php から呼ぶ。読むだけ。
 *
 * - awarenessByLearner: アウェアネスの配信の、受講者ごとの正解、不正解、未回答、設問の数。
 *   設問の数は割当の配信の設問(edu_delivery_questions)の数、正解と不正解は最新の提出(edu_responses)の解答で数える。
 *   未回答 = 設問の数 − 正解 − 不正解(受講していない配信の設問は全部が未回答)。
 * - answerRows: 解答の1問1行(受講者、配信、設問)。提出した解答だけを出す(受講していない人の行は出さない)。
 *   解答の日時は、答えた直後に答え合わせをする配信では設問ごとの記録(edu_answer_locks)、ほかは提出の日時。
 *
 * どちらもテスト用の対象者と削除済み(active でない)の対象者を除く($realTargetSql、教育レポートの概要と同じ条件)。
 */
declare(strict_types=1);

require_once __DIR__ . '/Db.php';

final class EduAnswerReport
{
    /** 解答の1問1行の出力の上限。超えた分は出さず、打ち切ったことを知らせる。 */
    public const ANSWER_ROW_LIMIT = 50000;

    /**
     * @param array{delivery_id?: ?int, from?: ?string, to?: ?string} $filter from と to は配信日('YYYY-MM-DD')
     * @return list<array<string,mixed>>
     */
    public static function awarenessByLearner(int $tenantId, string $realTargetSql, array $filter): array
    {
        $where = ['a.tenant_id = ?', "d.delivery_type = 'awareness_quiz'", $realTargetSql];
        $params = [$tenantId];
        if (($filter['delivery_id'] ?? null) !== null) {
            $where[] = 'a.delivery_id = ?';
            $params[] = $filter['delivery_id'];
        }
        // 配信日は予約の日時、なければ作った日時(配信ごとの一覧と同じ)
        foreach (['from' => '>=', 'to' => '<='] as $key => $op) {
            if (($filter[$key] ?? null) !== null) {
                $where[] = "substr(COALESCE(d.scheduled_at, d.created_at), 1, 10) {$op} ?";
                $params[] = $filter[$key];
            }
        }
        $rows = Db::all(
            'SELECT a.target_id, a.status, t.name, t.email, t.employee_no, t.department,
                    (SELECT COUNT(*) FROM edu_delivery_questions dq WHERE dq.delivery_id = a.delivery_id) AS total,
                    (SELECT COUNT(*) FROM edu_responses r INNER JOIN edu_response_answers ra ON ra.response_id = r.id
                      WHERE r.assignment_id = a.id AND ra.is_correct = 1) AS correct,
                    (SELECT COUNT(*) FROM edu_responses r INNER JOIN edu_response_answers ra ON ra.response_id = r.id
                      WHERE r.assignment_id = a.id AND COALESCE(ra.is_correct, 0) != 1) AS incorrect
             FROM edu_assignments a
             INNER JOIN edu_deliveries d ON d.id = a.delivery_id AND d.tenant_id = a.tenant_id
             INNER JOIN targets t ON t.id = a.target_id AND t.tenant_id = a.tenant_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY t.name, t.id, a.id',
            $params
        );
        $people = [];
        foreach ($rows as $r) {
            $id = (int) $r['target_id'];
            $people[$id] ??= [
                'target_id' => $id,
                'name' => (string) ($r['name'] ?? ''),
                'email' => (string) $r['email'],
                'employee_no' => $r['employee_no'],
                'department' => trim((string) $r['department']) === '' ? '(未設定)' : (string) $r['department'],
                'deliveries' => 0, 'completed' => 0, 'total' => 0, 'correct' => 0, 'incorrect' => 0, 'unanswered' => 0,
            ];
            $p = &$people[$id];
            $p['deliveries']++;
            $p['completed'] += (string) $r['status'] === 'completed' ? 1 : 0;
            $p['total'] += (int) $r['total'];
            $p['correct'] += (int) $r['correct'];
            $p['incorrect'] += (int) $r['incorrect'];
            $p['unanswered'] += max(0, (int) $r['total'] - (int) $r['correct'] - (int) $r['incorrect']);
            unset($p);
        }
        return array_map(static function (array $p): array {
            $answered = $p['correct'] + $p['incorrect'];
            // 正答率は答えた設問のうちの正解(未回答は分母に入れず、別の列で見せる)
            $p['correct_rate'] = $answered > 0 ? round($p['correct'] / $answered * 100, 1) : null;
            return $p;
        }, array_values($people));
    }

    /**
     * 解答の1問1行。上限を超える分は出さない。
     * @param array{delivery_id?: ?int, from?: ?string, to?: ?string} $filter from と to は解答を提出した日('YYYY-MM-DD')
     * @return array{rows: list<array<string,mixed>>, truncated: bool}
     */
    public static function answerRows(int $tenantId, string $realTargetSql, array $filter, int $limit = self::ANSWER_ROW_LIMIT): array
    {
        $where = ['r.tenant_id = ?', $realTargetSql];
        $params = [$tenantId];
        if (($filter['delivery_id'] ?? null) !== null) {
            $where[] = 'a.delivery_id = ?';
            $params[] = $filter['delivery_id'];
        }
        foreach (['from' => '>=', 'to' => '<='] as $key => $op) {
            if (($filter[$key] ?? null) !== null) {
                $where[] = "substr(r.completed_at, 1, 10) {$op} ?";
                $params[] = $filter[$key];
            }
        }
        $rows = Db::all(
            'SELECT COALESCE(d.scheduled_at, d.created_at) AS delivery_date, d.title AS delivery_title, d.delivery_type,
                    t.name, t.email, t.employee_no, t.department,
                    c.name AS category, q.title AS question, q.options, ra.answer, ra.is_correct,
                    COALESCE(l.answered_at, r.completed_at) AS answered_at
             FROM edu_response_answers ra
             INNER JOIN edu_responses r ON r.id = ra.response_id
             INNER JOIN edu_assignments a ON a.id = r.assignment_id AND a.tenant_id = r.tenant_id
             INNER JOIN edu_deliveries d ON d.id = a.delivery_id AND d.tenant_id = a.tenant_id
             INNER JOIN targets t ON t.id = a.target_id AND t.tenant_id = a.tenant_id
             INNER JOIN edu_questions q ON q.id = ra.question_id
             LEFT JOIN edu_categories c ON c.id = q.category_id
             LEFT JOIN edu_answer_locks l ON l.assignment_id = a.id AND l.question_id = ra.question_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY d.id, t.name, t.id, ra.id
             LIMIT ' . ($limit + 1),
            $params
        );
        $truncated = count($rows) > $limit;
        if ($truncated) {
            array_pop($rows);
        }
        return [
            'rows' => array_map(static fn(array $r): array => [
                'delivery_date' => str_replace('T', ' ', (string) $r['delivery_date']),
                'delivery_title' => (string) $r['delivery_title'],
                'delivery_type' => (string) $r['delivery_type'],
                'name' => (string) ($r['name'] ?? ''),
                'email' => (string) $r['email'],
                'employee_no' => (string) ($r['employee_no'] ?? ''),
                'department' => trim((string) $r['department']) === '' ? '(未設定)' : (string) $r['department'],
                'category' => (string) ($r['category'] ?? ''),
                'question' => (string) $r['question'],
                'answer' => self::answerText((string) $r['options'], $r['answer']),
                'is_correct' => (int) $r['is_correct'] === 1,
                'answered_at' => (string) ($r['answered_at'] ?? ''),
            ], $rows),
            'truncated' => $truncated,
        ];
    }

    /** 解答(元の選択肢の番号の JSON)を選択肢の文にする。番号が選択肢にない時は番号のまま。 */
    public static function answerText(string $optionsJson, mixed $answerJson): string
    {
        $options = json_decode($optionsJson, true);
        $answer = json_decode((string) $answerJson, true);
        if (!is_array($answer)) {
            return '';
        }
        $texts = [];
        foreach ($answer as $index) {
            $texts[] = is_int($index) && is_array($options) && isset($options[$index]) ? (string) $options[$index] : (string) $index;
        }
        return implode(' / ', $texts);
    }
}
