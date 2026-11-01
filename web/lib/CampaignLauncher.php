<?php
declare(strict_types=1);

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/PipelineRunner.php';
require_once __DIR__ . '/Scheduler.php';

final class CampaignLauncher
{
    /**
     * 生成物を完成・検証してから送信scheduleを公開する。
     *
     * @param null|callable(int,string):array{0:bool,1:string} $generator
     * @return array{0:bool,1:string,2:int}
     */
    public static function prepare(int $campaignId, string $dataDir, ?callable $generator = null): array
    {
        $target = Db::one(
            'SELECT COUNT(*) AS count FROM campaign_targets WHERE campaign_id = ?',
            [$campaignId]
        );
        if ((int) ($target['count'] ?? 0) === 0) {
            return [false, '対象者がいません', 0];
        }

        $generate = $generator ?? self::pregenerate(...);
        [$generated, $error] = $generate($campaignId, $dataDir);
        if (!$generated) {
            return [false, $error, 0];
        }

        // 生成済みCSVの宛先を、scheduleを公開する前に検証する。
        // 生成時のリダイレクトだけでは、生成後にCSVが差し替わった場合や
        // 古い本番CSVが残っていた場合に本番アドレスへ送られてしまう。
        [$safeOk, $safeErr] = self::assertTestRecipientsOnly($campaignId, $dataDir);
        if (!$safeOk) {
            return [false, $safeErr, 0];
        }

        try {
            $batches = Scheduler::expand($campaignId);
        } catch (Throwable $e) {
            return [false, 'スケジュール生成: ' . $e->getMessage(), 0];
        }
        if ($batches === 0) {
            return [false, '対象者がいません', 0];
        }
        return [true, '', $batches];
    }

    /**
     * 再開(resume)など prepare を経由しない経路から宛先検証だけを行うための入口。
     *
     * @return array{0:bool,1:string}
     */
    public static function assertTestRecipientsSafe(int $campaignId, string $dataDir): array
    {
        return self::assertTestRecipientsOnly($campaignId, $dataDir);
    }

    /**
     * is_test=1 のとき、生成済み list.csv の宛先が test_redirect_emails だけであることを保証する。
     *
     * 1件でも本番アドレスが混ざっていれば schedule を公開せず中止する。
     * 生成時のリダイレクトとは独立した最終関門で、送信直前の実ファイルを見る。
     * 送信scriptは is_test を見ずCSVの「送信先情報」列をそのまま使うため、
     * ここを通さないと本番アドレスへの誤送信を止められない。
     *
     * @return array{0:bool,1:string}
     */
    private static function assertTestRecipientsOnly(int $campaignId, string $dataDir): array
    {
        $campaign = Db::one(
            'SELECT is_test, test_redirect_emails FROM campaigns WHERE id = ?',
            [$campaignId]
        );
        if ($campaign === null || (int) ($campaign['is_test'] ?? 0) !== 1) {
            return [true, ''];  // 本番送信はここでは判定しない
        }

        $allowed = [];
        foreach (preg_split('/[,\s]+/', (string) ($campaign['test_redirect_emails'] ?? '')) as $email) {
            $email = strtolower(trim($email));
            if ($email !== '') {
                $allowed[$email] = true;
            }
        }
        if ($allowed === []) {
            return [false, 'テスト送信ですがテスト宛先が未設定です'];
        }

        $csvPath = rtrim($dataDir, '/') . '/list.csv';
        if (!is_file($csvPath)) {
            return [false, '送信データ(list.csv)が見つかりません'];
        }
        $handle = fopen($csvPath, 'r');
        if ($handle === false) {
            return [false, '送信データ(list.csv)を読み取れません'];
        }

        try {
            $header = fgetcsv($handle);
            if ($header === false) {
                return [false, '送信データ(list.csv)が空です'];
            }
            $toIndex = array_search('送信先情報', $header, true);
            if ($toIndex === false) {
                return [false, '送信データに「送信先情報」列がありません'];
            }

            $line = 1;
            while (($row = fgetcsv($handle)) !== false) {
                $line++;
                if ($row === [null] || $row === []) {
                    continue;  // 空行
                }
                $to = strtolower(trim((string) ($row[$toIndex] ?? '')));
                if ($to === '') {
                    return [false, "送信データ{$line}行目の宛先が空です"];
                }
                if (!isset($allowed[$to])) {
                    // 混入したアドレスは晒さない。行番号だけ返して調査の起点にする
                    return [
                        false,
                        "テスト送信ですが{$line}行目にテスト宛先以外が含まれるため中止しました",
                    ];
                }
            }
        } finally {
            fclose($handle);
        }

        return [true, ''];
    }

