<?php
/**
 * アンケート(U7)の業務処理。API(surveys.php / survey_take.php)と CLI から使う。
 * 設計: docs/spec/07-seculio-benchmark-update-design.md 5.7。
 *
 * 約束事:
 * - 配信を1件でも作ったアンケートは設問を編集できない(集計に異なる設問の回答が混ざるのを防ぐ)。
 * - 匿名アンケートは回答と割当を結ばない。回答日時は日付までに丸める。
 * - 1つの割当で回答できるのは1回だけ(割当の状態を条件付き UPDATE で遷移させて二重提出を防ぐ)。
 * - 表示条件で隠れた設問は、必須でも回答を求めず、送られてきても保存しない。
 * - すべての参照・更新は tenant_id で絞る。
 */
declare(strict_types=1);

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/TenantStatus.php';

final class SurveyException extends RuntimeException
{
    public function __construct(string $message, public readonly int $httpCode = 400)
    {
        parent::__construct($message);
    }
}

final class SurveyService
{
    public const QUESTION_TYPES = ['single', 'multiple', 'text'];
    private const MAX_QUESTIONS = 50;
    private const MAX_OPTIONS = 20;
    private const MAX_TITLE = 500;
    private const MAX_OPTION = 200;
    private const MAX_TEXT_ANSWER = 2000;

    // ---------------------------------------------------------------- アンケート

    /** @return list<array<string,mixed>> */
    public static function listSurveys(int $tenantId): array
    {
        return Db::all(
            "SELECT s.id, s.title, s.status, s.is_anonymous, s.created_at, s.updated_at,
                    (SELECT COUNT(*) FROM survey_questions q WHERE q.survey_id = s.id) AS question_count,
                    (SELECT COUNT(*) FROM survey_deliveries d WHERE d.survey_id = s.id) AS delivery_count
             FROM surveys s WHERE s.tenant_id = ? ORDER BY s.id DESC",
            [$tenantId]
        );
    }

    /** @return array<string,mixed> アンケートと設問 */
    public static function getSurvey(int $tenantId, int $surveyId): array
    {
        $survey = self::assertSurvey($tenantId, $surveyId);
        $survey['questions'] = self::questions($surveyId);
        return $survey;
    }

    /** @param array<string,mixed> $data */
    public static function createSurvey(int $tenantId, ?int $userId, array $data): int
    {
        [$title, $description, $anonymous, $questions] = self::validateSurvey($data);
        return Db::tx(function () use ($tenantId, $userId, $title, $description, $anonymous, $questions): int {
            $id = Db::insert(
                'INSERT INTO surveys (tenant_id, title, description, is_anonymous, created_by) VALUES (?, ?, ?, ?, ?)',
                [$tenantId, $title, $description, $anonymous, $userId]
            );
            self::insertQuestions($id, $questions);
            return $id;
        });
    }

    /** @param array<string,mixed> $data */
    public static function updateSurvey(int $tenantId, int $surveyId, array $data): void
    {
        $survey = self::assertSurvey($tenantId, $surveyId);
        self::assertEditable($survey);
        [$title, $description, $anonymous, $questions] = self::validateSurvey($data);
        Db::tx(function () use ($surveyId, $title, $description, $anonymous, $questions): void {
            // 配信作成との競合: 下書きのままであることを更新条件に含める。
            $n = Db::run(
                "UPDATE surveys SET title = ?, description = ?, is_anonymous = ?, updated_at = datetime('now','localtime')
                 WHERE id = ? AND status = 'draft'",
                [$title, $description, $anonymous, $surveyId]
            );
            if ($n !== 1) {
                throw new SurveyException('配信済みのアンケートは編集できません。複製して新しいアンケートを作ってください', 409);
            }
            Db::run('DELETE FROM survey_questions WHERE survey_id = ?', [$surveyId]);
            self::insertQuestions($surveyId, $questions);
        });
    }

    public static function duplicateSurvey(int $tenantId, int $surveyId, ?int $userId): int
    {
        $survey = self::getSurvey($tenantId, $surveyId);
        $questions = array_map(static fn(array $q): array => [
            'section' => $q['section'],
            'question_type' => $q['question_type'],
            'title' => $q['title'],
            'options' => $q['options'],
            'is_required' => $q['is_required'],
            'show_if' => $q['show_if'],
        ], $survey['questions']);
        return self::createSurvey($tenantId, $userId, [
            'title' => mb_substr((string) $survey['title'] . '（複製）', 0, self::MAX_TITLE),
            'description' => $survey['description'],
            'is_anonymous' => (bool) $survey['is_anonymous'],
            'questions' => $questions,
        ]);
    }

