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

        $command = escapeshellcmd('/usr/bin/python3') . ' '
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