    /** @return array{0:bool,1:string} */
    private static function pregenerate(int $campaignId, string $dataDir): array
    {
        try {
            PipelineRunner::generateCsv($campaignId);
        } catch (Throwable $e) {
            return [false, 'CSV生成: ' . $e->getMessage()];
        }
        if ($dataDir === '') {
            return [false, 'data_dir 未設定'];
        }

        // QR/link-*.html のURLベースは、キャンペーンの beacon_base(DB)を最優先で使う。
        // create_beacon_files.py は放置すると config.ini 固定で読むため、UI/DBで
        // filesend.cojp.online に切り替えても QR だけ古い http://85.131.251.224 のまま
        // になる不整合があった。PHP と同じ PipelineRunner::beaconUrlBase() で解決して
        // --beacon-base で渡し、メール本文リンクと QR のURLを一致させる。
        $campaign = Db::one('SELECT beacon_base FROM campaigns WHERE id = ?', [$campaignId]);
        $beaconBase = PipelineRunner::beaconUrlBase($campaign ?: null);

        // ビーコン/QR/添付の生成は training ユーザーで走らせる(send_email.py と operation.log の
        // 所有を training に一本化するため)。Web(www-data)はキューに依頼を置くだけにし、
        // systemd(tet2-beacon.path/.service)が training として生成する。Web は sudo を使わない
        // (2026-10-21 の apache2 更新で apache2.service から /etc/sudoers が見えなくなり、
        //  従来の `sudo -u training` が失敗したため。tet2-beacon-runner.py がキューを処理する)。
        return self::generateBeaconFiles($campaignId, $beaconBase);
    }

    /**
     * ビーコン生成をキュー経由で training サービスに依頼し、結果を待つ。
     * data_dir(パス)は送らない。training 側が campaign_id で DB から正規パスを引くため
     * (www-data が書くパスを training が信用しない=パス詐称を不可能にする)。
     * @return array{0:bool,1:string}
     */
    private static function generateBeaconFiles(int $campaignId, string $beaconBase): array
    {
        // 既定は本番の固定パス。テストだけ TET2_BEACON_QUEUE_DIR で隔離する。
        $queueDir = getenv('TET2_BEACON_QUEUE_DIR') ?: '/opt/training/tet2-data/_beacon_queue';
        try {
            PipelineRunner::ensureDir($queueDir);
        } catch (Throwable $e) {
            return [false, 'ビーコン生成キューを準備できません: ' . $e->getMessage()];
        }
        $reqId = date('Ymd-His') . '-' . bin2hex(random_bytes(6));
        $reqPath = $queueDir . '/' . $reqId . '.req';
        $resPath = $queueDir . '/' . $reqId . '.res';
        $tmpPath = $queueDir . '/' . $reqId . '.tmp';
        $payload = json_encode(['campaign_id' => $campaignId, 'beacon_base' => $beaconBase],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (file_put_contents($tmpPath, $payload) === false || !@rename($tmpPath, $reqPath)) {
            @unlink($tmpPath);
            return [false, 'ビーコン生成の依頼を書き込めません'];
        }

        // tet2-beacon.path が .req を検知して tet2-beacon.service を起動する。結果(.res)を待つ。
        // UI の launch timeout は 180 秒。その手前(170 秒)で見切り、生成中であることを伝える。
        // (TET2_BEACON_TIMEOUT はテストで待ち時間を縮めるための seam。本番は未設定=170 秒。)
        $timeout = (float) (getenv('TET2_BEACON_TIMEOUT') ?: 170.0);
        $deadline = microtime(true) + $timeout;
        while (microtime(true) < $deadline) {
            if (is_file($resPath)) {
                $raw = (string) file_get_contents($resPath);
                @unlink($resPath);
                $result = json_decode($raw, true);
                if (!is_array($result) || !isset($result['rc'])) {
                    return [false, 'ビーコン生成の結果を解釈できません'];
                }
                if ((int) $result['rc'] !== 0) {
                    $details = implode(' / ', array_slice((array) ($result['output'] ?? []), -3));
                    return [false, "ビーコン生成(rc={$result['rc']}): {$details}"];
                }
                return [true, ''];
            }
            usleep(200000); // 0.2 秒
        }
        @unlink($reqPath);
        return [false, 'ビーコン生成がタイムアウトしました(生成サービス tet2-beacon が動作しているか確認してください)'];
    }
}