    public static function deleteSurvey(int $tenantId, int $surveyId): void
    {
        $survey = self::assertSurvey($tenantId, $surveyId);
        self::assertEditable($survey);
        $n = Db::run("DELETE FROM surveys WHERE id = ? AND tenant_id = ? AND status = 'draft'", [$surveyId, $tenantId]);
        if ($n !== 1) {
            throw new SurveyException('配信済みのアンケートは削除できません', 409);
        }
    }

    // ---------------------------------------------------------------- 配信

    /**
     * 配信を作り、対象者を固定してトークンを発行する。メールは送らない。
     * @param list<int> $groupIds
     * @return array{delivery_id:int, assigned:int}
     */
    public static function createDelivery(int $tenantId, int $surveyId, ?int $userId, string $title, ?string $deadline, array $groupIds): array
    {
        $survey = self::assertSurvey($tenantId, $surveyId);
        if ((string) $survey['status'] === 'closed') {
            throw new SurveyException('終了したアンケートは配信できません', 409);
        }
        if (self::questions($surveyId) === []) {
            throw new SurveyException('設問がないアンケートは配信できません', 400);
        }
        $title = trim($title);
        if ($title === '' || mb_strlen($title) > self::MAX_TITLE) {
            throw new SurveyException('配信名を1〜500文字で入力してください', 400);
        }
        $deadline = self::normalizeDeadline($deadline);
        $groupIds = array_values(array_unique(array_map('intval', $groupIds)));
        if ($groupIds === []) {
            throw new SurveyException('配信先のグループを1つ以上選んでください', 400);
        }
        $targetIds = self::targetsForGroups($tenantId, $groupIds);
        if ($targetIds === []) {
            throw new SurveyException('選んだグループに有効な対象者がいません', 400);
        }

        return Db::txImmediate(function () use ($tenantId, $surveyId, $userId, $title, $deadline, $groupIds, $targetIds): array {
            $deliveryId = Db::insert(
                'INSERT INTO survey_deliveries (tenant_id, survey_id, title, deadline, group_ids, created_by) VALUES (?, ?, ?, ?, ?, ?)',
                [$tenantId, $surveyId, $title, $deadline, json_encode($groupIds), $userId]
            );
            foreach ($targetIds as $targetId) {
                Db::run(
                    'INSERT INTO survey_assignments (tenant_id, delivery_id, target_id, access_token) VALUES (?, ?, ?, ?)',
                    [$tenantId, $deliveryId, $targetId, bin2hex(random_bytes(16))]
                );
            }
            // 配信を作った時点で設問を固定する。
            Db::run("UPDATE surveys SET status = 'published', updated_at = datetime('now','localtime') WHERE id = ? AND status = 'draft'", [$surveyId]);
            return ['delivery_id' => $deliveryId, 'assigned' => count($targetIds)];
        });
    }

    /** @return list<array<string,mixed>> */
    public static function listDeliveries(int $tenantId, ?int $surveyId = null): array
    {
        $sql = "SELECT d.id, d.survey_id, d.title, d.status, d.deadline, d.invited_at, d.created_at, d.closed_at,
                       s.title AS survey_title, s.is_anonymous,
                       (SELECT COUNT(*) FROM survey_assignments a WHERE a.delivery_id = d.id) AS assigned,
                       (SELECT COUNT(*) FROM survey_assignments a WHERE a.delivery_id = d.id AND a.status = 'answered') AS answered
                FROM survey_deliveries d INNER JOIN surveys s ON s.id = d.survey_id
                WHERE d.tenant_id = ?";
        $params = [$tenantId];
        if ($surveyId !== null) {
            $sql .= ' AND d.survey_id = ?';
            $params[] = $surveyId;
        }
        return Db::all($sql . ' ORDER BY d.id DESC', $params);
    }

    public static function closeDelivery(int $tenantId, int $deliveryId): void
    {
        self::assertDelivery($tenantId, $deliveryId);
        Db::run(
            "UPDATE survey_deliveries SET status = 'closed', closed_at = datetime('now','localtime') WHERE id = ? AND tenant_id = ? AND status = 'open'",
            [$deliveryId, $tenantId]
        );
    }

