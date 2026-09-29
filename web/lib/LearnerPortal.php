<?php
/**
 * 受講者のマイページのデータ(L3 ホーム、L4 成績、L5 再受講、L7 アンケート)。
 *
 * - どの関数も、ログイン中の人の対象者(LearnerAuth::current() の target)の id とテナントだけを受け取る。
 *   画面から来る id は配信の選択(delivery_id)だけで、それもこの対象者の割当に限って引く(ほかの人の結果は引けない)。
 * - 教育(edu_*)とアンケート(survey_*)の表だけを読む。訓練の表は読まない(L6 は作らない。learner_portal_test で確かめる)。
 * - 受講と回答のリンクは、既存のトークンのページ(take.php、survey.php)をそのまま使う。
 */
declare(strict_types=1);

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/EduAttempts.php';

final class LearnerPortal
{
    /** 答え合わせを見せてよい配信の feedback_mode。ここにない値(見せない設定)の配信は、解答と正解と解説を出さない。 */
    public const REVIEW_FEEDBACK_MODES = ['after_submit', 'immediate'];

    /**
     * マイページを使える管理画面のユーザの役割(LearnerAuth::ADMIN_ROLES と同じ。learner_portal_test で一致を確かめる)。
     * 受講の API(edu_take.php)は LearnerAuth を読まないので、ここに持つ。
     */
    public const ACCOUNT_ADMIN_ROLES = ['viewer', 'operator', 'tenant_admin'];

    /** 受講者に見せない配信の状態(開始前と中止)。 */
    private const HIDDEN_DELIVERY_STATUSES = "('draft','scheduled','cancelled')";

