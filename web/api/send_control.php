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

/**
 * キャンペーンの送信件数(total/sent)を返す。
 *
 * total は常に DB campaign_targets の件数(送信中に変わらないので食い違わない)。
 *
 * sent の源は2段階:
 *  1. リアルタイム(推奨): $dataDir/list.csv の「送信フラグ」列が 1 の行数。
 *     send_email.py が1件送るたびに update_send_flag() で立てるため、送信中も
 *     数字が動く(2026-08-21: 送信中に画面が固まる問題の解消)。
 *  2. フォールバック: list.csv が無い/列が無い/$dataDir 未指定なら DB の
 *     send_status='sent' 集計。完了後・旧データ・data_dir 不明時に累計を保証。
 *
 * DB send_status は worker が finish_batch() でバッチ完了時に一括同期する
 * 「確定台帳」。送信中は遅れるため、リアルタイム表示は list.csv を正とする。
 * send_status.json はプロセス単位カウンタで累計とズレる(945問題)ため件数には使わない。
 *
 * @return array{total:int, sent:int}
 */
function send_control_counts(int $campaignId, ?string $dataDir = null): array
{
    $counts = Db::one(
        "SELECT COUNT(*) AS total,
                SUM(CASE WHEN send_status='sent' THEN 1 ELSE 0 END) AS sent
         FROM campaign_targets WHERE campaign_id=?",
        [$campaignId]
    ) ?? ['total' => 0, 'sent' => 0];
    $total = (int) ($counts['total'] ?? 0);

    $realtimeSent = $dataDir !== null ? send_control_list_csv_sent($dataDir) : null;
    $sent = $realtimeSent ?? (int) ($counts['sent'] ?? 0);

    return ['total' => $total, 'sent' => $sent];
}

/**
 * list.csv の「送信フラグ」列が立っている行数を数える。
 * 読めない/列が無い場合は null を返し、呼び出し側で DB フォールバックさせる。
 * 送信フラグは ASCII の "1"/"1.0" なので文字コードに依存せず数えられる。
 */
function send_control_list_csv_sent(string $dataDir): ?int
{
    $path = rtrim($dataDir, '/') . '/list.csv';
    if (!is_file($path) || !is_readable($path)) {
        return null;
    }
    $fh = @fopen($path, 'r');
    if ($fh === false) {
        return null;
    }
    try {
        $header = fgetcsv($fh);
        if ($header === false || $header === null) {
            return null;
        }
        $flagIdx = array_search('送信フラグ', $header, true);
        if ($flagIdx === false) {
            return null; // 列が無い → フォールバック
        }
        $sent = 0;
        while (($row = fgetcsv($fh)) !== false) {
            if (!isset($row[$flagIdx])) {
                continue;
            }
            $flag = trim((string) $row[$flagIdx]);
            if ($flag === '1' || $flag === '1.0') {
                $sent++;
            }
        }
        return $sent;
    } finally {
        fclose($fh);
    }
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
            // total は DB、sent は list.csv 起点のリアルタイム(理由は send_control_counts 参照)。
            $counts = send_control_counts((int) $c['id'], $dir);
            $total = $counts['total'];
            $sent  = $counts['sent'];
            $statuses[] = [
                'campaign_id'   => (int) $c['id'],
                'campaign_name' => (string) $c['name'],
                'campaign_status' => (string) $c['status'],
                // status/current_email/timestamp はリアルタイム情報のため json 由来を残す。
                'status'        => (string) ($st['status'] ?? 'idle'),
                'timestamp'     => (string) ($st['timestamp'] ?? ''),
                'processed'     => $sent,
                'total'         => $total,
                'success'       => $sent,
                // error は DB に記録がない(send_status は sent/pending のみ)ため
                // ワーカーが書く json のカウンタを残す。
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