    /**
     * 受講用 URL の配布一覧(担当者が社内メールで配るため)。
     * @return list<array{email:string,name:string,department:string,status:string,token:string}>
     */
    public static function tokenRows(int $tenantId, int $deliveryId): array
    {
        self::assertDelivery($tenantId, $deliveryId);
        return Db::all(
            "SELECT t.email, COALESCE(t.name, '') AS name, COALESCE(t.department, '') AS department, a.status, a.access_token AS token
             FROM survey_assignments a INNER JOIN targets t ON t.id = a.target_id
             WHERE a.delivery_id = ? AND a.tenant_id = ? ORDER BY t.department, t.name, t.email",
            [$deliveryId, $tenantId]
        );
    }

    // ---------------------------------------------------------------- 回答

    /**
     * トークンから回答画面の内容を返す。回答済み・期限切れ・終了は例外。
     * @return array<string,mixed>
     */
    public static function startByToken(string $token): array
    {
        $a = self::resolveToken($token);
        $questions = array_map(static fn(array $q): array => [
            'id' => $q['id'],
            'section' => $q['section'],
            'question_type' => $q['question_type'],
            'title' => $q['title'],
            'options' => $q['options'],
            'is_required' => $q['is_required'],
            'show_if' => $q['show_if'],
        ], self::questions((int) $a['survey_id']));
        return [
            'survey' => [
                'title' => $a['survey_title'],
                'description' => $a['survey_description'],
                'is_anonymous' => (bool) $a['is_anonymous'],
                'deadline' => $a['deadline'],
            ],
            'questions' => $questions,
        ];
    }

    /**
     * 回答を保存する。
     * @param array<int|string,mixed> $answers question_id => 値(single は int、multiple は int[]、text は string)
     */
    public static function submitByToken(string $token, array $answers): void
    {
        $a = self::resolveToken($token);
        $questions = self::questions((int) $a['survey_id']);
        $normalized = self::validateAnswers($questions, $answers);
        $anonymous = (int) $a['is_anonymous'] === 1;
        $isTest = (int) (Db::one('SELECT is_test FROM targets WHERE id = ?', [(int) $a['target_id']])['is_test'] ?? 0);

        Db::txImmediate(function () use ($a, $normalized, $anonymous, $isTest): void {
            $answeredAt = $anonymous ? date('Y-m-d') : date('Y-m-d H:i:s');
            $n = Db::run(
                "UPDATE survey_assignments SET status = 'answered', answered_at = ? WHERE id = ? AND status = 'assigned'",
                [$answeredAt, (int) $a['id']]
            );
            if ($n !== 1) {
                throw new SurveyException('このアンケートは回答済みです', 409);
            }
            $responseId = Db::insert(
                'INSERT INTO survey_responses (tenant_id, delivery_id, assignment_id, submitted_at, is_test) VALUES (?, ?, ?, ?, ?)',
                [(int) $a['tenant_id'], (int) $a['delivery_id'], $anonymous ? null : (int) $a['id'], $answeredAt, $isTest]
            );
            foreach ($normalized as $questionId => $value) {
                Db::run(
                    'INSERT INTO survey_answers (response_id, question_id, value) VALUES (?, ?, ?)',
                    [$responseId, $questionId, json_encode($value, JSON_UNESCAPED_UNICODE)]
                );
            }
        });
    }

    // ---------------------------------------------------------------- 集計と出力

