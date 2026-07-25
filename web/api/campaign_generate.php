<?php declare(strict_types=1);
require __DIR__ . "/../lib/bootstrap.php";
require_once __DIR__ . "/../lib/PipelineRunner.php";

/**
 * data_dir 配下に生成された CSV 群の概要を返す。
 *
 * @return array<int, array{filename: string, rows: int, bytes: int}>
 */
function generated_csv_files(string $dir): array
{
    $files = ['list.csv', 'kenmei.csv', 'honbun.csv', 'URL.csv', 'Attachment.csv'];
    $baseDir = rtrim($dir, '/');
    $summaries = [];

    foreach ($files as $filename) {
        $path = $baseDir . '/' . $filename;
        if (!is_file($path)) {
            continue;
        }
        $lines = file($path);
        $lineCount = $lines === false ? 0 : count($lines);
        $size = filesize($path);
        $summaries[] = [
            'filename' => $filename,
            'rows' => max(0, $lineCount - 1),
            'bytes' => $size === false ? 0 : $size,
        ];
    }

    return $summaries;
}

try {
    $actor = require_role('operator');
    $action = $_GET['action'] ?? '';
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method !== 'POST') {
        json_error('不正なアクションです', 400);
    }
    tet2_require_csrf();

    $body = json_body();
    $campaignId = isset($body['id']) && is_int($body['id']) ? $body['id'] : 0;
    if ($campaignId < 1) {
        json_error('id が不正です', 400);
    }
    $tenantId = effective_tenant_id($actor, isset($body['tenant_id']) && is_int($body['tenant_id']) ? $body['tenant_id'] : null);
    $campaign = assert_campaign_owned($campaignId, $tenantId);

    if ($action === 'generate') {
        // draft 確認用に CSV 群だけを生成し、送信やビーコン生成は行わない。
        $dir = PipelineRunner::generateCsv($campaignId);
        $files = generated_csv_files($dir);
        audit('campaign.generate', 'campaign_id=' . $campaignId);
        json_out([
            'success' => true,
            'data_dir' => $dir,
            'files' => $files,
            'note' => 'CSV群を生成しました(送信・ビーコン生成はしていません)',
        ]);
    }

    json_error('不正なアクションです', 400);
} catch (Throwable $e) {
    error_log($e->getMessage());
    if ($e instanceof RuntimeException) {
        json_error($e->getMessage(), 400);
    }
    json_error('サーバエラー', 500);
}
