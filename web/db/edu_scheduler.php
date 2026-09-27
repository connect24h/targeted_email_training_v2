<?php
/**
 * 教育の配信の自動の処理を1回動かす CLI。予約した配信の開始、毎月の配信の作成、
 * 新入社員への出題を順に行い、結果を1行で出す。冪等(何度動かしても二重にならない)。
 *
 *   php db/edu_scheduler.php
 *   TET2_DB_PATH=/abs/path/copy.sqlite php db/edu_scheduler.php   # 隔離DBで確かめる
 *
 * 受講の案内メールは、配信で「案内メールを送る」(send_invites=1)を選んだときだけ送る。
 * timer(deploy/systemd/tet2-edu-scheduler.timer)の有効化は、利用者の承認を得てから別に行う。
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../lib/EduScheduler.php';

try {
    fwrite(STDOUT, EduScheduler::summaryLine(EduScheduler::run()) . "\n");
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, 'edu_scheduler: 失敗 ' . $e->getMessage() . "\n");
    exit(1);
}
