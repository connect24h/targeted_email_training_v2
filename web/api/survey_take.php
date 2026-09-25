<?php declare(strict_types=1);
/**
 * アンケート回答者向け 公開 API(認証不要・access_token 方式)。受講の edu_take.php と同じ流儀。
 *
 *   GET  survey_take.php?action=start&token=...   設問を返す
 *   POST survey_take.php?action=submit            {"token":..., "answers":{question_id: 値}} を保存する
 *
 * token は survey_assignments.access_token(32桁 hex)。URL に id を出さず、token から配信と対象者を確定する。
 * 回答済み・期限切れ・終了した配信は拒否する。検証と保存は SurveyService に集約する。
 */

require_once __DIR__ . '/../lib/Db.php';
require_once __DIR__ . '/../lib/SurveyService.php';

function stake_json(array $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function stake_body(): array
{
    $raw = file_get_contents('php://input');
    $data = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
    return is_array($data) ? $data : [];
}

/** @return array{0:int,1:array<string,mixed>} [HTTP コード, 応答] */
function stake_start(string $token): array
{
    try {
        return [200, ['success' => true] + SurveyService::startByToken($token)];
    } catch (SurveyException $e) {
        return [$e->httpCode, ['success' => false, 'error' => $e->getMessage()]];
    }
}

/** @return array{0:int,1:array<string,mixed>} */
function stake_submit(array $body): array
{
    $token = isset($body['token']) && is_string($body['token']) ? trim($body['token']) : '';
    $answers = $body['answers'] ?? null;
    if (!is_array($answers)) {
        return [400, ['success' => false, 'error' => '回答の形式が正しくありません']];
    }
    try {
        SurveyService::submitByToken($token, $answers);
        return [200, ['success' => true]];
    } catch (SurveyException $e) {
        return [$e->httpCode, ['success' => false, 'error' => $e->getMessage()]];
    }
}

try {
    $action = $_GET['action'] ?? '';
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($action === 'start' && $method === 'GET') {
        [$code, $data] = stake_start(isset($_GET['token']) && is_string($_GET['token']) ? trim($_GET['token']) : '');
        stake_json($data, $code);
    }
    if ($action === 'submit' && $method === 'POST') {
        [$code, $data] = stake_submit(stake_body());
        stake_json($data, $code);
    }
    stake_json(['success' => false, 'error' => '不正なアクションです'], 400);
} catch (Throwable $e) {
    error_log('survey_take: ' . $e->getMessage());
    stake_json(['success' => false, 'error' => 'サーバエラー'], 500);
}
