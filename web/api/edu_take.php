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
require_once __DIR__ . '/../lib/EduMedia.php';

// ---- 最小レスポンスヘルパ(bootstrap を使わないため自前) ----
function take_json($data, int $code = 200): never
{
    // テストでは応答を捕まえる json_out が定義されている(本番の受講 API は bootstrap を読まないので未定義)
    if (function_exists('json_out')) {
        json_out($data, $code);
    }
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
    if (function_exists('json_body')) {
        return json_body();
    }
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
        'SELECT a.*, d.title AS delivery_title, d.delivery_type, d.pass_score, d.status AS delivery_status,
                d.feedback_mode, d.material_id
         FROM edu_assignments a
         INNER JOIN edu_deliveries d ON d.id = a.delivery_id
         WHERE a.access_token = ?',
        [$token]
    );
    if ($a === null) {
        take_error('無効なトークンです', 404);
    }
    if (take_is_expired($a['token_expiry'] ?? null)) {
        take_error('この受講リンクは有効期限が切れています', 410);
    }
    return $a;
}

/**
 * 受講リンクが期限切れかを判定する。
 *
 * 期限は 'YYYY-MM-DD HH:MM:SS'(localtime)。空・NULL・解釈不能な値は「期限なし」と扱い、
 * 受講を止めない(判定できないことを理由に受講者を締め出さない)。
 */
function take_is_expired(?string $tokenExpiry, ?int $now = null): bool
{
    if ($tokenExpiry === null || trim($tokenExpiry) === '') {
        return false;
    }
    $expiry = strtotime($tokenExpiry);
    if ($expiry === false) {
        return false;
    }
    return $expiry < ($now ?? time());
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
        'SELECT q.id, q.tenant_id, q.title, q.question_type, q.options, q.correct_answer, q.explanation, q.option_explanations,
                q.image_name, q.difficulty, dq.sort_order
         FROM edu_delivery_questions dq
         INNER JOIN edu_questions q ON q.id = dq.question_id
         WHERE dq.delivery_id = ?
         ORDER BY dq.sort_order, dq.id',
        [$deliveryId]
    );
}

function take_delivery_material(int $deliveryId): ?array
{
    $material = Db::one(
        'SELECT m.id, m.tenant_id, m.title, m.description, m.slides, m.format, m.page_count
         FROM edu_deliveries d
         INNER JOIN edu_materials m ON m.id = d.material_id
         WHERE d.id = ? AND m.is_active = 1',
        [$deliveryId]
    );
    if ($material === null) {
        return null;
    }
    $material['id'] = (int) $material['id'];
    $material['slides'] = json_decode((string) $material['slides'], true) ?: [];
    $material['format'] = (string) ($material['format'] ?? 'text_slides');
    $material['page_count'] = (int) ($material['page_count'] ?? 0);
    $material['pages'] = [];
    if ($material['format'] === 'page_images') {
        foreach (Db::all('SELECT page_no, width, height, page_text FROM edu_material_pages WHERE material_id = ? ORDER BY page_no',
            [$material['id']]) as $page) {
            $material['pages'][] = ['page_no' => (int) $page['page_no'], 'width' => (int) $page['width'],
                'height' => (int) $page['height'], 'page_text' => (string) $page['page_text']];
        }
    }
    // ページ画像の版。画像の URL に付け、PDF の差し替えの後にブラウザが古いページ画像を使わないようにする
    // (管理 API の edu_m_rev と同じく、作り直すたびに増えるページの行の最小の id)
    $rev = Db::one('SELECT MIN(id) AS rev FROM edu_material_pages WHERE material_id = ?', [$material['id']]);
    $material['rev'] = (string) ($rev['rev'] ?? '');
    unset($material['tenant_id']);
    return $material;
}

/** 選択肢ごとの解説(JSON)を配列に。なければ null。 */
function take_option_explanations(?string $json): ?array
{
    $value = json_decode((string) $json, true);
    return is_array($value) && $value !== [] ? array_values(array_map('strval', $value)) : null;
}

