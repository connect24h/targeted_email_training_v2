<?php declare(strict_types=1);
/**
 * 受講者向け 公開 API(認証不要・access_token 方式)。
 * 既存の tracking_id 方式に倣い、ログイン不要でトークンだけで受講する。
 *
 *   GET  edu_take.php?action=start&token=...   設問配信(correct_answer は返さない=秘匿)
 *   POST edu_take.php?action=submit            回答受領→採点→保存→即時結果(解説つき)
 *
 * 採点は SecurityAwareness/lib/scoring.ts の移植:
 *   - 難易度(difficulty) = その設問の配点(maxScore)
 *   - 選択肢indexの集合が完全一致したときのみ正答(順不同・複数選択対応)
 *   - percentage = round(totalScore / maxScore * 100)
 *
 * セキュリティ:
 *   - 管理系の bootstrap 認証は通さないが、DB接続とセキュリティヘッダは共有する。
 *   - token は edu_assignments.access_token(32桁hex)。期限切れ・二重受講を拒否。
 *   - IDOR: token から tenant/delivery/target を確定するため、URL に id を露出しない。
 */

require_once __DIR__ . '/../lib/Db.php';

// ---- 最小レスポンスヘルパ(bootstrap を使わないため自前) ----
function take_json($data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function take_error(string $message, int $code = 400): never
{
    take_json(['success' => false, 'error' => $message], $code);
}

function take_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

/** token 文字列を取り出して検証(32桁hex)。 */
function take_token(string $source): string
{
    $token = '';
    if ($source === 'get') {
        $token = isset($_GET['token']) && is_string($_GET['token']) ? trim($_GET['token']) : '';
    } else {
        $body = take_body();
        $token = isset($body['token']) && is_string($body['token']) ? trim($body['token']) : '';
    }
    if ($token === '' || !preg_match('/^[0-9a-f]{32}$/', $token)) {
        take_error('トークンが不正です', 400);
    }
    return $token;
}

/** token から assignment(+delivery)を解決。無効・期限切れは拒否。 */
function take_resolve(string $token): array
{
    $a = Db::one(
        'SELECT a.*, d.title AS delivery_title, d.delivery_type, d.pass_score, d.status AS delivery_status
         FROM edu_assignments a
         INNER JOIN edu_deliveries d ON d.id = a.delivery_id
         WHERE a.access_token = ?',
        [$token]
    );
    if ($a === null) {
        take_error('無効なトークンです', 404);
    }
    if ($a['token_expiry'] !== null && $a['token_expiry'] !== '') {
        // 期限は 'YYYY-MM-DD HH:MM:SS'(localtime)。現在時刻と比較。
        if (strtotime((string) $a['token_expiry']) !== false && strtotime((string) $a['token_expiry']) < time()) {
            take_error('この受講リンクは有効期限が切れています', 410);
        }
    }
    return $a;
}

/**
 * 採点(scoring.ts 移植)。
 * @param array $answers [['question_id'=>int, 'answer'=>int[]], ...]
 * @param array $questionMap question_id => ['correct'=>int[], 'difficulty'=>int, ...]
 */
function take_score(array $answers, array $questionMap): array
{
    $scored = [];
    $totalScore = 0;
    $maxScore = 0;

    foreach ($answers as $a) {
        $qid = (int) $a['question_id'];
        $ans = $a['answer'];
        if (!isset($questionMap[$qid])) {
            // 配信に含まれない設問への回答は無視(0点・maxも加算しない)
            $scored[] = ['question_id' => $qid, 'answer' => $ans, 'is_correct' => false, 'score_earned' => 0, 'max_score' => 0];
            continue;
        }
        $q = $questionMap[$qid];
        $correct = $q['correct'];
        $isCorrect = take_arrays_equal($ans, $correct);
        $qMax = (int) $q['difficulty'];
        $earned = $isCorrect ? $qMax : 0;

        $scored[] = [
            'question_id' => $qid,
            'answer' => $ans,
            'is_correct' => $isCorrect,
            'score_earned' => $earned,
            'max_score' => $qMax,
        ];
        $totalScore += $earned;
        $maxScore += $qMax;
    }

    $percentage = $maxScore > 0 ? (int) round($totalScore / $maxScore * 100) : 0;
    return [
        'total_score' => $totalScore,
        'max_score' => $maxScore,
        'percentage' => $percentage,
        'answers' => $scored,
    ];
}

/** index配列の集合一致(順不同)。scoring.ts の arraysEqual 移植。 */
function take_arrays_equal(array $a, array $b): bool
{
    if (count($a) !== count($b)) {
        return false;
    }
    $sa = array_map('intval', $a);
    $sb = array_map('intval', $b);
    sort($sa);
    sort($sb);
    return $sa === $sb;
}

/** 配信の設問を(sort_order 順で)取得。 */
function take_delivery_questions(int $deliveryId): array
{
    return Db::all(
        'SELECT q.id, q.title, q.question_type, q.options, q.correct_answer, q.explanation, q.difficulty, dq.sort_order
         FROM edu_delivery_questions dq
         INNER JOIN edu_questions q ON q.id = dq.question_id
         WHERE dq.delivery_id = ?
         ORDER BY dq.sort_order, dq.id',
        [$deliveryId]
    );
}

function take_handle_start(): never
{
    $token = take_token('get');
    $a = take_resolve($token);

    if ((string) $a['status'] === 'completed') {
        take_error('この受講は既に完了しています', 409);
    }

    $questions = take_delivery_questions((int) $a['delivery_id']);
    if ($questions === []) {
        take_error('出題する設問がありません', 404);
    }

    // 初回アクセスで started に(冪等: 既に started ならそのまま)
    if ((string) $a['status'] === 'assigned') {
        Db::run(
            "UPDATE edu_assignments SET status='started', started_at=datetime('now','localtime') WHERE id=? AND status='assigned'",
            [(int) $a['id']]
        );
    }

    // correct_answer / explanation は秘匿(採点前に答えを渡さない)
    $out = [];
    foreach ($questions as $q) {
        $out[] = [
            'id' => (int) $q['id'],
            'title' => $q['title'],
            'question_type' => $q['question_type'],
            'options' => json_decode((string) $q['options'], true) ?: [],
            'difficulty' => (int) $q['difficulty'],
        ];
    }

    take_json([
        'success' => true,
        'delivery' => [
            'title' => $a['delivery_title'],
            'delivery_type' => $a['delivery_type'],
            'question_count' => count($out),
        ],
        'questions' => $out,
    ]);
}

function take_handle_submit(): never
{
    $token = take_token('post');
    $a = take_resolve($token);

    if ((string) $a['status'] === 'completed') {
        take_error('この受講は既に完了しています', 409);
    }

    $body = take_body();
    if (!isset($body['answers']) || !is_array($body['answers'])) {
        take_error('answers は配列で指定してください', 400);
    }

    // 提出された回答を正規化(question_id => int[])
    $submitted = [];
    foreach ($body['answers'] as $item) {
        if (!is_array($item) || !isset($item['question_id']) || !is_int($item['question_id'])) {
            take_error('answers の形式が不正です', 400);
        }
        $ans = $item['answer'] ?? [];
        if (!is_array($ans)) {
            take_error('answer は配列で指定してください', 400);
        }
        $idx = [];
        foreach ($ans as $v) {
            if (!is_int($v) || $v < 0) {
                take_error('answer の要素が不正です', 400);
            }
            $idx[] = $v;
        }
        $submitted[$item['question_id']] = $idx;
    }

    // 配信の正解表を作る(サーバ側=改ざん不能)
    $questions = take_delivery_questions((int) $a['delivery_id']);
    if ($questions === []) {
        take_error('出題する設問がありません', 404);
    }
    $questionMap = [];
    $qMeta = [];
    foreach ($questions as $q) {
        $qid = (int) $q['id'];
        $questionMap[$qid] = [
            'correct' => json_decode((string) $q['correct_answer'], true) ?: [],
            'difficulty' => (int) $q['difficulty'],
        ];
        $qMeta[$qid] = $q;
    }

    // 全設問について採点(未回答は空配列=不正解扱い、maxScoreには算入)
    $answersForScoring = [];
    foreach ($questions as $q) {
        $qid = (int) $q['id'];
        $answersForScoring[] = [
            'question_id' => $qid,
            'answer' => $submitted[$qid] ?? [],
        ];
    }
    $result = take_score($answersForScoring, $questionMap);

    // 保存(トランザクション)。二重受講防止: assignment を completed に。
    $passScore = $a['pass_score'] !== null ? (int) $a['pass_score'] : null;
    $passed = $passScore !== null ? ($result['percentage'] >= $passScore) : null;

    Db::tx(function () use ($a, $result, $qMeta) {
        // 既存の response があれば作らない(二重防止の保険。通常は completed ガードで来ない)
        $existing = Db::one('SELECT id FROM edu_responses WHERE assignment_id = ?', [(int) $a['id']]);
        if ($existing !== null) {
            return;
        }
        $responseId = Db::insert(
            'INSERT INTO edu_responses (tenant_id, assignment_id, total_score, max_score, percentage, started_at, completed_at)
             VALUES (?, ?, ?, ?, ?, ?, datetime(\'now\',\'localtime\'))',
            [
                (int) $a['tenant_id'],
                (int) $a['id'],
                $result['total_score'],
                $result['max_score'],
                $result['percentage'],
                $a['started_at'],
            ]
        );
        foreach ($result['answers'] as $sa) {
            // 配信外設問(max_score=0)は保存しない
            if (!isset($qMeta[$sa['question_id']])) {
                continue;
            }
            Db::run(
                'INSERT INTO edu_response_answers (response_id, question_id, answer, is_correct, score_earned)
                 VALUES (?, ?, ?, ?, ?)',
                [
                    $responseId,
                    $sa['question_id'],
                    json_encode($sa['answer']),
                    $sa['is_correct'] ? 1 : 0,
                    $sa['score_earned'],
                ]
            );
        }
        Db::run(
            "UPDATE edu_assignments SET status='completed', completed_at=datetime('now','localtime'), score=? WHERE id=?",
            [$result['percentage'], (int) $a['id']]
        );
    });

    // 即時結果(解説つき)。ここで初めて correct_answer と explanation を返す。
    $feedback = [];
    foreach ($questions as $q) {
        $qid = (int) $q['id'];
        $userAns = $submitted[$qid] ?? [];
        $correct = json_decode((string) $q['correct_answer'], true) ?: [];
        $feedback[] = [
            'id' => $qid,
            'title' => $q['title'],
            'options' => json_decode((string) $q['options'], true) ?: [],
            'your_answer' => $userAns,
            'correct_answer' => $correct,
            'is_correct' => take_arrays_equal($userAns, $correct),
            'explanation' => $q['explanation'],
        ];
    }

    take_json([
        'success' => true,
        'result' => [
            'total_score' => $result['total_score'],
            'max_score' => $result['max_score'],
            'percentage' => $result['percentage'],
            'pass_score' => $passScore,
            'passed' => $passed,
        ],
        'feedback' => $feedback,
    ]);
}

try {
    $action = $_GET['action'] ?? '';
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($action === 'start' && $method === 'GET') {
        take_handle_start();
    }
    if ($action === 'submit' && $method === 'POST') {
        take_handle_submit();
    }
    take_error('不正なアクションです', 400);
} catch (Throwable $e) {
    error_log('edu_take: ' . $e->getMessage());
    take_error('サーバエラー', 500);
}