    /**
     * 配信の集計。テスト用対象者は母数と回答から除く。
     * @return array<string,mixed>
     */
    public static function results(int $tenantId, int $deliveryId): array
    {
        $delivery = self::assertDelivery($tenantId, $deliveryId);
        $counts = Db::one(
            "SELECT COUNT(*) AS assigned, SUM(CASE WHEN a.status = 'answered' THEN 1 ELSE 0 END) AS answered
             FROM survey_assignments a INNER JOIN targets t ON t.id = a.target_id
             WHERE a.delivery_id = ? AND a.tenant_id = ? AND t.is_test = 0",
            [$deliveryId, $tenantId]
        );
        $assigned = (int) ($counts['assigned'] ?? 0);
        $answered = (int) ($counts['answered'] ?? 0);

        $byDepartment = array_map(static function (array $r): array {
            $n = (int) $r['assigned'];
            return [
                'department' => $r['department'],
                'assigned' => $n,
                'answered' => (int) $r['answered'],
                'rate' => $n > 0 ? round((int) $r['answered'] / $n * 100, 1) : 0.0,
            ];
        }, Db::all(
            "SELECT COALESCE(NULLIF(t.department, ''), '（部署未設定）') AS department,
                    COUNT(*) AS assigned, SUM(CASE WHEN a.status = 'answered' THEN 1 ELSE 0 END) AS answered
             FROM survey_assignments a INNER JOIN targets t ON t.id = a.target_id
             WHERE a.delivery_id = ? AND a.tenant_id = ? AND t.is_test = 0
             GROUP BY 1 ORDER BY 1",
            [$deliveryId, $tenantId]
        ));

        $questions = self::questions((int) $delivery['survey_id']);
        $answerRows = Db::all(
            'SELECT ans.question_id, ans.value FROM survey_answers ans
             INNER JOIN survey_responses r ON r.id = ans.response_id
             WHERE r.delivery_id = ? AND r.tenant_id = ? AND r.is_test = 0',
            [$deliveryId, $tenantId]
        );
        $byQuestion = [];
        foreach ($questions as $q) {
            $byQuestion[$q['id']] = [
                'id' => $q['id'], 'title' => $q['title'], 'question_type' => $q['question_type'],
                'options' => $q['options'], 'counts' => array_fill(0, count($q['options']), 0),
                'answered' => 0, 'texts' => [],
            ];
        }
        foreach ($answerRows as $row) {
            $qid = (int) $row['question_id'];
            if (!isset($byQuestion[$qid])) {
                continue;
            }
            $value = json_decode((string) $row['value'], true);
            $byQuestion[$qid]['answered']++;
            if ($byQuestion[$qid]['question_type'] === 'text') {
                $byQuestion[$qid]['texts'][] = (string) $value;
                continue;
            }
            foreach ((array) $value as $index) {
                if (is_int($index) && isset($byQuestion[$qid]['counts'][$index])) {
                    $byQuestion[$qid]['counts'][$index]++;
                }
            }
        }

        return [
            'delivery' => [
                'id' => (int) $delivery['id'], 'title' => $delivery['title'], 'status' => $delivery['status'],
                'deadline' => $delivery['deadline'], 'survey_title' => $delivery['survey_title'],
                'is_anonymous' => (bool) $delivery['is_anonymous'],
            ],
            'assigned' => $assigned,
            'answered' => $answered,
            'rate' => $assigned > 0 ? round($answered / $assigned * 100, 1) : 0.0,
            'by_department' => $byDepartment,
            'questions' => array_values($byQuestion),
        ];
    }

    /**
     * 回答の出力行(CSV / Excel 共通)。匿名は回答者の列を出さない。テスト用対象者の回答は除く。
     * @return array{header:list<string>, rows:list<list<string>>}
     */
    public static function exportRows(int $tenantId, int $deliveryId): array
    {
        $delivery = self::assertDelivery($tenantId, $deliveryId);
        $anonymous = (int) $delivery['is_anonymous'] === 1;
        $questions = self::questions((int) $delivery['survey_id']);
        $header = $anonymous ? ['回答日'] : ['回答日時', '氏名', 'メールアドレス', '部署'];
        foreach ($questions as $i => $q) {
            $header[] = 'Q' . ($i + 1) . ' ' . $q['title'];
        }

        $responses = $anonymous
            ? Db::all('SELECT r.id, r.submitted_at FROM survey_responses r WHERE r.delivery_id = ? AND r.tenant_id = ? AND r.is_test = 0 ORDER BY r.submitted_at, r.id', [$deliveryId, $tenantId])
            : Db::all(
                "SELECT r.id, r.submitted_at, COALESCE(t.name, '') AS name, t.email, COALESCE(t.department, '') AS department
                 FROM survey_responses r
                 INNER JOIN survey_assignments a ON a.id = r.assignment_id
                 INNER JOIN targets t ON t.id = a.target_id
                 WHERE r.delivery_id = ? AND r.tenant_id = ? AND r.is_test = 0 ORDER BY r.submitted_at, r.id",
                [$deliveryId, $tenantId]
            );
        $rows = [];
        foreach ($responses as $r) {
            $values = [];
            foreach (Db::all('SELECT question_id, value FROM survey_answers WHERE response_id = ?', [(int) $r['id']]) as $ans) {
                $values[(int) $ans['question_id']] = json_decode((string) $ans['value'], true);
            }
            $row = $anonymous ? [(string) $r['submitted_at']] : [(string) $r['submitted_at'], (string) $r['name'], (string) $r['email'], (string) $r['department']];
            foreach ($questions as $q) {
                $row[] = self::formatAnswer($q, $values[$q['id']] ?? null);
            }
            $rows[] = $row;
        }
        return ['header' => $header, 'rows' => $rows];
    }