/** 1問の答え合わせの内容(正解、選択肢ごとの解説、まとめの解説)。 */
function take_question_feedback(array $q, array $answer): array
{
    $correct = json_decode((string) $q['correct_answer'], true) ?: [];
    return [
        'id' => (int) $q['id'],
        'title' => $q['title'],
        'options' => json_decode((string) $q['options'], true) ?: [],
        'your_answer' => array_values(array_map('intval', $answer)),
        'correct_answer' => $correct,
        'is_correct' => take_arrays_equal($answer, $correct),
        'explanation' => $q['explanation'],
        'option_explanations' => take_option_explanations($q['option_explanations'] ?? null),
        'has_image' => ($q['image_name'] ?? '') !== '',
    ];
}

/** 画像を送り出す。 */
function take_send_file(array $file): never
{
    header('Content-Type: ' . $file['mime']);
    header('Content-Length: ' . filesize($file['path']));
    header('Cache-Control: private, max-age=3600');
    header('X-Content-Type-Options: nosniff');
    readfile($file['path']);
    exit;
}

/**
 * 受講者のトークンで、配信の教材のページ画像の場所を決める。受講の完了後も教材は見直せる。
 *
 * @return array{path:string,mime:string}
 */
function take_page_image_file(string $token, int $pageNo): array
{
    $a = take_resolve($token);
    $material = $a['material_id'] !== null
        ? Db::one("SELECT id, tenant_id FROM edu_materials WHERE id = ? AND format = 'page_images'", [(int) $a['material_id']])
        : null;
    $page = $material !== null
        ? Db::one('SELECT image_name FROM edu_material_pages WHERE material_id = ? AND page_no = ?', [(int) $material['id'], $pageNo])
        : null;
    if ($page === null || !EduMedia::safeName((string) $page['image_name'])) {
        take_error('ページが見つかりません', 404);
    }
    $owner = $material['tenant_id'] !== null ? (int) $material['tenant_id'] : null;
    $path = EduMedia::materialDir($owner, (int) $material['id']) . '/' . $page['image_name'];
    if (!is_file($path)) {
        take_error('ページが見つかりません', 404);
    }
    return ['path' => $path, 'mime' => EduMedia::mimeOf((string) $page['image_name'])];
}

/** @return array{path:string,mime:string} 配信に含まれる設問の画像の場所 */
function take_question_image_file(string $token, int $questionId): array
{
    $a = take_resolve($token);
    $q = Db::one(
        'SELECT q.tenant_id, q.image_name FROM edu_delivery_questions dq
         INNER JOIN edu_questions q ON q.id = dq.question_id
         WHERE dq.delivery_id = ? AND q.id = ?',
        [(int) $a['delivery_id'], $questionId]
    );
    $name = (string) ($q['image_name'] ?? '');
    if ($q === null || $name === '' || !EduMedia::safeName($name)) {
        take_error('画像が見つかりません', 404);
    }
    $path = EduMedia::questionDir($q['tenant_id'] !== null ? (int) $q['tenant_id'] : null) . '/' . $name;
    if (!is_file($path)) {
        take_error('画像が見つかりません', 404);
    }
    return ['path' => $path, 'mime' => EduMedia::mimeOf($name)];
}

/** 答え合わせ済みの解答(question_id => int[])。 */
function take_locked_answers(int $assignmentId): array
{
    $out = [];
    foreach (Db::all('SELECT question_id, answer FROM edu_answer_locks WHERE assignment_id = ?', [$assignmentId]) as $row) {
        $out[(int) $row['question_id']] = array_map('intval', json_decode((string) $row['answer'], true) ?: []);
    }
    return $out;
}

/**
 * 1問ずつの答え合わせ(feedback_mode = immediate の配信だけ)。
 * 配信の割当と設問の組で最初の解答だけを記録し、2回目以降は最初の結果を返す(正解を見てから選び直させない)。
 */
