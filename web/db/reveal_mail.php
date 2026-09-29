<?php
/**
 * 種明かしメール(段D の D2)と担当者への報告の通知(D3)を送る CLI バッチ。
 * timer(deploy/systemd/tet2-reveal-mail.timer)から呼ぶ想定。timer は配備では有効にならない(有効化は利用者の承認が要る)。
 *
 *   php db/reveal_mail.php
 *   TET2_DB_PATH=/abs/path/copy.sqlite TET2_MAIL_OUTBOX_DIR=/abs/outbox php db/reveal_mail.php   # 隔離DBで、投函せずに確かめる
 *
 * どの送信も既定で切。訓練ごとの種明かしメールの設定、テナントの通知先がなければ1通も送らない。
 * 冪等: 1人に1つの条件で1通(notification_sends)。何度動かしても同じ人へ2通は送らない。
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib/RevealMail.php';
require_once __DIR__ . '/../lib/ReportNotify.php';

try {
    $reveal = RevealMail::run();
    $notify = ReportNotify::run();
    fwrite(STDOUT, sprintf(
        "[%s] 種明かし: 失敗の直後 %d / 終了後 %d / 報告した人 %d / 失敗 %d / 次回へ %d ・ 報告の通知: 送信 %d / 失敗 %d / 上限で次回へ %d\n",
        date('Y-m-d H:i:s'),
        $reveal[RevealMail::KIND_FAILED], $reveal[RevealMail::KIND_CLOSED], $reveal[RevealMail::KIND_REPORTED],
        $reveal['failed'], $reveal['remaining'],
        $notify['sent'], $notify['failed'], $notify['deferred']
    ));
    exit($reveal['failed'] + $notify['failed'] > 0 ? 1 : 0);
} catch (\Throwable $e) {
    fwrite(STDERR, '種明かしメールと報告の通知に失敗: ' . $e->getMessage() . "\n");
    exit(1);
}