    // ---------------------------------------------------------------- 内部

    /** @return array<string,mixed> */
    private static function assertSurvey(int $tenantId, int $surveyId): array
    {
        $survey = Db::one('SELECT * FROM surveys WHERE id = ? AND tenant_id = ?', [$surveyId, $tenantId]);
        if ($survey === null) {
            throw new SurveyException('アンケートが見つかりません', 404);
        }
        return $survey;
    }

    /** @return array<string,mixed> */
    private static function assertDelivery(int $tenantId, int $deliveryId): array
    {
        $delivery = Db::one(
            'SELECT d.*, s.title AS survey_title, s.is_anonymous FROM survey_deliveries d
             INNER JOIN surveys s ON s.id = d.survey_id WHERE d.id = ? AND d.tenant_id = ?',
            [$deliveryId, $tenantId]
        );
        if ($delivery === null) {
            throw new SurveyException('配信が見つかりません', 404);
        }
        return $delivery;
    }

    /** @param array<string,mixed> $survey */
    private static function assertEditable(array $survey): void
    {
        if ((string) $survey['status'] !== 'draft') {
            throw new SurveyException('配信済みのアンケートは編集できません。複製して新しいアンケートを作ってください', 409);
        }
    }

    /** @return array<string,mixed> */
    private static function resolveToken(string $token): array
    {
        if (!preg_match('/^[0-9a-f]{32}$/', $token)) {
            throw new SurveyException('リンクが正しくありません', 400);
        }
        $a = Db::one(
            'SELECT a.*, d.survey_id, d.status AS delivery_status, d.deadline,
                    s.title AS survey_title, s.description AS survey_description, s.is_anonymous
             FROM survey_assignments a
             INNER JOIN survey_deliveries d ON d.id = a.delivery_id
             INNER JOIN surveys s ON s.id = d.survey_id AND s.tenant_id = a.tenant_id
             WHERE a.access_token = ?',
            [$token]
        );
        if ($a === null) {
            throw new SurveyException('リンクが正しくありません', 404);
        }
        // 停止中・削除済みのテナントの回答は受け付けない
        if (!TenantStatus::isOperational((int) $a['tenant_id'])) {
            throw new SurveyException(TenantStatus::PARTICIPANT_BLOCKED_MESSAGE, 403);
        }
        if ((string) $a['status'] === 'answered') {
            throw new SurveyException('このアンケートは回答済みです', 409);
        }
        if ((string) $a['delivery_status'] !== 'open' || self::isPast($a['deadline'] ?? null)) {
            throw new SurveyException('このアンケートの回答期間は終了しました', 410);
        }
        return $a;
    }

    private static function isPast(?string $deadline, ?int $now = null): bool
    {
        if ($deadline === null || trim($deadline) === '') {
            return false;
        }
        $ts = strtotime($deadline);
        return $ts !== false && $ts < ($now ?? time());
    }

    private static function normalizeDeadline(?string $deadline): ?string
    {
        if ($deadline === null || trim($deadline) === '') {
            return null;
        }
        $ts = strtotime($deadline);
        if ($ts === false) {
            throw new SurveyException('締切日時の形式が正しくありません', 400);
        }
        if ($ts < time()) {
            throw new SurveyException('締切日時が過去です', 400);
        }
        return date('Y-m-d H:i:s', $ts);
    }

