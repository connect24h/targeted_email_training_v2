<?php
declare(strict_types=1);
/**
 * 訓練メールの送達の状態と返信を取り込む CLI(段B1、G04 と G26)。冪等で、何度流しても同じ。
 *  1. postfix の mail.log(TET2_MAIL_LOG、既定 /var/log/mail.log と .1)の status=bounced|expired|sent → 宛先の delivery_state
 *  2. 訓練の送信元の Maildir の返信 → events の reply、戻りメール(DSN) → 宛先の delivery_state
 * 送信の処理(bin/send_email.py、v1 と共用)は変えず、Message-ID の <t{tracking_id}.…> で宛先を決める。
 *
 * mail.log(syslog:adm 640)と Maildir(各アカウント:vmail 640)を読むため、実行者は adm と vmail のグループが要る
 * (tet2-report-ingest.service と同じ)。timer は deploy/systemd/tet2-delivery-ingest.{service,timer}(既定で無効)。
 *   TET2_DB_PATH=/abs/path/copy.sqlite TET2_MAIL_LOG=/abs/path/mail.log php web/db/delivery_ingest.php
 * --mail-log-only / --replies-only で片方だけ動かせる。
 */
require __DIR__ . '/../lib/Db.php';
require __DIR__ . '/../lib/DeliveryStateIngest.php';
require __DIR__ . '/../lib/ReplyIngest.php';

$args = array_slice($argv, 1);
$result = [];
if (!in_array('--replies-only', $args, true)) {
    $result['mail_log'] = DeliveryStateIngest::run();
}
if (!in_array('--mail-log-only', $args, true)) {
    $result['maildir'] = ReplyIngest::run();
}
echo '[' . date('Y-m-d H:i:s') . '] delivery ' . json_encode($result, JSON_UNESCAPED_UNICODE) . "\n";
