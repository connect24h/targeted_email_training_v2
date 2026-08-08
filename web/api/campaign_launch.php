<?php
/**
 * キャンペーンの送信開始・停止 API。
 * launch: draft を scheduled にし、send_schedule を展開する（実送信はワーカーが行う）。
 * stop  : 緊急停止。send_schedule の未完バッチを cancelled にし、停止フラグを立てる。
 */
declare(strict_types=1);
require __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/Scheduler.php';
require_once __DIR__ . '/../lib/PipelineRunner.php';

/**
 * launch 時の前処理: DB→CSV 生成 + ビーコン/リンク/添付生成を1回だけ行う。
 * 成功なら [true, '']、失敗なら [false, エラー文字列] を返す。
 * 生成物は data_dir 配下(CSV)と docroot(link/beacon)・Attachment に作られる。
 * 送信ワーカーは以後これを再生成せず、送信のみ行う。
 */
function campaign_launch_pregenerate(int $campaignId, string $dataDir): array
{
    try {
        // 前処理1: DB→CSV 生成(PHP から直接呼ぶ)。
        PipelineRunner::generateCsv($campaignId);
    } catch (Throwable $e) {
        return [false, 'CSV生成: ' . $e->getMessage()];
    }
    if ($dataDir === '') {
        return [false, 'data_dir 未設定'];
    }
    // 前処理2: ビーコン/リンク/添付生成(Python スクリプトを exec)。
    $cmd = escapeshellcmd('/usr/bin/python3') . ' ' . escapeshellarg('/opt/training/bin/create_beacon_files.py')
         . ' --data-dir ' . escapeshellarg($dataDir) . ' 2>&1';
    exec($cmd, $out, $rc);
    if ($rc !== 0) {
        return [false, 'ビーコン生成(rc=' . $rc . '): ' . implode(' / ', array_slice($out, -3))];
    }
    return [true, ''];
}

