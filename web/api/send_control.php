<?php
/**
 * メール送信制御パネル API（送信ステータス + アラート）。
 *
 * 送信ワーカー(send_email.py)が各キャンペーンの data_dir 配下に書き出す JSON を集約する:
 *   - {data_dir}/send_status.json : そのキャンペーンの送信進捗
 *   - {data_dir}/send_alerts.json : そのキャンペーンの配信遅延/失敗アラート
 * 並列送信(複数キャンペーン同時)に対応するため、キャンペーン別ファイルを集約して返す。
 * 後方互換で /opt/training/bin/data/send_{status,alerts}.json(グローバル)も参照する。
 *
 * アクション:
 *   status(GET)        : 実行中/直近キャンペーンの送信ステータス配列。viewer 以上。
 *   alerts(GET)        : 全キャンペーンのアラート最新20件(新しい順)。viewer 以上。
 *   clear_alerts(POST) : アラート履歴を削除。operator 以上、CSRF 必須。
 */
declare(strict_types=1);
require __DIR__ . '/../lib/bootstrap.php';

const SEND_GLOBAL_STATUS = '/opt/training/bin/data/send_status.json';
const SEND_GLOBAL_ALERTS = '/opt/training/bin/data/send_alerts.json';

/** 実行中/直近のキャンペーン(id, name, data_dir, status)を返す。テナントで絞る。 */
function send_control_campaigns(int $tenantId): array
{
    // 実行中(running/scheduled/paused)＋直近で送信した done を対象にする。
    return Db::all(
        "SELECT id, name, data_dir, status FROM campaigns
         WHERE tenant_id = ? AND deleted_at IS NULL
           AND data_dir IS NOT NULL AND data_dir != ''
           AND status IN ('running','scheduled','paused','done')
         ORDER BY (status IN ('running','scheduled','paused')) DESC, id DESC
         LIMIT 30",
        [$tenantId]
    );
}

/** JSON ファイルを配列で読む。無効/不在なら null。 */
function send_control_read_json(string $path): mixed
{
    if (!is_file($path)) {
        return null;
    }
    $data = json_decode((string) file_get_contents($path), true);
    return is_array($data) ? $data : null;
}

try {
    $actor  = require_role($_SERVER['REQUEST_METHOD'] === 'GET' ? 'viewer' : 'operator');
    $action = $_GET['action'] ?? '';
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $tenantId = effective_tenant_id($actor, isset($_GET['tenant_id']) ? (int) $_GET['tenant_id'] : null);

    if ($action === 'status' && $method === 'GET') {
        $campaigns = send_control_campaigns($tenantId);
        $statuses = [];
        foreach ($campaigns as $c) {
            $dir = rtrim((string) $c['data_dir'], '/');
            $st = send_control_read_json($dir . '/send_status.json');
            if ($st === null) {
                continue; // まだ送信していない(status.jsonが無い)
            }
            $flag = is_file($dir . '/stop_sending.flag');
            $statuses[] = [
                'campaign_id'   => (int) $c['id'],
                'campaign_name' => (string) $c['name'],
                'campaign_status' => (string) $c['status'],
                'status'        => (string) ($st['status'] ?? 'idle'),
                'timestamp'     => (string) ($st['timestamp'] ?? ''),
                'processed'     => (int) ($st['processed'] ?? 0),
                'total'         => (int) ($st['total'] ?? 0),
                'success'       => (int) ($st['success'] ?? 0),
                'error'         => (int) ($st['error'] ?? 0),
                'current_email' => (string) ($st['current_email'] ?? ''),
                'is_stopped'    => $flag,
            ];
        }
        // 実行中(running系)を優先し、次に更新時刻の新しい順。
        usort($statuses, static function ($a, $b) {
            $ar = in_array($a['campaign_status'], ['running', 'scheduled', 'paused'], true) ? 1 : 0;
            $br = in_array($b['campaign_status'], ['running', 'scheduled', 'paused'], true) ? 1 : 0;
            if ($ar !== $br) {
                return $br <=> $ar;
            }
            return strcmp((string) $b['timestamp'], (string) $a['timestamp']);
        });
        $running = array_values(array_filter($statuses, static fn ($s) => in_array($s['campaign_status'], ['running', 'scheduled', 'paused'], true)));
        json_out([
            'success'       => true,
            'active_count'  => count($running),
            'statuses'      => $statuses,
        ]);
    }

    if ($action === 'alerts' && $method === 'GET') {
        $campaigns = send_control_campaigns($tenantId);
        $all = [];
        foreach ($campaigns as $c) {
            $dir = rtrim((string) $c['data_dir'], '/');
            $alerts = send_control_read_json($dir . '/send_alerts.json');
            if ($alerts === null) {
                continue;
            }
            foreach ($alerts as $a) {
                if (!is_array($a)) {
                    continue;
                }
                $a['campaign_id']   = (int) $c['id'];
                $a['campaign_name'] = (string) $c['name'];
                $all[] = $a;
            }
        }
        // timestamp 降順(新しい順)で最新20件。
        usort($all, static fn ($x, $y) => strcmp((string) ($y['timestamp'] ?? ''), (string) ($x['timestamp'] ?? '')));
        $all = array_slice($all, 0, 20);
        json_out(['success' => true, 'data' => $all]);
    }

    if ($action === 'clear_alerts' && $method === 'POST') {
        tet2_require_csrf();
        // 対象キャンペーンの data_dir 配下 send_alerts.json を削除。
        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        $body = json_body();
        if ($id === 0 && isset($body['id']) && is_int($body['id'])) {
            $id = $body['id'];
        }
        if ($id > 0) {
            $c = assert_campaign_owned($id, $tenantId);
            $dir = rtrim((string) ($c['data_dir'] ?? ''), '/');
            if ($dir !== '' && is_file($dir . '/send_alerts.json')) {
                @unlink($dir . '/send_alerts.json');
            }
            audit('send_control.clear_alerts', 'campaign_id=' . $id);
        } else {
            // id 未指定: このテナントの全キャンペーン分 + グローバルをクリア。
            foreach (send_control_campaigns($tenantId) as $c) {
                $dir = rtrim((string) $c['data_dir'], '/');
                if ($dir !== '' && is_file($dir . '/send_alerts.json')) {
                    @unlink($dir . '/send_alerts.json');
                }
            }
            audit('send_control.clear_alerts', 'all');
        }
        json_out(['success' => true, 'message' => 'アラートをクリアしました']);
    }

    json_error('不正なアクションです', 400);
} catch (Throwable $e) {
    error_log($e->getMessage());
    json_error('サーバエラー', 500);
}