    /**
     * グループに属する有効な対象者。kind='all' のグループはテナントの全有効対象者。
     * @param list<int> $groupIds
     * @return list<int>
     */
    private static function targetsForGroups(int $tenantId, array $groupIds): array
    {
        $placeholders = implode(',', array_fill(0, count($groupIds), '?'));
        $groups = Db::all(
            "SELECT id, kind FROM groups WHERE tenant_id = ? AND status = 'active' AND id IN ({$placeholders})",
            array_merge([$tenantId], $groupIds)
        );
        if (count($groups) !== count($groupIds)) {
            throw new SurveyException('選んだグループが見つかりません', 404);
        }
        if (in_array('all', array_column($groups, 'kind'), true)) {
            $rows = Db::all("SELECT id FROM targets WHERE tenant_id = ? AND status = 'active' ORDER BY id", [$tenantId]);
        } else {
            $rows = Db::all(
                "SELECT DISTINCT t.id FROM targets t INNER JOIN target_group tg ON tg.target_id = t.id
                 WHERE t.tenant_id = ? AND t.status = 'active' AND tg.group_id IN ({$placeholders}) ORDER BY t.id",
                array_merge([$tenantId], $groupIds)
            );
        }
        return array_map(static fn(array $r): int => (int) $r['id'], $rows);
    }

    /** @return list<array<string,mixed>> */
    private static function questions(int $surveyId): array
    {
        return array_map(static fn(array $q): array => [
            'id' => (int) $q['id'],
            'section' => $q['section'],
            'sort_order' => (int) $q['sort_order'],
            'question_type' => (string) $q['question_type'],
            'title' => (string) $q['title'],
            'options' => json_decode((string) $q['options'], true) ?: [],
            'is_required' => (int) $q['is_required'] === 1,
            'show_if' => $q['show_if'] !== null ? json_decode((string) $q['show_if'], true) : null,
        ], Db::all('SELECT * FROM survey_questions WHERE survey_id = ? ORDER BY sort_order, id', [$surveyId]));
    }

