<?php declare(strict_types=1); require __DIR__."/../lib/bootstrap.php";
require_once __DIR__ . '/../lib/SuspiciousMailStore.php';
require_once __DIR__ . '/../lib/VirusTotalClient.php';
require_once __DIR__ . '/../lib/Secrets.php';

/**
 * 不審メール API。.eml を受け取って解析し、分類・状態・優先度の管理と VirusTotal 照会を提供する。
 *
 * - upload: {filename, file_base64, reporter_email?} を JSON で受ける（$_FILES は使わない既存規約）。
 * - テナント分離: 非 superadmin は自テナントの行のみ。superadmin はテナント未確定（NULL）の行も見える。
 * - raw .eml のダウンロードは提供しない（危険な添付の再配布経路になるため）。
 */

const SM_MAX_BASE64_LEN = 2_900_000;
const SM_REPUTATION_BATCH = 4;

function sm_tenant(array $actor): ?int
{
    // superadmin は tenant_id を指定しなければ全テナント横断（NULL 行を含む）。
    $requested = isset($_GET['tenant_id']) && $_GET['tenant_id'] !== '' ? (int) $_GET['tenant_id'] : null;
    if ($actor['role'] === 'superadmin' && $requested === null) {
        return null;
    }
    return effective_tenant_id($actor, $requested);
}

function sm_body_int(array $body, string $key): int
{
    if (!array_key_exists($key, $body) || !is_int($body[$key]) || $body[$key] < 1) {
        json_error($key . ' が不正です', 400);
    }
    return $body[$key];
}

function sm_query_enum(string $key, array $allowed): ?string
{
    $value = $_GET[$key] ?? '';
    if (!is_string($value) || $value === '') {
        return null;
    }
    if (!in_array($value, $allowed, true)) {
        json_error($key . ' が不正です', 400);
    }
    return $value;
}

function sm_handle_upload(array $actor): never
{
    tet2_require_csrf();
    $body = json_body();
    // superadmin は body か query の tenant_id で対象テナントを指定する（api() は query に付ける）。
    $requested = isset($body['tenant_id']) && is_int($body['tenant_id']) ? $body['tenant_id']
        : (isset($_GET['tenant_id']) && $_GET['tenant_id'] !== '' ? (int) $_GET['tenant_id'] : null);
    $tenantId = effective_tenant_id($actor, $requested);
    $filename = is_string($body['filename'] ?? null) ? trim($body['filename']) : '';
    $encoded = is_string($body['file_base64'] ?? null) ? $body['file_base64'] : '';
    if ($filename === '' || !preg_match('/\.eml$/i', $filename) || $encoded === '') {
        json_error('メールファイル（.eml）を指定してください', 400);
    }
    if (strlen($encoded) > SM_MAX_BASE64_LEN) {
        json_error('メールファイルは2MB以内にしてください', 400);
    }
    $raw = base64_decode($encoded, true);
    if (!is_string($raw) || $raw === '') {
        json_error('メールファイルを読み取れません', 400);
    }
    if (!preg_match('/^[!-9;-~]+:/m', substr($raw, 0, 8192))) {
        json_error('メールのヘッダー形式ではありません', 400);
    }
    $reporter = is_string($body['reporter_email'] ?? null) ? trim($body['reporter_email']) : null;
    if ($reporter !== null && $reporter !== '' && filter_var($reporter, FILTER_VALIDATE_EMAIL) === false) {
        json_error('報告者のメールアドレスが不正です', 400);
    }
    try {
        $result = Db::tx(static fn(): array => SuspiciousMailStore::create($raw, [
            'tenant_id' => $tenantId, 'source' => 'upload', 'uploaded_by' => (string) $actor['email'],
            'reporter_email' => $reporter !== '' ? $reporter : null,
        ]));
    } catch (InvalidArgumentException $e) {
        json_error($e->getMessage(), 400);
    }
    if ($result['duplicate']) {
        json_out(['success' => false, 'error' => '同じメールがすでに登録されています', 'id' => $result['id'], 'duplicate' => true], 409);
    }
    audit('suspicious_mail.upload', 'id=' . $result['id'] . ',file=' . mb_substr(basename($filename), 0, 80));
    $row = SuspiciousMailStore::find($result['id'], $tenantId);
    json_out(['success' => true, 'id' => $result['id'], 'score' => (int) $row['score'], 'suggested_category' => $row['suggested_category'],
        'is_training' => (int) $row['is_training']], 201);
}

