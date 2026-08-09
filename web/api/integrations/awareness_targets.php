<?php
declare(strict_types=1);

require_once __DIR__ . '/../../lib/Db.php';
require_once __DIR__ . '/../../lib/IntegrationAuth.php';
require_once __DIR__ . '/../../lib/AwarenessTargetService.php';

function integration_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}

function integration_body(): array
{
    $raw = file_get_contents('php://input');
    $body = json_decode($raw === false ? '' : $raw, true);
    if (!is_array($body)) {
        throw new IntegrationValidationException('JSON bodyが不正です');
    }
    return $body;
}

function integration_query_int(string $key, int $default, int $minimum = 0): int
{
    if (!isset($_GET[$key])) {
        return $default;
    }
    $value = filter_var($_GET[$key], FILTER_VALIDATE_INT);
    if ($value === false || $value < $minimum) {
        throw new IntegrationValidationException("{$key}が不正です");
    }
    return (int) $value;
}

function integration_require_https(array $server): void
{
    if (getenv('TET2_INTEGRATION_ALLOW_HTTP') === '1') {
        return;
    }
    $forwarded = strtolower((string) ($server['HTTP_X_FORWARDED_PROTO'] ?? ''));
    $remote = (string) ($server['REMOTE_ADDR'] ?? '');
    $trustForwarded = in_array($remote, ['127.0.0.1', '::1'], true)
        || getenv('TET2_TRUST_PROXY_HEADERS') === '1';
    if (($server['HTTPS'] ?? '') !== 'on' && (!$trustForwarded || $forwarded !== 'https')) {
        throw new IntegrationAuthException(400, 'HTTPSが必要です');
    }
}

try {
    integration_require_https($_SERVER);
    $tenantId = IntegrationAuth::tenantId($_SERVER);
    $service = new AwarenessTargetService($tenantId);
    $action = isset($_GET['action']) && is_string($_GET['action']) ? $_GET['action'] : 'snapshot';
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'GET' && $action === 'snapshot') {
        $boundary = isset($_GET['boundary']) ? integration_query_int('boundary', 0) : null;
        integration_json(['success' => true] + $service->snapshot(
            integration_query_int('cursor', 0), integration_query_int('limit', 100, 1), $boundary
        ));
    }
    if ($method === 'GET' && $action === 'phishing_results') {
        integration_json(['success' => true] + $service->phishingResults(
            integration_query_int('cursor', 0), integration_query_int('limit', 100, 1)
        ));
    }
    if ($method === 'GET' && $action === 'group_snapshot') {
        integration_json(['success' => true] + $service->groupSnapshot());
    }

    $key = (string) ($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '');
    if ($method === 'POST' && $action === 'upsert') {
        integration_json(['success' => true] + $service->upsert(integration_body(), $key));
    }
    if ($method === 'POST' && $action === 'archive') {
        $body = integration_body();
        $targetId = $body['targetId'] ?? null;
        if (!is_int($targetId)) {
            throw new IntegrationValidationException('targetIdが不正です');
        }
        integration_json(['success' => true] + $service->archive($targetId, $key));
    }
    if ($method === 'POST' && $action === 'group_upsert') {
        integration_json(['success' => true] + $service->upsertGroup(integration_body(), $key));
    }
    if ($method === 'POST' && $action === 'group_archive') {
        $body = integration_body();
        $groupId = $body['groupId'] ?? null;
        if (!is_int($groupId)) {
            throw new IntegrationValidationException('groupIdが不正です');
        }
        integration_json(['success' => true] + $service->archiveGroup($groupId, $key));
    }
    integration_json(['success' => false, 'error' => 'actionが見つかりません'], 404);
} catch (IntegrationAuthException $error) {
    integration_json(['success' => false, 'error' => $error->getMessage()], $error->httpCode);
} catch (IntegrationValidationException $error) {
    integration_json(['success' => false, 'error' => $error->getMessage()], 400);
} catch (IntegrationConflictException $error) {
    integration_json(['success' => false, 'error' => $error->getMessage()], 409);
} catch (IntegrationNotFoundException $error) {
    integration_json(['success' => false, 'error' => $error->getMessage()], 404);
} catch (PDOException $error) {
    $status = str_contains($error->getMessage(), 'UNIQUE') ? 409 : 500;
    integration_json(['success' => false, 'error' => $status === 409 ? '一意制約に違反しました' : 'DB処理に失敗しました'], $status);
} catch (Throwable) {
    integration_json(['success' => false, 'error' => '内部エラーが発生しました'], 500);
}
