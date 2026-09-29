<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/CredentialVault.php';

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}
if (($_SERVER['HTTPS'] ?? '') !== 'on' && (int) ($_SERVER['SERVER_PORT'] ?? 0) !== 443) {
    http_response_code(403);
    exit('HTTPS Required');
}
if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 4096) {
    http_response_code(413);
    exit('Payload Too Large');
}

$trackingId = $_POST['random_value'] ?? null;
$authType = $_POST['auth_type'] ?? null;
if (!is_string($trackingId) || !is_string($authType)) {
    http_response_code(400);
    exit('Invalid Request');
}

$fieldNames = [
    'box' => ['email', 'password'],
    'ms365' => ['email', 'password'],
    'digitalarts' => ['login_id'],
    'ms365_email' => ['email'],
][$authType] ?? null;
if ($fieldNames === null) {
    http_response_code(400);
    exit('Invalid Request');
}
$entered = [];
foreach ($fieldNames as $name) { $entered[$name] = $_POST[$name] ?? null; }

$stage = 'lookup';
try {
    // 種明かしは既存の共通版を基準にし、所属テナント版が読める時だけ差し替える。
    $masterPath = getenv('TET2_DB_PATH') !== false
        ? __DIR__ . '/../../bin/master.html' : '/opt/training/bin/master.html';
    $tenant = Db::one(
        'SELECT t.data_dir, c.reveal_page_id FROM campaign_targets ct
           JOIN campaigns c ON c.id=ct.campaign_id AND c.deleted_at IS NULL
           JOIN tenants t ON t.id=c.tenant_id WHERE ct.tracking_id=?',
        [$trackingId]
    );
    if ($tenant !== null) {
        $dataDir = rtrim((string) $tenant['data_dir'], '/');
        // 優先順: キャンペーンが選んだ種明かしページ > テナント既定の reveal.html > 共通 master.html(G29)。
        $selected = $tenant['reveal_page_id'] !== null
            ? $dataDir . '/reveal-pages/reveal-' . (int) $tenant['reveal_page_id'] . '.html' : null;
        $default = $dataDir . '/reveal.html';
        if ($selected !== null && is_file($selected) && is_readable($selected)) {
            $masterPath = $selected;
        } elseif (is_file($default) && is_readable($default)) {
            $masterPath = $default;
        }
    }
    $stage = 'reveal_page';
    $html = @file_get_contents($masterPath);
    if ($html === false) { throw new RuntimeException('reveal unavailable'); }

    $stage = 'record';
    CredentialVault::record($trackingId, $authType, $entered);
    $html = preg_replace('/<img[^>]*kunren-beacon[^>]*>/i', '', $html);
    http_response_code(200);
    header('Content-Type: text/html; charset=utf-8');
    echo str_replace('#$5$#', '', $html);
} catch (DomainException $error) {
    http_response_code(403);
    echo 'Capture Not Authorized';
} catch (Throwable $error) {
    error_log('[credential_capture] failed at ' . $stage . ': ' . get_class($error));
    http_response_code(503);
    echo 'Capture Unavailable';
}
