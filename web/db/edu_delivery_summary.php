<?php
/**
 * 受講期間の終了時の集計通知(段D の D5、G63)を1回動かす CLI。
 * 期限を過ぎた配信の受講率と合格率を、教育配信の画面で有効にした組織の担当者へ送り、結果を1行で出す。
 * 冪等(配信1件につき1回だけ送る)。有効にした組織がなければ1通も送らない。
 *
 *   php db/edu_delivery_summary.php
 *   TET2_DB_PATH=/abs/path/copy.sqlite TET2_EDU_MAIL_DISABLE=1 php db/edu_delivery_summary.php   # 隔離DBで確かめる
 *
 * timer(deploy/systemd/tet2-edu-summary.timer)の有効化は、利用者の承認を得てから別に行う。
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../lib/EduDeliverySummary.php';

try {
    fwrite(STDOUT, EduDeliverySummary::summaryLine(EduDeliverySummary::run()) . "\n");
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, 'edu_delivery_summary: 失敗 ' . $e->getMessage() . "\n");
    exit(1);
}