function take_handle_answer(): never
{
    $token = take_token('post');
    $a = take_resolve($token);
    if ((string) $a['status'] === 'completed') {
        take_error('この受講は既に完了しています', 409);
    }
    if ((string) ($a['feedback_mode'] ?? '') !== 'immediate') {
        take_error('この配信は提出後にまとめて答え合わせをします', 409);
    }
    $body = take_body();
    $questionId = $body['question_id'] ?? null;
    $answer = $body['answer'] ?? null;
    if (!is_int($questionId) || !is_array($answer)) {
        take_error('question_id と answer を指定してください', 400);
    }
    foreach ($answer as $v) {
        if (!is_int($v) || $v < 0) {
            take_error('answer の要素が不正です', 400);
        }
    }
    $q = null;
    foreach (take_delivery_questions((int) $a['delivery_id']) as $row) {
        if ((int) $row['id'] === $questionId) {
            $q = $row;
        }
    }
    if ($q === null) {
        take_error('この配信の設問ではありません', 404);
    }
    if ((string) $a['status'] === 'assigned') {
        Db::run("UPDATE edu_assignments SET status='started', started_at=datetime('now','localtime') WHERE id=? AND status='assigned'",
            [(int) $a['id']]);
    }
    $correct = json_decode((string) $q['correct_answer'], true) ?: [];
    Db::run(
        'INSERT OR IGNORE INTO edu_answer_locks (assignment_id, question_id, answer, is_correct) VALUES (?, ?, ?, ?)',
        [(int) $a['id'], $questionId, json_encode(array_values($answer)), take_arrays_equal($answer, $correct) ? 1 : 0]
    );
    $locked = take_locked_answers((int) $a['id'])[$questionId];
    take_json([
        'success' => true,
        'locked' => $locked !== array_values($answer),
        'feedback' => take_question_feedback($q, $locked),
    ]);
}

function take_upsert_response(array $assignment, array $result): int
{
    $existing = Db::one('SELECT id FROM edu_responses WHERE assignment_id = ?', [(int) $assignment['id']]);
    if ($existing !== null) {
        $responseId = (int) $existing['id'];
        Db::run(
            "UPDATE edu_responses SET total_score=?, max_score=?, percentage=?,
             completed_at=datetime('now','localtime') WHERE id=?",
            [$result['total_score'], $result['max_score'], $result['percentage'], $responseId]
        );
        Db::run('DELETE FROM edu_response_answers WHERE response_id = ?', [$responseId]);
        return $responseId;
    }
    return Db::insert(
        "INSERT INTO edu_responses
         (tenant_id, assignment_id, total_score, max_score, percentage, started_at, completed_at)
         VALUES (?, ?, ?, ?, ?, ?, datetime('now','localtime'))",
        [(int) $assignment['tenant_id'], (int) $assignment['id'], $result['total_score'],
            $result['max_score'], $result['percentage'], $assignment['started_at']]
    );
}