try {
    $actor  = require_role('operator');
    $action = $_GET['action'] ?? '';
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method !== 'POST') {
        json_error('不正なアクションです', 400);
    }
    tet2_require_csrf();

    $body       = json_body();
    $campaignId = isset($body['id']) && is_int($body['id']) ? $body['id'] : 0;
    if ($campaignId < 1) {
        json_error('id が不正です', 400);
    }
    $tenantId = effective_tenant_id($actor, isset($body['tenant_id']) && is_int($body['tenant_id']) ? $body['tenant_id'] : null);
    $campaign = assert_campaign_owned($campaignId, $tenantId);

    if ($action === 'launch') {
        if (!in_array($campaign['status'], ['draft', 'scheduled'], true)) {
            json_error('このキャンペーンは開始できません（status=' . $campaign['status'] . '）', 409);
        }
        // 送信日時が未設定だと Scheduler が例外→500 になるため、事前に分かりやすく弾く。
        // 複製直後は start_at/end_at が空(過去日時の引き継ぎを避ける設計)なので、ここで promotion を促す。
        if (trim((string) ($campaign['start_at'] ?? '')) === '' || trim((string) ($campaign['end_at'] ?? '')) === '') {
            json_error('送信日時（開始・終了）が未設定です。「修正」から開始日時・終了日時を設定してください', 400);
        }
        // テスト送信でない本番キャンペーンは、ここでは追加の承認を要する運用も可能（今回は is_test を尊重）。
        $count = Scheduler::expand($campaignId);
        if ($count === 0) {
            json_error('対象者がいません', 400);
        }
        // 前処理をこの launch 時点で1回だけ実行する(CSV・ビーコン・リンク・添付を生成)。
        // 開始日時が来たら worker は送信のみ行う。前処理失敗はここで即座にユーザーへ返す(早期発見)。
        // launch 後に対象者/テンプレを変えた場合は、下書きに戻して再launchで作り直す(手動反映)。
        [$genOk, $genErr] = campaign_launch_pregenerate($campaignId, (string) ($campaign['data_dir'] ?? ''));
        if (!$genOk) {
            json_error('送信データの生成に失敗しました: ' . $genErr, 500);
        }
        Db::run("UPDATE campaigns SET status='scheduled' WHERE id=?", [$campaignId]);
        // 停止フラグが残っていれば解除
        $flag = rtrim((string) $campaign['data_dir'], '/') . '/stop_sending.flag';
        if ($flag !== '/stop_sending.flag' && is_file($flag)) {
            @unlink($flag);
        }
        audit('campaign.launch', 'campaign_id=' . $campaignId . ',batches=' . $count);
        json_out(['success' => true, 'batches' => $count, 'status' => 'scheduled']);
    }

    if ($action === 'stop') {
        // 未実行バッチを cancelled、キャンペーンを paused。
        // 停止フラグを data_dir に置き、ワーカーが次バッチ起動前と起動判定でチェックする。
        Db::run("UPDATE send_schedule SET status='cancelled' WHERE campaign_id=? AND status IN ('queued')", [$campaignId]);
        Db::run("UPDATE campaigns SET status='paused' WHERE id=?", [$campaignId]);
        $dir = (string) $campaign['data_dir'];
        if ($dir !== '' && is_dir($dir)) {
            @file_put_contents($dir . '/stop_sending.flag', "stopped by UI\n");
        }
        audit('campaign.stop', 'campaign_id=' . $campaignId);
        json_out(['success' => true, 'status' => 'paused']);
    }

    if ($action === 'resume') {
        // 停止点から再開: paused のキャンペーンで、停止フラグを消し、
        // 未送信(send_status!='sent')の koban だけを send_schedule に再展開して scheduled に戻す。
        // 送信済みは再送しない(停止点からの再開=v1 の list.csv 送信フラグ思想と同じ)。
        if ((string) $campaign['status'] !== 'paused') {
            json_error('一時停止中のキャンペーンのみ再開できます（status=' . $campaign['status'] . '）', 409);
        }
        // 停止フラグ解除(ワーカーが次バッチを起動できるように)
        $dir = (string) $campaign['data_dir'];
        if ($dir !== '' && is_dir($dir)) {
            $flag = rtrim($dir, '/') . '/stop_sending.flag';
            if (is_file($flag)) {
                @unlink($flag);
            }
        }
        // 未送信 koban を再展開。Scheduler::expand は send_status='sent' を除外して未送信のみを
        // バッチ化する(停止点からの再開)。running/claimed の実行中バッチは触らない。
        $count = Scheduler::expandRemaining($campaignId);
        Db::run("UPDATE campaigns SET status='scheduled' WHERE id=?", [$campaignId]);
        audit('campaign.resume', 'campaign_id=' . $campaignId . ',batches=' . $count);
        json_out(['success' => true, 'status' => 'scheduled', 'batches' => $count]);
    }

    if ($action === 'to_draft') {
        // 実行前(scheduled)・停止中(paused)のキャンペーンを draft に戻す(編集可能にする)。
        // send_schedule の未実行バッチを取り消し、停止フラグを消し、status=draft にする。
        // 送信済み(campaign_targets.send_status='sent')はそのまま残る(履歴保持)。
        if (!in_array((string) $campaign['status'], ['scheduled', 'paused'], true)) {
            json_error('予約・一時停止中のキャンペーンのみ下書きに戻せます（status=' . $campaign['status'] . '）', 409);
        }
        Db::run("UPDATE send_schedule SET status='cancelled' WHERE campaign_id=? AND status IN ('queued')", [$campaignId]);
        $dir = (string) $campaign['data_dir'];
        if ($dir !== '' && is_dir($dir)) {
            $flag = rtrim($dir, '/') . '/stop_sending.flag';
            if (is_file($flag)) {
                @unlink($flag);
            }
        }
        Db::run("UPDATE campaigns SET status='draft' WHERE id=?", [$campaignId]);
        audit('campaign.to_draft', 'campaign_id=' . $campaignId);
        json_out(['success' => true, 'status' => 'draft']);
    }

    if ($action === 'progress') {
        // 送信進捗: campaign_targets の send_status 集計 + send_schedule のバッチ状態 +
        // 停止フラグの有無 + 再開項番(最小の未送信 koban)。UI のステータス表示/ポーリング用。
        $counts = Db::one(
            "SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN send_status='sent' THEN 1 ELSE 0 END) AS sent,
                SUM(CASE WHEN send_status!='sent' THEN 1 ELSE 0 END) AS pending
             FROM campaign_targets WHERE campaign_id=?",
            [$campaignId]
        ) ?? ['total' => 0, 'sent' => 0, 'pending' => 0];
        $total   = (int) ($counts['total'] ?? 0);
        $sent    = (int) ($counts['sent'] ?? 0);
        $pending = (int) ($counts['pending'] ?? 0);

        $resumeRow = Db::one(
            "SELECT MIN(koban) AS koban FROM campaign_targets WHERE campaign_id=? AND send_status!='sent'",
            [$campaignId]
        );
        $resumeKoban = $resumeRow !== null && $resumeRow['koban'] !== null ? (int) $resumeRow['koban'] : null;

        $batches = Db::all(
            "SELECT status, COUNT(*) AS n FROM send_schedule WHERE campaign_id=? GROUP BY status",
            [$campaignId]
        );
        $batchStatus = [];
        foreach ($batches as $b) {
            $batchStatus[(string) $b['status']] = (int) $b['n'];
        }

        $dir = (string) $campaign['data_dir'];
        $stopped = $dir !== '' && is_file(rtrim($dir, '/') . '/stop_sending.flag');

        json_out([
            'success'       => true,
            'campaign_status' => (string) $campaign['status'],
            'total'         => $total,
            'sent'          => $sent,
            'pending'       => $pending,
            'progress_rate' => $total > 0 ? round($sent * 100 / $total, 1) : 0.0,
            'resume_koban'  => $resumeKoban,
            'is_stopped'    => $stopped,
            'batches'       => $batchStatus,
        ]);
    }

    json_error('不正なアクションです', 400);
} catch (Throwable $e) {
    error_log($e->getMessage());
    json_error('サーバエラー', 500);
}
