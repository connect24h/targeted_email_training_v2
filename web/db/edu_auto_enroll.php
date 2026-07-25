<?php
/**
 * 訓練→教育の自動連携を定期実行する CLI バッチ。
 * cron/systemd timer から呼ぶ想定(UI の ingest 経路とは別に、無人で回す)。
 *
 *   php db/edu_auto_enroll.php
 *   TET2_DB_PATH=/tmp/xxx.sqlite php db/edu_auto_enroll.php   # 隔離DB検証用
 *
 * ingest(ログ取込)自体はここでは行わない。events は UI の ingest か別バッチで取り込まれている前提で、
 * トリガー配信(triggered_by='phishing_failure', status='running')へ失敗者を自動投入する。
 * 冪等: 何度実行しても既存割当は二重投入されない。
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib/EduAutoEnroll.php';

try {
    $result = EduAutoEnroll::run();
    fwrite(STDOUT, sprintf(
        "自動連携: 対象配信 %d 件 / 新規割当 %d 件\n",
        $result['deliveries'],
        $result['assigned']
    ));
    foreach ($result['details'] as $d) {
        if ($d['assigned'] > 0) {
            fwrite(STDOUT, sprintf("  delivery #%d: +%d\n", $d['delivery_id'], $d['assigned']));
        }
    }
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, '自動連携に失敗: ' . $e->getMessage() . "\n");
    exit(1);
}