function sm_handle_list(array $actor): never
{
    $tenantId = sm_tenant($actor);
    $limit = isset($_GET['limit']) ? max(1, min(200, (int) $_GET['limit'])) : 50;
    $offset = isset($_GET['offset']) ? max(0, (int) $_GET['offset']) : 0;
    $where = ['1=1'];
    $params = [];
    if ($tenantId !== null) {
        $where[] = 'tenant_id=?';
        $params[] = $tenantId;
    }
    foreach (['status' => SuspiciousMailStore::STATUSES, 'category' => SuspiciousMailAnalyzer::CATEGORIES,
              'priority' => SuspiciousMailStore::PRIORITIES, 'source' => ['upload', 'maildir']] as $key => $allowed) {
        $value = sm_query_enum($key, $allowed);
        if ($value !== null) {
            $where[] = "$key=?";
            $params[] = $value;
        }
    }
    $q = isset($_GET['q']) && is_string($_GET['q']) ? trim($_GET['q']) : '';
    if ($q !== '') {
        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], mb_substr($q, 0, 100)) . '%';
        $where[] = "(subject LIKE ? ESCAPE '\\' OR from_email LIKE ? ESCAPE '\\' OR reporter_email LIKE ? ESCAPE '\\')";
        array_push($params, $like, $like, $like);
    }
    $sql = implode(' AND ', $where);
    $total = (int) Db::one("SELECT COUNT(*) n FROM suspicious_mails WHERE $sql", $params)['n'];
    $rows = Db::all("SELECT id, tenant_id, source, received_at, from_email, from_name, subject, reporter_email, is_training,
                     score, suggested_category, category, status, priority, assigned_to, reputation_checked_at, created_at, updated_at
                     FROM suspicious_mails WHERE $sql ORDER BY received_at DESC, id DESC LIMIT ? OFFSET ?", [...$params, $limit, $offset]);
    $counts = Db::all("SELECT status, COUNT(*) n FROM suspicious_mails WHERE " . ($tenantId === null ? '1=1' : 'tenant_id=?') . ' GROUP BY status',
        $tenantId === null ? [] : [$tenantId]);
    json_out(['success' => true, 'rows' => $rows, 'total' => $total, 'limit' => $limit, 'offset' => $offset,
        'status_counts' => array_column($counts, 'n', 'status'), 'virustotal_configured' => Secrets::get('virustotal.api_key') !== null]);
}

function sm_handle_get(array $actor): never
{
    $tenantId = sm_tenant($actor);
    $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    $row = $id > 0 ? SuspiciousMailStore::detail($id, $tenantId) : null;
    if ($row === null) {
        json_error('不審メールが見つかりません', 404);
    }
    $row['virustotal_configured'] = Secrets::get('virustotal.api_key') !== null;
    json_out(['success' => true, 'mail' => $row]);
}

function sm_handle_update(array $actor): never
{
    tet2_require_csrf();
    $body = json_body();
    $id = sm_body_int($body, 'id');
    $tenantId = sm_tenant($actor);
    try {
        $result = Db::tx(static fn(): array => SuspiciousMailStore::update($id, $tenantId, $body, (string) $actor['email']));
    } catch (DomainException $e) {
        json_error($e->getMessage(), $e->getCode() ?: 400);
    }
    if ($result['changed'] !== []) {
        audit('suspicious_mail.update', 'id=' . $id . ',fields=' . implode('/', $result['changed']));
    }
    json_out(['success' => true] + $result);
}

function sm_handle_reanalyze(array $actor): never
{
    tet2_require_csrf();
    $id = sm_body_int(json_body(), 'id');
    $tenantId = sm_tenant($actor);
    try {
        $result = Db::tx(static fn(): array => SuspiciousMailStore::reanalyze($id, $tenantId));
    } catch (DomainException $e) {
        json_error($e->getMessage(), $e->getCode() ?: 400);
    } catch (InvalidArgumentException $e) {
        json_error($e->getMessage(), 400);
    }
    audit('suspicious_mail.reanalyze', 'id=' . $id);
    json_out(['success' => true] + $result);
}

function sm_handle_reputation(array $actor): never
{
    tet2_require_csrf();
    $id = sm_body_int(json_body(), 'id');
    $tenantId = sm_tenant($actor);
    $apiKey = Secrets::get('virustotal.api_key');
    if ($apiKey === null) {
        json_out(['success' => false, 'error' => 'VirusTotal の API キーが設定されていません', 'code' => 'virustotal_not_configured'], 409);
    }
    $client = new VirusTotalClient($apiKey);
    try {
        $result = SuspiciousMailStore::runReputation($id, $tenantId, $client, SM_REPUTATION_BATCH);
    } catch (DomainException $e) {
        json_error($e->getMessage(), $e->getCode() ?: 400);
    } catch (InvalidArgumentException $e) {
        json_error($e->getMessage(), 400);
    }
    audit('suspicious_mail.reputation', 'id=' . $id . ',done=' . $result['done'] . ',remaining=' . $result['remaining']);
    json_out(['success' => true] + $result);
}

try {
    $action = $_GET['action'] ?? '';
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $actor = require_role($method === 'GET' ? 'viewer' : 'operator');

    if ($action === 'list' && $method === 'GET') {
        sm_handle_list($actor);
    }
    if ($action === 'get' && $method === 'GET') {
        sm_handle_get($actor);
    }
    if ($action === 'upload' && $method === 'POST') {
        sm_handle_upload($actor);
    }
    if ($action === 'update' && $method === 'POST') {
        sm_handle_update($actor);
    }
    if ($action === 'reanalyze' && $method === 'POST') {
        sm_handle_reanalyze($actor);
    }
    if ($action === 'reputation' && $method === 'POST') {
        sm_handle_reputation($actor);
    }
    json_error('不正なアクションです', 400);
} catch (Throwable $e) {
    error_log($e->getMessage());
    json_error('サーバエラー', 500);
}
