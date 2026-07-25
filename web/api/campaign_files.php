<?php
/**
 * データビューア: キャンペーンの data_dir 内マスタCSV + 認証マスタHTML を閲覧する。
 * テナント分離: assert_campaign_owned でキャンペーン所有を確認し、他テナントの data_dir は読めない。
 * パストラバーサル防御: ファイル名はホワイトリストの完全一致のみ許可。
 *
 *   GET  action=list&id=<campaign_id>            data_dir 内の存在ファイル一覧
 *   GET  action=content&id=<campaign_id>&file=<name>   ファイル内容(CSV→JSON行, HTML→raw)
 */
declare(strict_types=1);
require __DIR__ . '/../lib/bootstrap.php';

// data_dir 内で閲覧を許可するファイル(CSVマスタ)
const CAMPAIGN_DATA_FILES = ['list.csv', 'kenmei.csv', 'honbun.csv', 'Attachment.csv', 'URL.csv'];
// 認証マスタHTML(グローバル共有 /opt/training/bin/。認証フラグ 0-3 の出し分け)
const AUTH_MASTER_FILES = ['master.html', 'master2.html', 'master3.html', 'master4.html'];
const AUTH_MASTER_DIR   = '/opt/training/bin';
const AUTH_MASTER_LABEL = [
    'master.html'  => '認証フラグ0: 通常',
    'master2.html' => '認証フラグ1: Box認証',
    'master3.html' => '認証フラグ2: Microsoft365認証',
    'master4.html' => '認証フラグ3: デジタルアーツ認証',
];

function cf_query_int(string $key): ?int
{
    if (!isset($_GET[$key]) || $_GET[$key] === '') {
        return null;
    }
    $v = filter_var($_GET[$key], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    return $v === false ? null : (int) $v;
}

try {
    $actor  = require_role('viewer');
    $action = $_GET['action'] ?? '';
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        json_error('不正なアクションです', 400);
    }
    $campaignId = cf_query_int('id');
    if ($campaignId === null) {
        json_error('id が不正です', 400);
    }
    $tenantId = effective_tenant_id($actor, cf_query_int('tenant_id'));
    $campaign = assert_campaign_owned($campaignId, $tenantId); // 他テナントは 404

    $dataDir = rtrim((string) ($campaign['data_dir'] ?? ''), '/');

    if ($action === 'list') {
        $files = [];
        // data_dir 内 CSV マスタ
        foreach (CAMPAIGN_DATA_FILES as $name) {
            $path = $dataDir !== '' ? $dataDir . '/' . $name : '';
            if ($path !== '' && is_file($path)) {
                $files[] = ['file' => $name, 'kind' => 'csv', 'label' => $name, 'size' => filesize($path), 'exists' => true];
            } else {
                $files[] = ['file' => $name, 'kind' => 'csv', 'label' => $name, 'size' => 0, 'exists' => false];
            }
        }
        // 認証マスタHTML(グローバル)
        foreach (AUTH_MASTER_FILES as $name) {
            $path = AUTH_MASTER_DIR . '/' . $name;
            $files[] = [
                'file'   => $name,
                'kind'   => 'html',
                'label'  => AUTH_MASTER_LABEL[$name] ?? $name,
                'size'   => is_file($path) ? filesize($path) : 0,
                'exists' => is_file($path),
            ];
        }
        json_out(['success' => true, 'files' => $files]);
    }

    if ($action === 'content') {
        $file = (string) ($_GET['file'] ?? '');
        // ホワイトリスト完全一致のみ(パストラバーサル防御)
        if (in_array($file, CAMPAIGN_DATA_FILES, true)) {
            if ($dataDir === '') {
                json_error('このキャンペーンにはまだデータがありません', 404);
            }
            $path = $dataDir . '/' . $file;
            $type = 'csv';
        } elseif (in_array($file, AUTH_MASTER_FILES, true)) {
            $path = AUTH_MASTER_DIR . '/' . $file;
            $type = 'html';
        } else {
            json_error('アクセスが拒否されました', 403);
        }

        if (!is_file($path)) {
            json_error('ファイルが見つかりません', 404);
        }

        if ($type === 'csv') {
            $rows = [];
            $handle = fopen($path, 'r');
            if ($handle !== false) {
                $headers = fgetcsv($handle);
                if ($headers !== false) {
                    while (($row = fgetcsv($handle)) !== false) {
                        // 列数不一致に耐える(不足は空、超過は切り詰め)
                        $row = array_pad(array_slice($row, 0, count($headers)), count($headers), '');
                        $rows[] = array_combine($headers, $row);
                    }
                }
                fclose($handle);
            }
            json_out(['success' => true, 'type' => 'csv', 'headers' => $headers ?: [], 'data' => $rows]);
        }

        // html: 生ソースを返す(表示側で esc する)
        $content = file_get_contents($path);
        json_out(['success' => true, 'type' => 'html', 'content' => $content === false ? '' : $content]);
    }

    json_error('不正なアクションです', 400);
} catch (Throwable $e) {
    error_log($e->getMessage());
    json_error('サーバエラー', 500);
}
