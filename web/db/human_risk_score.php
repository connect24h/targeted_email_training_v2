<?php
/**
 * 個人リスクスコア(Human Risk Score)を日次算出する CLI バッチ。
 * systemd timer(tet2-edu-snapshot.timer)から呼ぶ想定(無人)。
 *
 *   php db/human_risk_score.php
 *   php db/human_risk_score.php 2026-08-16                        # 日付指定(バックフィル/再現検証用)
 *   TET2_DB_PATH=/tmp/xxx.sqlite php db/human_risk_score.php      # 隔離DB検証用
 *
 * 冪等: 同一 (tenant_id, target_id, computed_date) は UPSERT で上書きするので二重に積まない。
 *
 * 帯の分布を必ず出力する。high が過半数になっているようなら重み設計が誤っており、
 * スコアが介入の判断材料として機能していないことを意味する。
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib/HumanRiskScore.php';

$date = null;
if (isset($argv[1]) && $argv[1] !== '') {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $argv[1])) {
        fwrite(STDERR, "日付は YYYY-MM-DD 形式で指定してください: {$argv[1]}\n");
        exit(1);
    }
    $date = $argv[1];
}

try {
    $result = HumanRiskScore::run($date);
    fwrite(STDOUT, sprintf(
        "リスクスコア %s: %d 名 (high %d / medium %d / low %d)\n",
        $result['date'],
        $result['scored'],
        $result['bands']['high'],
        $result['bands']['medium'],
        $result['bands']['low']
    ));
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, 'リスクスコア算出に失敗: ' . $e->getMessage() . "\n");
    exit(1);
}
