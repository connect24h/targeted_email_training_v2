<?php
declare(strict_types=1);

/**
 * 段B1(G04): 届かない宛先の取込(mail.log の status=bounced)と、率の分母からの除外、対象者の一覧の警告。
 * 合成の mail.log だけを使う(アドレスは .test)。本番の /var/log/mail.log は読まない。
 */

require_once __DIR__ . '/helpers.php';
tet2_test_boot();
require_once __DIR__ . '/../lib/DeliveryStateIngest.php';
load_api('report');
load_api('targets');

$dir = sys_get_temp_dir() . '/tet2-maillog-' . bin2hex(random_bytes(4));
mkdir($dir);
register_shutdown_function(static function () use ($dir): void {
    foreach (glob($dir . '/*') ?: [] as $f) {
        unlink($f);
    }
    @rmdir($dir);
});
$log = $dir . '/mail.log';
$now = strtotime('2026-09-29 12:00:00');

// 対象者 71..76(テナント1)。訓練 70(送信元 sender@train.example.test)、テストの訓練 79(宛先をテスト用に振り替える)
foreach (range(71, 76) as $id) {
    Db::run("INSERT INTO targets (id, tenant_id, tenant_no, email, name, company, status) VALUES (?, 1, ?, ?, ?, 'Example Co', 'active')",
        [$id, $id, "d{$id}@example.test", "宛先{$id}"]);
}
Db::run("INSERT INTO campaigns (id, tenant_id, name, status, created_by, from_address) VALUES (70, 1, '届かない宛先', 'done', 1, 'sender@train.example.test')");
Db::run("INSERT INTO campaigns (id, tenant_id, name, status, created_by, from_address, is_test) VALUES (79, 1, 'テスト送信', 'done', 1, 'sender@train.example.test', 1)");
Db::run("INSERT INTO campaigns (id, tenant_id, name, status, created_by, from_address) VALUES (78, 1, '前の訓練', 'done', 1, 'sender@train.example.test')");
foreach (range(71, 76) as $id) {
    Db::run("INSERT INTO campaign_targets (id, campaign_id, target_id, tracking_id, content_no, send_status, sent_at) VALUES (?, 70, ?, ?, 1, 'sent', '2026-09-28 10:00:00')",
        [700 + $id, $id, '70000000' . $id]);
}
Db::run("INSERT INTO campaign_targets (id, campaign_id, target_id, tracking_id, content_no, send_status, sent_at) VALUES (791, 79, 71, '7900000071', 1, 'sent', '2026-09-28 10:00:00')");
// 前の訓練でも 71 は届かなかった(続けて届かない → 警告)。72 は前は届かず、今回は届いた(警告しない)
Db::run("INSERT INTO campaign_targets (id, campaign_id, target_id, tracking_id, content_no, send_status, sent_at, delivery_state, delivery_state_at)
         VALUES (781, 78, 71, '7800000071', 1, 'sent', '2026-08-01 10:00:00', 'undeliverable', '2026-08-01 10:01:00')");
Db::run("INSERT INTO campaign_targets (id, campaign_id, target_id, tracking_id, content_no, send_status, sent_at, delivery_state, delivery_state_at)
         VALUES (782, 78, 72, '7800000072', 1, 'sent', '2026-08-01 10:00:00', 'undeliverable', '2026-08-01 10:01:00')");
// ほかのテナントの訓練
Db::run("INSERT INTO campaigns (id, tenant_id, name, status, created_by, from_address) VALUES (77, 2, '他社', 'done', 2, 'sender@train.example.test')");
Db::run("INSERT INTO campaign_targets (id, campaign_id, target_id, tracking_id, content_no, send_status, sent_at) VALUES (799, 77, 3, '7700000003', 1, 'sent', '2026-09-28 10:00:00')");

$iso = static fn (string $time): string => "2026-09-28T{$time}.123456+09:00 mail";
$pf = static fn (string $time, string $proc, string $queue, string $body): string => $iso($time) . " postfix/{$proc}[1234]: {$queue}: {$body}";
$bounce = static fn (string $to, string $dsn, string $text): string =>
    "to=<{$to}>, relay=mx.example.test[192.0.2.10]:25, delay=1.2, delays=0.1/0/0.5/0.6, dsn={$dsn}, status=bounced ({$text})";

// ローテート済みの .1 に 71 の送信(message-id と from)、今のファイルに結果の行がある
file_put_contents($log . '.1', implode("\n", [
    $pf('10:00:01', 'cleanup', 'A1B2C3D401', 'message-id=<t7000000071.0123456789abcdef@train.example.test>'),
    $pf('10:00:01', 'qmgr', 'A1B2C3D401', 'from=<sender@train.example.test>, size=1234, nrcpt=1 (queue active)'),
]) . "\n");
file_put_contents($log, implode("\n", [
    // 71: tracking の Message-ID で結ぶ。届かない
    $pf('10:00:02', 'smtp', 'A1B2C3D401', $bounce('d71@example.test', '5.1.1', 'host mx.example.test said: 550 5.1.1 User unknown')),
    // 72: 送達(前は届かなかったが今回は届いた)
    $pf('10:00:03', 'cleanup', 'B1B2C3D402', 'message-id=<t7000000072.aa@train.example.test>'),
    $pf('10:00:04', 'smtp', 'B1B2C3D402', 'to=<d72@example.test>, relay=mx.example.test[192.0.2.10]:25, delay=1, delays=0/0/0/1, dsn=2.0.0, status=sent (250 2.0.0 OK)'),
    // 73: 宛先のアドレスが対象者と違う(入れない)
    $pf('10:00:05', 'cleanup', 'C1B2C3D403', 'message-id=<t7000000073.bb@train.example.test>'),
    $pf('10:00:06', 'smtp', 'C1B2C3D403', $bounce('someone-else@example.test', '5.1.1', 'User unknown')),
    // 74: Message-ID に tracking がない送信。宛先・時刻・送信元が合う1件なので結ぶ
    $pf('10:00:07', 'cleanup', 'D1B2C3D404', 'message-id=<20260928100007.1@train.example.test>'),
    $pf('10:00:07', 'qmgr', 'D1B2C3D404', 'from=<sender@train.example.test>, size=1234, nrcpt=1 (queue active)'),
    $pf('10:00:08', 'smtp', 'D1B2C3D404', $bounce('d74@example.test', '5.2.1', 'mailbox disabled')),
    // 75: tracking がなく、送信元が訓練と違う(v1 などほかの送信。入れない)
    $pf('10:00:09', 'cleanup', 'E1B2C3D405', 'message-id=<other.1@elsewhere.example.test>'),
    $pf('10:00:09', 'qmgr', 'E1B2C3D405', 'from=<other@elsewhere.example.test>, size=1, nrcpt=1 (queue active)'),
    $pf('10:00:10', 'smtp', 'E1B2C3D405', $bounce('d75@example.test', '5.1.1', 'User unknown')),
    // 76: 一時的な遅延(deferred)は見ない
    $pf('10:00:11', 'cleanup', 'F1B2C3D406', 'message-id=<t7000000076.cc@train.example.test>'),
    $pf('10:00:12', 'smtp', 'F1B2C3D406', 'to=<d76@example.test>, relay=none, delay=1, delays=0/0/1/0, dsn=4.4.1, status=deferred (connect timed out)'),
    // テストの訓練: 宛先はテスト用に振り替え(アドレスを問わない)。従来の syslog の時刻の形も読む
    'Sep 28 10:00:13 mail postfix/cleanup[1234]: G1B2C3D407: message-id=<t7900000071.dd@train.example.test>',
    'Sep 28 10:00:14 mail postfix/smtp[1234]: G1B2C3D407: ' . $bounce('tester@example.test', '5.1.1', 'User unknown'),
    // ほかのテナントの宛先(tracking で結ぶ。テナント1の宛先は変えない)
    $pf('10:00:15', 'cleanup', 'H1B2C3D408', 'message-id=<t7700000003.ee@train.example.test>'),
    $pf('10:00:16', 'smtp', 'H1B2C3D408', $bounce('target@other.example.test', '5.1.1', 'User unknown')),
    // 期限切れで戻された(expired)も届かない
    $pf('10:00:17', 'cleanup', 'I1B2C3D409', 'message-id=<t7000000076.ff@train.example.test>'),
    $pf('10:00:18', 'qmgr', 'I1B2C3D409', 'from=<sender@train.example.test>, status=expired, returned to sender'),
    $pf('10:00:19', 'error', 'I1B2C3D409', 'to=<d76@example.test>, relay=none, delay=432000, delays=0/0/0/0, dsn=4.4.1, status=expired, returned to sender'),
    'a line that is not postfix',
]) . "\n");

$counts = DeliveryStateIngest::run(['mail_log' => $log, 'now' => $now]);
$state = static fn (int $id): ?string => Db::one('SELECT delivery_state FROM campaign_targets WHERE id = ?', [$id])['delivery_state'];
check($counts['readable'] === true && $counts['undeliverable'] === 5 && $counts['delivered'] === 1, '取込: 届かない5件、送達1件');
check($counts['unmatched'] === 2, '取込: 結べない届かない2件(アドレス違い、訓練以外の送信元)は入れない');
check($state(771) === 'undeliverable' && str_contains((string) Db::one('SELECT delivery_detail FROM campaign_targets WHERE id = 771')['delivery_detail'], 'dsn=5.1.1'),
    'ローテート済みの .1 の message-id と今のファイルの結果の行を結ぶ(根拠に dsn を残す)');
check(Db::one('SELECT delivery_state_at FROM campaign_targets WHERE id = 771')['delivery_state_at'] === '2026-09-28 10:00:02', '届かないと分かった日時はログの時刻');
check($state(772) === 'delivered', '送達(status=sent)を記録する');
check($state(773) === null && $state(775) === null, '宛先が対象者と違う行、訓練以外の送信元の行は入れない');
check($state(774) === 'undeliverable' && str_contains((string) Db::one('SELECT delivery_detail FROM campaign_targets WHERE id = 774')['delivery_detail'], '宛先と時刻で照合'),
    'Message-ID に tracking がない送信は、宛先・時刻・送信元が合う1件だけ結ぶ');
check($state(776) === 'undeliverable', '一時的な遅延は見ず、期限切れ(expired)を届かないにする');
check($state(791) === 'undeliverable', 'テストの訓練は振り替えた宛先でも結ぶ(syslog の時刻の形も読む)');
check($state(781) === 'undeliverable' && $state(782) === 'undeliverable', '前の訓練の記録は変えない');

// 冪等: 同じログを読み直しても書かない
$again = DeliveryStateIngest::run(['mail_log' => $log, 'now' => $now]);
check($again['undeliverable'] === 0 && $again['delivered'] === 0, '同じログを読み直しても書かない(冪等)');
// 届かないを送達で上書きしない
file_put_contents($log, $pf('11:00:00', 'cleanup', 'J1B2C3D410', 'message-id=<t7000000071.zz@train.example.test>') . "\n"
    . $pf('11:00:01', 'smtp', 'J1B2C3D410', 'to=<d71@example.test>, relay=mx.example.test[192.0.2.10]:25, dsn=2.0.0, status=sent (250 OK)') . "\n", FILE_APPEND);
DeliveryStateIngest::run(['mail_log' => $log, 'now' => $now]);
check($state(771) === 'undeliverable', '届かないを送達で上書きしない');
check(DeliveryStateIngest::run(['mail_log' => $dir . '/missing.log'])['readable'] === false, '読めないログは何もしない');

// ---- 率の分母から外す ----
$_GET = ['campaign_id' => '70'];
$s = call_handler('report_handle_summary', [], 'viewer', [])['payload']['summary'];
check($s['target_count'] === 3 && $s['sent_count'] === 3 && $s['undeliverable_count'] === 3,
    '概要: 届かない3人(71、74、76)を対象数と送信済みから外し、人数を出す');
check($s['delivery_error_count'] === 3, '概要: 届かない宛先は配信エラーにも数える');
Db::run("INSERT INTO events (tenant_id, campaign_id, tracking_id, event_type, occurred_at, source) VALUES (1, 70, '7000000072', 'click', '2026-09-28 11:00:00', 'fixture')");
$s = call_handler('report_handle_summary', [], 'viewer', [])['payload']['summary'];
check($s['click_rate'] === 33.3 && $s['failure_rate'] === 33.3, '概要: 率の分母は届いた3人(1人のクリックで 33.3%)');
$_GET = [];
$c = array_column(call_handler('report_handle_campaigns', [], 'viewer', [])['payload']['campaigns'], null, 'id')[70];
check($c['target_count'] === 3 && $c['undeliverable_count'] === 3 && $c['click_rate'] === 33.3, '一覧: 同じく届かない宛先を分母から外す');
$_GET = ['campaign_id' => '70'];
$d = call_handler('report_handle_detail', [], 'viewer', [])['payload'];
check($d['summary']['count'] === 3 && $d['summary']['link_rate'] === 33.33, '詳細: 会社別の数も届いた宛先だけ');
$_GET = ['campaign_id' => '77', 'tenant_id' => '2'];
check(call_handler('report_handle_summary', [], 'superadmin', [])['payload']['summary']['undeliverable_count'] === 1,
    'ほかのテナントの届かない宛先はそのテナントにだけ出る');

// ---- 対象者の一覧 ----
$_GET = ['action' => 'list'];
$list = array_column(call_handler('targets_handle_list', [], 'viewer')['payload']['targets'], null, 'id');
check($list[71]['undeliverable_count'] === 2 && $list[71]['delivery_warning'] === true, '対象者: 続けて届かない宛先に警告(2回。テストの訓練は数えない)');
check($list[72]['undeliverable_count'] === 1 && $list[72]['delivery_warning'] === false, '対象者: その後に届いた宛先は警告しない');
check($list[74]['undeliverable_count'] === 1 && $list[73]['undeliverable_count'] === 0, '対象者: 届かない回数を出す');
check(Db::one("SELECT COUNT(*) AS n FROM targets WHERE id BETWEEN 71 AND 76 AND status = 'active'")['n'] === 6, '対象者は自動では消さない');

echo "ALL TESTS PASSED\n";
