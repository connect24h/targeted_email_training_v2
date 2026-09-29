<?php
declare(strict_types=1);

/**
 * 段B1(G26、G04): 訓練メールへの返信と戻りメール(DSN)を、送信元の Maildir から取り込む。合成の Maildir(.test)だけを使う。
 * - 返信は外側の In-Reply-To/References の <t{tracking_id}.…> で宛先を決め、正しいテナントとキャンペーンの events の reply に入れる
 * - 別のテナントの送信元の Maildir に届いた返信、差出人の違う返信、自動の応答、tracking のない返信は入れない
 * - 戻りメールは宛先を「届かない」にする。報告の Maildir は読まない。何度流しても同じ(冪等)
 */

require_once __DIR__ . '/helpers.php';
tet2_test_boot();
require_once __DIR__ . '/../lib/ReplyIngest.php';
require_once __DIR__ . '/../lib/TrainingActions.php';
load_api('report');

$root = sys_get_temp_dir() . '/tet2-reply-' . bin2hex(random_bytes(4));
register_shutdown_function(static function () use ($root): void {
    exec('rm -rf ' . escapeshellarg($root));
});
$maildir = static function (string $name) use ($root): string {
    foreach (['new', 'cur', 'tmp'] as $sub) {
        @mkdir("{$root}/{$name}/Maildir/{$sub}", 0777, true);
    }
    return "{$root}/{$name}/Maildir";
};
$senderA = $maildir('sender-a');   // テナント1の訓練の送信元
$senderB = $maildir('sender-b');   // テナント2の訓練の送信元
$report = $maildir('report');      // 報告の Maildir(読まない)
$opts = ['maildirs' => ['sender-a@train.example.test' => $senderA, 'sender-b@other.example.test' => $senderB,
    'report@train.example.test' => $report], 'report_maildir' => $report];

$put = static function (string $dir, string $name, array $headers, string $body = "返信の本文\n", string $sub = 'new'): string {
    $lines = [];
    foreach ($headers as $k => $v) {
        $lines[] = "{$k}: {$v}";
    }
    $path = "{$dir}/{$sub}/{$name}";
    file_put_contents($path, implode("\r\n", $lines) . "\r\n\r\n" . $body);
    return $path;
};

Db::run("INSERT INTO targets (id, tenant_id, tenant_no, email, name, status) VALUES (81, 1, 81, 'r81@example.test', '返信する人', 'active')");
Db::run("INSERT INTO targets (id, tenant_id, tenant_no, email, name, status) VALUES (82, 1, 82, 'r82@example.test', '届かない人', 'active')");
Db::run("INSERT INTO campaigns (id, tenant_id, name, status, created_by, from_address) VALUES (80, 1, '返信の確認', 'done', 1, 'sender-a@train.example.test')");
Db::run("INSERT INTO campaign_targets (id, campaign_id, target_id, tracking_id, content_no, send_status, sent_at) VALUES (881, 80, 81, '8000000081', 1, 'sent', '2026-09-28 10:00:00')");
Db::run("INSERT INTO campaign_targets (id, campaign_id, target_id, tracking_id, content_no, send_status, sent_at) VALUES (882, 80, 82, '8000000082', 1, 'sent', '2026-09-28 10:00:00')");
Db::run("INSERT INTO campaigns (id, tenant_id, name, status, created_by, from_address) VALUES (90, 2, '他社の訓練', 'done', 2, 'sender-b@other.example.test')");
Db::run("INSERT INTO campaign_targets (id, campaign_id, target_id, tracking_id, content_no, send_status, sent_at) VALUES (893, 90, 3, '9000000003', 1, 'sent', '2026-09-28 10:00:00')");

$msg = static fn (string $tid): string => "<t{$tid}.0123456789abcdef0123456789abcdef@train.example.test>";
$ok = $put($senderA, '1790000000.M1P1.mail', ['From' => '返信する人 <R81@example.test>', 'To' => 'sender-a@train.example.test',
    'Subject' => 'Re: ご確認ください', 'Message-ID' => '<reply-1@example.test>', 'In-Reply-To' => $msg('8000000081'),
    'References' => $msg('8000000081')]);
$put($senderA, '1790000001.M2P1.mail', ['From' => 'r81@example.test', 'Subject' => 'Re: 他社の訓練へ', 'Message-ID' => '<reply-2@example.test>',
    'In-Reply-To' => $msg('9000000003')]);                                                   // 別テナントの tracking(送信元が違う)
$put($senderA, '1790000002.M3P1.mail', ['From' => 'attacker@evil.example.test', 'Subject' => 'Re: x', 'Message-ID' => '<reply-3@example.test>',
    'In-Reply-To' => $msg('8000000081')]);                                                   // 差出人が宛先の人と違う
$put($senderA, '1790000003.M4P1.mail', ['From' => 'r81@example.test', 'Subject' => '不在', 'Message-ID' => '<reply-4@example.test>',
    'Auto-Submitted' => 'auto-replied', 'In-Reply-To' => $msg('8000000081')]);               // 自動の応答
$put($senderA, '1790000004.M5P1.mail', ['From' => 'r81@example.test', 'Subject' => 'こんにちは', 'Message-ID' => '<reply-5@example.test>'],
    "本文の引用だけに " . $msg('8000000081') . " がある\n");                                  // 外側のヘッダに tracking がない