    /**
     * ホームの ToDo: 未受講・受講中の教育と、未回答のアンケート。期限の近い順(期限なしは最後)。
     * @return list<array<string,mixed>>
     */
    public static function todos(int $tenantId, int $targetId): array
    {
        $items = [];
        $edu = Db::all(
            'SELECT a.status, a.access_token, a.token_expiry, d.title, d.delivery_type, d.deadline, d.allow_after_deadline
             FROM edu_assignments a
             INNER JOIN edu_deliveries d ON d.id = a.delivery_id AND d.tenant_id = a.tenant_id
             WHERE a.tenant_id = ? AND a.target_id = ? AND a.status IN (\'assigned\', \'started\')
               AND d.status NOT IN ' . self::HIDDEN_DELIVERY_STATUSES,
            [$tenantId, $targetId]
        );
        foreach ($edu as $r) {
            if (EduAttempts::closedByDeadline($r)) {
                continue; // 期限切れは受講できないので ToDo に出さない(成績の画面には出る)。期限の後の受講を認める配信は出す
            }
            $items[] = [
                'kind' => 'edu',
                'title' => (string) $r['title'],
                'delivery_type' => (string) $r['delivery_type'],
                'status' => (string) $r['status'],
                'deadline' => self::deadlineOf($r['deadline'], $r['token_expiry']),
                'url' => 'take.php?token=' . rawurlencode((string) $r['access_token']),
            ];
        }
        foreach (self::openSurveyRows($tenantId, $targetId) as $r) {
            $items[] = [
                'kind' => 'survey',
                'title' => (string) $r['title'],
                'status' => 'assigned',
                'deadline' => $r['deadline'] !== null ? (string) $r['deadline'] : null,
                'url' => 'survey.php?token=' . rawurlencode((string) $r['access_token']),
            ];
        }
        usort($items, static function (array $a, array $b): int {
            if ($a['deadline'] === $b['deadline']) {
                return strcmp($a['title'], $b['title']);
            }
            if ($a['deadline'] === null) {
                return 1;
            }
            if ($b['deadline'] === null) {
                return -1;
            }
            return strcmp((string) $a['deadline'], (string) $b['deadline']);
        });
        return $items;
    }

    /**
     * 自分の成績: 配信ごとの状態、点数、合否、配信の日時、受講の日時、受講回数、回の一覧、最新の提出の正解の数、
     * 完了した配信の答え合わせ。アウェアネス(小問)の正答率の推移と、自分の受講完了率も返す。
     * @return array{deliveries: list<array<string,mixed>>, awareness_trend: list<array<string,mixed>>, summary: array<string,mixed>}
     */
    public static function grades(int $tenantId, int $targetId): array
    {
        $rows = Db::all(
            'SELECT a.id, a.tenant_id, a.status, a.score, a.started_at, a.completed_at, a.access_token, a.token_expiry,
                    d.id AS delivery_id, d.title, d.delivery_type, d.pass_score, d.deadline, d.feedback_mode,
                    d.status AS delivery_status, d.allow_retake_after_pass, d.allow_after_deadline,
                    COALESCE(d.scheduled_at, a.created_at) AS delivered_at
             FROM edu_assignments a
             INNER JOIN edu_deliveries d ON d.id = a.delivery_id AND d.tenant_id = a.tenant_id
             WHERE a.tenant_id = ? AND a.target_id = ? AND d.status NOT IN ' . self::HIDDEN_DELIVERY_STATUSES . '
             ORDER BY COALESCE(a.completed_at, a.started_at, a.created_at) DESC, a.id DESC',
            [$tenantId, $targetId]
        );
        $out = [];
        $trend = [];
        foreach ($rows as $r) {
            $attempts = EduAttempts::forAssignment((int) $r['id']);
            $submitted = array_values(array_filter($attempts, static fn(array $t): bool => $t['completed_at'] !== null));
            $latest = $submitted !== [] ? $submitted[count($submitted) - 1] : null;
            $retaking = EduAttempts::retakeOpen($r);
            $expired = EduAttempts::closedByDeadline($r);
            $status = (string) $r['status'];
            $passScore = $r['pass_score'] !== null ? (int) $r['pass_score'] : null;
            $retakeReason = $status === 'completed' ? EduAttempts::retakeBlockReason($r) : null;

            $item = [
                'delivery_id' => (int) $r['delivery_id'],
                'title' => (string) $r['title'],
                'delivery_type' => (string) $r['delivery_type'],
                'status' => $status,                         // assigned=未受講 / started=受講中 / completed=完了
                'retaking' => $retaking,                     // 完了の後に受け直している最中
                'score' => $r['score'] !== null ? (int) $r['score'] : null,
                'pass_score' => $passScore,
                'passed' => $latest !== null && $latest['passed'] !== null ? (int) $latest['passed'] === 1 : null,
                // 配信の日時: 予約の日時、なければ自分に割り当てられた日時(開始の日時)
                'delivered_at' => $r['delivered_at'],
                'started_at' => $r['started_at'],
                'completed_at' => $r['completed_at'],
                // 最新の提出の回の「正解 n/m」(設問の数で数える。得点は配点で計算するので別)
                'correct_count' => $latest !== null ? self::correctCount($latest)['correct'] : null,
                'question_count' => $latest !== null ? self::correctCount($latest)['total'] : null,
                'deadline' => self::deadlineOf($r['deadline'], $r['token_expiry']),
                'expired' => $expired,
                // 期限は過ぎたが、期限の後の受講を認める配信なので受講できる(完了は期限後として記録される)
                'past_deadline' => !$expired && (EduAttempts::isExpired($r['token_expiry']) || EduAttempts::isExpired($r['deadline'])),
                'attempt_count' => count($submitted),
                'attempts' => array_map(static fn(array $t): array => [
                    'attempt_no' => (int) $t['attempt_no'],
                    'is_retake' => (int) $t['is_retake'] === 1,
                    'started_at' => $t['started_at'],
                    'completed_at' => $t['completed_at'],
                    'percentage' => $t['percentage'] !== null ? (int) $t['percentage'] : null,
                    'passed' => $t['passed'] !== null ? (int) $t['passed'] === 1 : null,
                ], $attempts),
                // 受講の画面へのリンク(未受講・受講中・受け直し中で、期限内の時だけ)
                'take_url' => (!$expired && ($status !== 'completed' || $retaking))
                    ? 'take.php?token=' . rawurlencode((string) $r['access_token']) : null,
                'can_retake' => $status === 'completed' && !$retaking && $retakeReason === null,
                'retake_block_reason' => $status === 'completed' && !$retaking ? $retakeReason : null,
                'review' => null,
                'review_hidden' => false,
            ];
            // 完了した配信は、最新の提出の回の答え合わせ(edu_take.php の答え合わせと同じ内容)
            if ($status === 'completed' && $latest !== null) {
                if (in_array((string) $r['feedback_mode'], self::REVIEW_FEEDBACK_MODES, true)) {
                    $item['review'] = self::review((int) $r['delivery_id'], $latest);
                } else {
                    $item['review_hidden'] = true;
                }
            }
            if ((string) $r['delivery_type'] === 'awareness_quiz') {
                foreach ($submitted as $t) {
                    $trend[] = [
                        'seq' => (int) $t['id'],
                        'title' => (string) $r['title'],
                        'attempt_no' => (int) $t['attempt_no'],
                        'completed_at' => (string) $t['completed_at'],
                        'percentage' => $t['percentage'] !== null ? (int) $t['percentage'] : 0,
                    ];
                }
            }
            $out[] = $item;
        }
        // 提出の日時の順(同じ秒なら記録の順)
        usort($trend, static fn(array $a, array $b): int => [$a['completed_at'], $a['seq']] <=> [$b['completed_at'], $b['seq']]);
        $trend = array_map(static function (array $p): array {
            unset($p['seq']);
            return $p;
        }, $trend);
        $completed = count(array_filter($out, static fn(array $d): bool => $d['status'] === 'completed'));
        return ['deliveries' => $out, 'awareness_trend' => $trend, 'summary' => [
            'assigned' => count($out),
            'completed' => $completed,
            'completion_rate' => $out !== [] ? round($completed / count($out) * 100, 1) : null,
        ]];
    }

    /**
     * 受講の API が、受講を終えた画面にマイページへ戻るリンクを出してよいか。その対象者がマイページにログインできる
     * アカウント(LearnerAuth::targetFor と同じつながり方で、パスワードを設定済みの有効なアカウント)を持つ時だけ true。
     */
    public static function hasAccount(int $tenantId, int $targetId): bool
    {
        $roles = implode(',', array_fill(0, count(self::ACCOUNT_ADMIN_ROLES), '?'));
        return Db::one(
            "SELECT 1 FROM users u
             INNER JOIN targets t ON t.id = ? AND t.tenant_id = ? AND t.status = 'active'
             WHERE u.tenant_id = t.tenant_id AND u.status = 'active' AND u.password_pending = 0
               AND lower(u.email) = lower(t.email)
               AND ((u.role = 'learner' AND u.target_id = t.id) OR u.role IN ($roles))
             LIMIT 1",
            array_merge([$targetId, $tenantId], self::ACCOUNT_ADMIN_ROLES)
        ) !== null;
    }

    /** テナントの社内の問い合わせ先(管理画面のテナントの設定)。未設定は null。 */
    public static function contact(int $tenantId): ?string
    {
        $value = trim((string) (Db::one('SELECT edu_contact FROM tenants WHERE id = ?', [$tenantId])['edu_contact'] ?? ''));
        return $value !== '' ? $value : null;
    }

    /** @return array{correct:int,total:int} 提出した回の正解の数と設問の数 */
    private static function correctCount(array $attempt): array
    {
        $answers = json_decode((string) ($attempt['answers'] ?? ''), true) ?: [];
        $correct = count(array_filter($answers, static fn($a): bool => is_array($a) && ($a['is_correct'] ?? false) === true));
        return ['correct' => $correct, 'total' => count($answers)];
    }

    /**
     * もう一度受講する。完了した配信は受け直しの回を開き、未完了ならそのまま続ける。受講の画面の URL を返す。
     * @throws DomainException 受け直せない(理由の文)
     * @throws OutOfBoundsException 自分の割当にない配信
     */
    public static function retake(int $tenantId, int $targetId, int $deliveryId): string
    {
        $row = Db::one(
            'SELECT a.*, d.status AS delivery_status, d.deadline, d.allow_retake_after_pass, d.allow_after_deadline
             FROM edu_assignments a
             INNER JOIN edu_deliveries d ON d.id = a.delivery_id AND d.tenant_id = a.tenant_id
             WHERE a.tenant_id = ? AND a.target_id = ? AND a.delivery_id = ?
               AND d.status NOT IN ' . self::HIDDEN_DELIVERY_STATUSES,
            [$tenantId, $targetId, $deliveryId]
        );
        if ($row === null) {
            throw new OutOfBoundsException('配信が見つかりません');
        }
        if (EduAttempts::closedByDeadline($row)) {
            throw new DomainException(EduAttempts::MSG_EXPIRED);
        }
        if ((string) $row['status'] === 'completed') {
            EduAttempts::startRetake($row);
        }
        return 'take.php?token=' . rawurlencode((string) $row['access_token']);
    }

    /**
     * 自分のアンケート: 未回答(回答のリンク)と回答の履歴。
     * 匿名のアンケートは、本人にも回答の中身を出さない(回答と割当を結ばない作りのため、引くこともしない)。
     * @return array{open: list<array<string,mixed>>, history: list<array<string,mixed>>}
     */
    public static function surveys(int $tenantId, int $targetId): array
    {
        $open = array_map(static fn(array $r): array => [
            'title' => (string) $r['title'],
            'deadline' => $r['deadline'],
            'url' => 'survey.php?token=' . rawurlencode((string) $r['access_token']),
        ], self::openSurveyRows($tenantId, $targetId));

        $rows = Db::all(
            "SELECT a.id, a.answered_at, d.title, d.survey_id, s.is_anonymous
             FROM survey_assignments a
             INNER JOIN survey_deliveries d ON d.id = a.delivery_id AND d.tenant_id = a.tenant_id
             INNER JOIN surveys s ON s.id = d.survey_id AND s.tenant_id = a.tenant_id
             WHERE a.tenant_id = ? AND a.target_id = ? AND a.status = 'answered'
             ORDER BY a.answered_at DESC, a.id DESC",
            [$tenantId, $targetId]
        );
        $history = [];
        foreach ($rows as $r) {
            $anonymous = (int) $r['is_anonymous'] === 1;
            $history[] = [
                'title' => (string) $r['title'],
                'answered_at' => (string) $r['answered_at'],   // 匿名は日付だけ(保存の時に丸めてある)
                'is_anonymous' => $anonymous,
                'answers' => $anonymous ? null : self::surveyAnswers((int) $r['id'], (int) $r['survey_id']),
            ];
        }
        return ['open' => $open, 'history' => $history];
    }

    // ---------------------------------------------------------------- 内部

    /** @return list<array<string,mixed>> 回答できる(未回答、受付中、期限内の)アンケートの割当 */
    private static function openSurveyRows(int $tenantId, int $targetId): array
    {
        $rows = Db::all(
            "SELECT a.access_token, d.title, d.deadline
             FROM survey_assignments a
             INNER JOIN survey_deliveries d ON d.id = a.delivery_id AND d.tenant_id = a.tenant_id
             WHERE a.tenant_id = ? AND a.target_id = ? AND a.status = 'assigned' AND d.status = 'open'",
            [$tenantId, $targetId]
        );
        return array_values(array_filter($rows, static fn(array $r): bool => !EduAttempts::isExpired($r['deadline'])));
    }

    /** 期限の表示: 配信の締切、なければ受講のリンクの期限。 */
    private static function deadlineOf(?string $deadline, ?string $tokenExpiry): ?string
    {
        foreach ([$deadline, $tokenExpiry] as $v) {
            if ($v !== null && trim($v) !== '') {
                $v = trim($v);
                return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1 ? $v . ' 23:59:59' : $v;
            }
        }
        return null;
    }

    /**
     * 回の答え合わせ: 問題ごとの自分の解答、正解、正誤、解説、選択肢ごとの解説(配信の設問の順)。
     * @return list<array<string,mixed>>
     */
    private static function review(int $deliveryId, array $attempt): array
    {
        $answers = [];
        foreach (json_decode((string) $attempt['answers'], true) ?: [] as $a) {
            $answers[(int) $a['question_id']] = array_map('intval', (array) ($a['answer'] ?? []));
        }
        $questions = Db::all(
            'SELECT q.id, q.title, q.options, q.correct_answer, q.explanation, q.option_explanations
             FROM edu_delivery_questions dq
             INNER JOIN edu_questions q ON q.id = dq.question_id
             WHERE dq.delivery_id = ?
             ORDER BY dq.sort_order, dq.id',
            [$deliveryId]
        );
        $out = [];
        foreach ($questions as $q) {
            $mine = $answers[(int) $q['id']] ?? [];
            $correct = array_map('intval', json_decode((string) $q['correct_answer'], true) ?: []);
            $a = $mine;
            $b = $correct;
            sort($a);
            sort($b);
            $optionExplanations = json_decode((string) ($q['option_explanations'] ?? ''), true);
            $out[] = [
                'title' => (string) $q['title'],
                'options' => json_decode((string) $q['options'], true) ?: [],
                'your_answer' => $mine,
                'correct_answer' => $correct,
                'is_correct' => $a === $b,
                'explanation' => $q['explanation'],
                'option_explanations' => is_array($optionExplanations) && $optionExplanations !== []
                    ? array_values(array_map('strval', $optionExplanations)) : null,
            ];
        }
        return $out;
    }

    /** @return list<array{question:string, answer:string}> 記名のアンケートの自分の回答 */
    private static function surveyAnswers(int $assignmentId, int $surveyId): array
    {
        $rows = Db::all(
            'SELECT q.title, q.question_type, q.options, sa.value
             FROM survey_responses r
             INNER JOIN survey_answers sa ON sa.response_id = r.id
             INNER JOIN survey_questions q ON q.id = sa.question_id AND q.survey_id = ?
             WHERE r.assignment_id = ?
             ORDER BY q.sort_order, q.id',
            [$surveyId, $assignmentId]
        );
        $out = [];
        foreach ($rows as $r) {
            $value = json_decode((string) $r['value'], true);
            $options = json_decode((string) $r['options'], true) ?: [];
            if ((string) $r['question_type'] === 'text') {
                $text = is_string($value) ? $value : '';
            } else {
                $picked = [];
                foreach ((array) $value as $i) {
                    if (is_int($i) && isset($options[$i])) {
                        $picked[] = (string) $options[$i];
                    }
                }
                $text = implode('、', $picked);
            }
            $out[] = ['question' => (string) $r['title'], 'answer' => $text];
        }
        return $out;
    }
}