    /**
     * @param array<string,mixed> $data
     * @return array{0:string,1:?string,2:int,3:list<array<string,mixed>>}
     */
    private static function validateSurvey(array $data): array
    {
        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '' || mb_strlen($title) > self::MAX_TITLE) {
            throw new SurveyException('題名を1〜500文字で入力してください', 400);
        }
        $description = isset($data['description']) ? trim((string) $data['description']) : null;
        if ($description !== null && mb_strlen($description) > 5000) {
            throw new SurveyException('説明は5000文字以内で入力してください', 400);
        }
        $anonymous = !empty($data['is_anonymous']) ? 1 : 0;
        $questions = $data['questions'] ?? [];
        if (!is_array($questions) || count($questions) > self::MAX_QUESTIONS) {
            throw new SurveyException('設問は50問以内で指定してください', 400);
        }
        $out = [];
        foreach (array_values($questions) as $i => $q) {
            $out[] = self::validateQuestion($q, $i, $out);
        }
        return [$title, $description === '' ? null : $description, $anonymous, $out];
    }

    /**
     * @param list<array<string,mixed>> $previous それまでに検証済みの設問
     * @return array<string,mixed>
     */
    private static function validateQuestion(mixed $q, int $index, array $previous): array
    {
        $no = $index + 1;
        if (!is_array($q)) {
            throw new SurveyException("設問{$no}の形式が正しくありません", 400);
        }
        $type = (string) ($q['question_type'] ?? '');
        if (!in_array($type, self::QUESTION_TYPES, true)) {
            throw new SurveyException("設問{$no}の形式は single、multiple、text のいずれかです", 400);
        }
        $title = trim((string) ($q['title'] ?? ''));
        if ($title === '' || mb_strlen($title) > self::MAX_TITLE) {
            throw new SurveyException("設問{$no}の文を1〜500文字で入力してください", 400);
        }
        $options = [];
        if ($type !== 'text') {
            $raw = $q['options'] ?? [];
            if (!is_array($raw)) {
                throw new SurveyException("設問{$no}の選択肢が正しくありません", 400);
            }
            foreach ($raw as $opt) {
                $opt = trim((string) $opt);
                if ($opt === '' || mb_strlen($opt) > self::MAX_OPTION) {
                    throw new SurveyException("設問{$no}の選択肢は1〜200文字で入力してください", 400);
                }
                $options[] = $opt;
            }
            if (count($options) < 2 || count($options) > self::MAX_OPTIONS) {
                throw new SurveyException("設問{$no}の選択肢は2〜20個にしてください", 400);
            }
        }
        $showIf = null;
        if (isset($q['show_if']) && $q['show_if'] !== null) {
            $cond = $q['show_if'];
            $qi = is_array($cond) ? ($cond['question_index'] ?? null) : null;
            $oi = is_array($cond) ? ($cond['option'] ?? null) : null;
            if (!is_int($qi) || !is_int($oi) || $qi < 0 || $qi >= $index
                || $previous[$qi]['question_type'] === 'text'
                || $oi < 0 || $oi >= count($previous[$qi]['options'])) {
                throw new SurveyException("設問{$no}の表示条件は、前にある選択式の設問と、その選択肢を指定してください", 400);
            }
            $showIf = ['question_index' => $qi, 'option' => $oi];
        }
        $section = isset($q['section']) ? trim((string) $q['section']) : '';
        return [
            'section' => $section === '' ? null : mb_substr($section, 0, 200),
            'question_type' => $type,
            'title' => $title,
            'options' => $options,
            'is_required' => !empty($q['is_required']),
            'show_if' => $showIf,
        ];
    }

    /** @param list<array<string,mixed>> $questions */
    private static function insertQuestions(int $surveyId, array $questions): void
    {
        foreach ($questions as $i => $q) {
            Db::run(
                'INSERT INTO survey_questions (survey_id, section, sort_order, question_type, title, options, is_required, show_if)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $surveyId, $q['section'], $i, $q['question_type'], $q['title'],
                    json_encode($q['options'], JSON_UNESCAPED_UNICODE), $q['is_required'] ? 1 : 0,
                    $q['show_if'] !== null ? json_encode($q['show_if']) : null,
                ]
            );
        }
    }

    /**
     * 回答を検証して正規化する。隠れた設問の回答は捨てる。
     * @param list<array<string,mixed>> $questions
     * @param array<int|string,mixed> $answers
     * @return array<int,mixed> question_id => 正規化した値
     */
    public static function validateAnswers(array $questions, array $answers): array
    {
        $out = [];
        $chosen = []; // 設問 index => 選んだ選択肢の index 配列(表示条件の判定用)
        foreach ($questions as $i => $q) {
            $no = $i + 1;
            $cond = $q['show_if'];
            $visible = $cond === null || in_array($cond['option'], $chosen[$cond['question_index']] ?? [], true);
            $raw = $answers[$q['id']] ?? $answers[(string) $q['id']] ?? null;
            if (!$visible) {
                $chosen[$i] = [];
                continue;
            }
            $empty = $raw === null || $raw === '' || $raw === [];
            if ($empty) {
                if ($q['is_required']) {
                    throw new SurveyException("設問{$no}は回答が必要です", 400);
                }
                $chosen[$i] = [];
                continue;
            }
            if ($q['question_type'] === 'text') {
                if (!is_string($raw)) {
                    throw new SurveyException("設問{$no}の回答が正しくありません", 400);
                }
                $text = trim($raw);
                if (mb_strlen($text) > self::MAX_TEXT_ANSWER) {
                    throw new SurveyException("設問{$no}は2000文字以内で入力してください", 400);
                }
                if ($text === '') {
                    if ($q['is_required']) {
                        throw new SurveyException("設問{$no}は回答が必要です", 400);
                    }
                    continue;
                }
                $out[$q['id']] = $text;
                continue;
            }
            $values = is_array($raw) ? $raw : [$raw];
            $indexes = [];
            foreach ($values as $v) {
                if (!is_int($v) || $v < 0 || $v >= count($q['options'])) {
                    throw new SurveyException("設問{$no}の選択肢が正しくありません", 400);
                }
                $indexes[] = $v;
            }
            $indexes = array_values(array_unique($indexes));
            sort($indexes);
            if ($q['question_type'] === 'single' && count($indexes) !== 1) {
                throw new SurveyException("設問{$no}は選択肢を1つ選んでください", 400);
            }
            $chosen[$i] = $indexes;
            $out[$q['id']] = $indexes;
        }
        return $out;
    }

    /** @param array<string,mixed> $q */
    private static function formatAnswer(array $q, mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if ($q['question_type'] === 'text') {
            return (string) $value;
        }
        $labels = [];
        foreach ((array) $value as $index) {
            if (is_int($index) && isset($q['options'][$index])) {
                $labels[] = $q['options'][$index];
            }
        }
        return implode(' / ', $labels);
    }
}