function take_save_attempt(array $assignment, array $result, array $questionMeta, bool $completed, bool $clearLocks = false): void
{
    Db::tx(function () use ($assignment, $result, $questionMeta, $completed, $clearLocks) {
        if ($clearLocks) {
            // eラーニングで不合格なら、答え合わせの固定を消して受け直せるようにする(採点の保存と同じトランザクション)
            Db::run('DELETE FROM edu_answer_locks WHERE assignment_id = ?', [(int) $assignment['id']]);
        }
        $responseId = take_upsert_response($assignment, $result);
        foreach ($result['answers'] as $answer) {
            if (!isset($questionMeta[$answer['question_id']])) {
                continue;
            }
            Db::run(
                'INSERT INTO edu_response_answers
                 (response_id, question_id, answer, is_correct, score_earned) VALUES (?, ?, ?, ?, ?)',
                [$responseId, $answer['question_id'], json_encode($answer['answer']),
                    $answer['is_correct'] ? 1 : 0, $answer['score_earned']]
            );
        }
        if ($completed) {
            Db::run(
                "UPDATE edu_assignments SET status='completed',
                 completed_at=datetime('now','localtime'), score=? WHERE id=?",
                [$result['percentage'], (int) $assignment['id']]
            );
        } else {
            Db::run(
                "UPDATE edu_assignments SET status='started', completed_at=NULL, score=? WHERE id=?",
                [$result['percentage'], (int) $assignment['id']]
            );
        }
    });
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
        // token解決後に配信削除が先行した場合、消えた割当で受講成功を返さない。
        $a = take_resolve($token);
        if ((string) $a['status'] === 'completed') {
            take_error('この受講は既に完了しています', 409);
        }
    }

    // correct_answer / explanation は秘匿(採点前に答えを渡さない)
    $out = [];
    $byId = [];
    foreach ($questions as $q) {
        $byId[(int) $q['id']] = $q;
        $out[] = [
            'id' => (int) $q['id'],
            'title' => $q['title'],
            'question_type' => $q['question_type'],
            'options' => json_decode((string) $q['options'], true) ?: [],
            'difficulty' => (int) $q['difficulty'],
            'has_image' => ($q['image_name'] ?? '') !== '',
        ];
    }
    // 途中で中断して再開したとき、答え合わせ済みの設問はその結果から続ける
    $answered = [];
    foreach (take_locked_answers((int) $a['id']) as $qid => $answer) {
        if (isset($byId[$qid])) {
            $answered[] = ['question_id' => $qid, 'feedback' => take_question_feedback($byId[$qid], $answer)];
        }
    }

    take_json([
        'success' => true,
        'delivery' => [
            'title' => $a['delivery_title'],
            'delivery_type' => $a['delivery_type'],
            'question_count' => count($out),
            'feedback_mode' => (string) ($a['feedback_mode'] ?? 'after_submit'),
            'pass_score' => $a['pass_score'] !== null ? (int) $a['pass_score'] : null,
        ],
        'material' => take_delivery_material((int) $a['delivery_id']),
        'questions' => $out,
        'answered' => $answered,
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

    // 答え合わせ済みの設問は、固定した解答で採点する(提出の中身で書き換えさせない)
    $immediate = (string) ($a['feedback_mode'] ?? '') === 'immediate';
    if ($immediate) {
        foreach (take_locked_answers((int) $a['id']) as $qid => $answer) {
            $submitted[$qid] = $answer;
        }
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

    // eラーニングは合格時だけ完了。アウェアネスは提出時点で完了。
    $passScore = $a['pass_score'] !== null ? (int) $a['pass_score'] : null;
    $passed = $passScore !== null ? ($result['percentage'] >= $passScore) : null;
    $completed = (string) $a['delivery_type'] !== 'elearning' || $passed === true;
    take_save_attempt($a, $result, $qMeta, $completed, $immediate && !$completed);

    // 即時結果(解説つき)。ここで初めて correct_answer と explanation を返す。
    $feedback = [];
    foreach ($questions as $q) {
        $qid = (int) $q['id'];
        $feedback[] = take_question_feedback($q, $submitted[$qid] ?? []);
    }

    take_json([
        'success' => true,
        'result' => [
            'total_score' => $result['total_score'],
            'max_score' => $result['max_score'],
            'percentage' => $result['percentage'],
            'pass_score' => $passScore,
            'passed' => $passed,
            'completed' => $completed,
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
    if ($action === 'answer' && $method === 'POST') {
        take_handle_answer();
    }
    if ($action === 'page_image' && $method === 'GET') {
        $page = filter_var($_GET['page'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($page === false) {
            take_error('page が不正です', 400);
        }
        take_send_file(take_page_image_file(take_token('get'), (int) $page));
    }
    if ($action === 'question_image' && $method === 'GET') {
        $qid = filter_var($_GET['question_id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($qid === false) {
            take_error('question_id が不正です', 400);
        }
        take_send_file(take_question_image_file(take_token('get'), (int) $qid));
    }
    take_error('不正なアクションです', 400);
} catch (Throwable $e) {
    error_log('edu_take: ' . $e->getMessage());
    take_error('サーバエラー', 500);
}