$put($senderA, '1790000005.M6P1.mail', ['From' => 'MAILER-DAEMON@train.example.test', 'Subject' => 'Undelivered Mail Returned to Sender',
    'Message-ID' => '<dsn-1@train.example.test>', 'Content-Type' => 'multipart/report; report-type=delivery-status; boundary="B"'],
    "--B\r\nContent-Type: text/plain\r\n\r\n届きませんでした\r\n--B\r\nContent-Type: message/delivery-status\r\n\r\nReporting-MTA: dns; mail\r\n\r\n"
    . "Final-Recipient: rfc822; r82@example.test\r\nAction: failed\r\nStatus: 5.1.1\r\nDiagnostic-Code: smtp; 550 5.1.1 User unknown\r\n"
    . "--B\r\nContent-Type: text/rfc822-headers\r\n\r\nMessage-ID: " . $msg('8000000082') . "\r\nTo: r82@example.test\r\n--B--\r\n");
$put($senderB, '1790000006.M7P1.mail', ['From' => 'target@other.example.test', 'Subject' => 'Re: 他社', 'Message-ID' => '<reply-6@example.test>',
    'References' => '<unrelated@example.test> ' . $msg('9000000003')]);                        // テナント2の正しい返信
$put($report, '1790000007.M8P1.mail', ['From' => 'r81@example.test', 'Subject' => 'Fwd: 報告', 'Message-ID' => '<report-1@example.test>',
    'In-Reply-To' => $msg('8000000081')]);                                                   // 報告の Maildir は読まない

$counts = ReplyIngest::run($opts);
check($counts['reply'] === 2 && $counts['dsn'] === 1 && $counts['unmatched'] === 3 && $counts['ignored'] === 1 && $counts['scanned'] === 7,
    '取込: 返信2件、戻りメール1件、入れない3件、自動の応答1件(報告の Maildir は読まない)');
$replies = Db::all("SELECT tenant_id, campaign_id, tracking_id, occurred_at, verdict FROM events WHERE event_type = 'reply' ORDER BY tenant_id");
check(count($replies) === 2, 'events の reply は2件');
check($replies[0] === ['tenant_id' => 1, 'campaign_id' => 80, 'tracking_id' => '8000000081', 'occurred_at' => date('Y-m-d H:i:s', 1790000000), 'verdict' => 'user'],
    '返信は正しいテナント、キャンペーン、宛先に入る(時刻は Maildir に届いた時刻)');
check($replies[1]['tenant_id'] === 2 && $replies[1]['campaign_id'] === 90 && $replies[1]['tracking_id'] === '9000000003',
    'ほかのテナントの返信はそのテナントにだけ入る');
check(Db::one("SELECT COUNT(*) AS n FROM events WHERE tenant_id = 1 AND tracking_id = '9000000003'")['n'] === 0,
    'テナントの分離: 別のテナントの送信元の Maildir に届いた返信は入れない');
$status = array_column(Db::all('SELECT maildir_file, status, tenant_id FROM reply_mails'), null, 'maildir_file');
check($status["{$senderA}/new/1790000001.M2P1.mail"]['status'] === 'unmatched' && $status["{$senderA}/new/1790000001.M2P1.mail"]['tenant_id'] === null,
    '突き合わせられない返信はテナントを持たない(superadmin の返信者の一覧に残る)');
check($status["{$senderA}/new/1790000002.M3P1.mail"]['status'] === 'from_mismatch', '差出人が宛先の人と違う返信は入れない');
check($status["{$senderA}/new/1790000003.M4P1.mail"]['status'] === 'ignored_auto', '自動の応答は返信に数えない');
check($status["{$senderA}/new/1790000004.M5P1.mail"]['status'] === 'unmatched', '本文の引用だけの Message-ID では返信にしない');
check(!isset($status["{$report}/new/1790000007.M8P1.mail"]), '報告の Maildir は読まない(報告の取込が扱う)');
$ct = Db::one('SELECT delivery_state, delivery_detail FROM campaign_targets WHERE id = 882');
check($ct['delivery_state'] === 'undeliverable' && str_contains((string) $ct['delivery_detail'], 'status=5.1.1'), '戻りメールで宛先を「届かない」にする');

// 冪等: 読み直しても増えない。new から cur へ移ったメールも2回数えない
$again = ReplyIngest::run($opts);
check($again['scanned'] === 0 && Db::one("SELECT COUNT(*) AS n FROM events WHERE event_type = 'reply'")['n'] === 2, '同じ Maildir を読み直しても増えない');
rename($ok, "{$senderA}/cur/1790000000.M1P1.mail:2,S");
$moved = ReplyIngest::run($opts);
check($moved['scanned'] === 1 && $moved['ignored'] === 1 && Db::one("SELECT COUNT(*) AS n FROM events WHERE event_type = 'reply'")['n'] === 2,
    'cur へ移った同じメールは2回数えない');

// レポートとログに出る
$_GET = ['campaign_id' => '80'];
$people = array_column(call_handler('report_handle_people', [], 'viewer', [])['payload']['people'], null, 'tracking_id');
check($people['8000000081']['reply_at'] === date('Y-m-d H:i:s', 1790000000) && $people['8000000082']['delivery_state'] === 'undeliverable',
    '利用者ごとに返信の日時と届かない宛先が出る');
$summary = call_handler('report_handle_summary', [], 'viewer', [])['payload']['summary'];
check($summary['target_count'] === 1 && $summary['undeliverable_count'] === 1, '戻りメールで届かない宛先も率の分母から外れる');

echo "ALL TESTS PASSED\n";
