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

        // create_beacon_files.py は training ユーザーで実行する。
        // send_email.py(worker=training)と共有する operation.log の所有者を training に
        // 一本化し、www-data 実行時に training が書けなくなる権限競合を根絶するため。
        // (2026-08 に operation.log が www-data:644 で作られ、直後の send_email(training)が
        //  PermissionError で全滅した事故の恒久対策。sudoers に (training) NOPASSWD 定義済み。)
        $command = '/usr/bin/sudo -n -u training '
            . escapeshellcmd('/usr/bin/python3') . ' '
            . escapeshellarg('/opt/training/bin/create_beacon_files.py')
            . ' --data-dir ' . escapeshellarg($dataDir) . ' 2>&1';
        exec($command, $output, $returnCode);
        if ($returnCode !== 0) {
            $details = implode(' / ', array_slice($output, -3));
            return [false, "ビーコン生成(rc={$returnCode}): {$details}"];
        }
        return [true, ''];
    }
}
