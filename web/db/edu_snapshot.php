<?php
/**
 * 教育スコアの経年スナップショットを日次生成する CLI バッチ。
 * cron/systemd timer から呼ぶ想定(無人)。
 *
 *   php db/edu_snapshot.php
 *   php db/edu_snapshot.php 2026-07-18                        # 日付指定(バックフィル/再現検証用)
 *   TET2_DB_PATH=/tmp/xxx.sqlite php db/edu_snapshot.php      # 隔離DB検証用
 *
 * 冪等: 同一 (tenant_id, snapshot_type, group department, snapshot_date) は二重に積まない。
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib/EduSnapshot.php';

$date = null;
if (isset($argv[1]) && $argv[1] !== '') {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $argv[1])) {
        fwrite(STDERR, "日付は YYYY-MM-DD 形式で指定してください: {$argv[1]}\n");
        exit(1);
    }
    $date = $argv[1];
}

try {
    $result = EduSnapshot::run($date);
    fwrite(STDOUT, sprintf(
        "スナップショット: テナント %d / 新規 %d 行 / スキップ %d 行 (既存)\n",
        $result['tenants'],
        $result['inserted'],
        $result['skipped']
    ));
    foreach ($result['details'] as $d) {
        if ($d['inserted'] > 0) {
            fwrite(STDOUT, sprintf("  tenant #%d: +%d\n", $d['tenant_id'], $d['inserted']));
        }
    }
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, 'スナップショット生成に失敗: ' . $e->getMessage() . "\n");
    exit(1);
}
