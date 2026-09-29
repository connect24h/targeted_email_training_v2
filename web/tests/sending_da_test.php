<?php
declare(strict_types=1);

/**
 * 段D の送信(D-a)のテスト: 種明かしメール(D2、G06)と担当者への報告の通知(D3、G38)。
 * 合成 DB と .test ドメインだけを使い、メールは送信口の差し替えで数える(SMTP へ出さない)。
 *
 *   OFF-*  既定(設定なし)では、失敗・報告・閉じた訓練・不審メールの報告があっても1通も送らない
 *   RA-*   (a) 失敗した直後: 失敗した本人にだけ1通。入れる前の失敗、装置の判定、在籍していない人、テストの訓練には送らない
 *   OPEN-* 閉じる前の訓練には (b) と (c) を送らない
 *   RB-*   (b) 閉じた後: 範囲(全員・失敗・失敗なし)どおりに1人1通。閉じる操作からも送る
 *   RC-*   (c) 報告した人へ(閉じた後): 報告した人にだけ1通
 *   ISO-*  テナントの分離(ほかのテナントの設定・対象者・通知先へ届かない)
 *   RT-*   送れなかった時は3回まで送り直し、それ以上は送らない
 *   PG-*   種明かしのページのトークン
 *   RN-*   報告の通知: 通知先へ報告ごとに1通、入れる前の報告・訓練・アップロードには送らない、上限、改行の差し込み
 *   API-*  設定の API の権限、CSRF、検証
 */

require_once __DIR__ . '/helpers.php';

tet2_test_boot();
putenv('TET2_EDU_MAIL_DISABLE=1');
putenv('TET2_EDU_BASE_URL=https://learner.example.test');
putenv('TET2_ADMIN_BASE_URL=https://admin.example.test/tet2');

require_once __DIR__ . '/../lib/EduMailer.php';
require_once __DIR__ . '/../lib/NotificationTemplates.php';
require_once __DIR__ . '/../lib/RevealMail.php';
require_once __DIR__ . '/../lib/ReportNotify.php';
require_once __DIR__ . '/../lib/SuspiciousMailStore.php';
require_once __DIR__ . '/../lib/Secrets.php';
load_api('reveal_mail');
load_api('report_notify');
load_api('report');

$GLOBALS['__MAILS'] = [];
$GLOBALS['__FAIL_TO'] = [];
EduMailer::useTransport(static function (string $to, string $subject, string $body): bool {
    if (in_array($to, $GLOBALS['__FAIL_TO'], true)) {
        return false;
    }
    $GLOBALS['__MAILS'][] = ['to' => $to, 'subject' => $subject, 'body' => $body];
    return true;
});
function takeMails(): array
{
    $m = $GLOBALS['__MAILS'];
    $GLOBALS['__MAILS'] = [];
    return $m;
}
function recipients(array $mails): array
{
    $to = array_column($mails, 'to');
    sort($to);
    return $to;
}
function runAll(): array
{
    RevealMail::run();
    ReportNotify::run();
    return takeMails();
}

$past = date('Y-m-d H:i:s', time() - 3600);
$later = date('Y-m-d H:i:s', time() + 120);

// ---------------------------------------------------------------------------
// 合成データ
//   テナント1 訓練A(配信終了、閉じる前): 1=失敗(クリック)、2=報告、4=送っていない、5=届かない(失敗)、6=装置だけ、7=在籍なし(失敗)
//   テナント1 訓練T(テスト): 1 が失敗
//   テナント2 訓練B: 3=失敗
// ---------------------------------------------------------------------------
$ins = static fn(string $sql, array $p = []): int => Db::insert($sql, $p);
foreach ([4 => 'notsent', 5 => 'bounce', 6 => 'scanner', 7 => 'archived'] as $id => $tag) {
    Db::run("INSERT INTO targets (id, tenant_id, tenant_no, email, name, status) VALUES (?, 1, ?, ?, ?, ?)",
        [$id, $id, "{$tag}@example.test", "T {$tag}", $tag === 'archived' ? 'archived' : 'active']);
}
$campA = $ins("INSERT INTO campaigns (tenant_id, name, status, created_by) VALUES (1, '訓練A', 'done', 1)");
$campT = $ins("INSERT INTO campaigns (tenant_id, name, status, is_test, created_by) VALUES (1, 'テスト訓練', 'done', 1, 1)");
$campB = $ins("INSERT INTO campaigns (tenant_id, name, status, created_by) VALUES (2, '訓練B', 'done', 2)");
$ct = static function (int $campaign, int $target, string $tid, ?string $sentAt, ?string $state = null): void {
    Db::run('INSERT INTO campaign_targets (campaign_id, target_id, tracking_id, content_no, send_status, sent_at, delivery_state)
             VALUES (?, ?, ?, 1, ?, ?, ?)', [$campaign, $target, $tid, $sentAt === null ? 'pending' : 'sent', $sentAt, $state]);
};
$ct($campA, 1, 'A000000001', '2026-09-01 10:00:00');
$ct($campA, 2, 'A000000002', '2026-09-01 10:00:00');
$ct($campA, 4, 'A000000004', null);
$ct($campA, 5, 'A000000005', '2026-09-01 10:00:00', 'undeliverable');
$ct($campA, 6, 'A000000006', '2026-09-01 10:00:00');
$ct($campA, 7, 'A000000007', '2026-09-01 10:00:00');
$ct($campT, 1, 'T000000001', '2026-09-01 10:00:00');
$ct($campB, 3, 'B000000003', '2026-09-01 10:00:00');
$ev = static function (int $tenant, int $campaign, string $tid, string $type, string $at, string $verdict = 'user'): void {
    Db::run('INSERT INTO events (tenant_id, campaign_id, tracking_id, event_type, occurred_at, source, verdict) VALUES (?, ?, ?, ?, ?, ?, ?)',
        [$tenant, $campaign, $tid, $type, $at, 'fixture', $verdict]);
};
// 設定を入れる前の失敗と報告
$ev(1, $campA, 'A000000001', 'click', $past);
$ev(1, $campA, 'A000000002', 'report', $past);
$ev(1, $campA, 'A000000006', 'click', $past, 'scanner');
$ev(1, $campT, 'T000000001', 'click', $past);
$ev(2, $campB, 'B000000003', 'auth', $past);
// 不審メールの報告(テナント1)
$sm = static function (int $tenant, string $source, int $training, string $createdAt, string $subject = '請求書のご確認',
    string $reporter = 'target1@example.test', string $from = 'bad@evil.test'): int {
    return Db::insert("INSERT INTO suspicious_mails (tenant_id, source, reporter_email, raw_path, sha256, raw_bytes, subject, from_email,
            received_at, is_training, analysis_json, findings_json, created_at)
        VALUES (?, ?, ?, '/nonexistent', ?, 1, ?, ?, ?, ?, '{}', '[]', ?)",
        [$tenant, $source, $reporter, bin2hex(random_bytes(8)), $subject, $from, $createdAt, $training, $createdAt]);
};
$sm(1, 'maildir', 0, $past);

// ---------------------------------------------------------------------------
// OFF: 既定では1通も送らない(閉じた訓練、失敗、報告、不審メールの報告があっても)
// ---------------------------------------------------------------------------
check(runAll() === [], 'OFF-1: 設定のない時は、失敗・報告・不審メールの報告があっても1通も送らない');
Db::run("UPDATE campaigns SET closed_at = ? WHERE id IN (?, ?, ?)", [$past, $campA, $campT, $campB]);
check(runAll() === [], 'OFF-2: 閉じた訓練でも、設定がなければ1通も送らない');
Db::run('UPDATE campaigns SET closed_at = NULL');
foreach ([$campA, $campT, $campB] as $c) {
    $t = $c === $campB ? 2 : 1;
    RevealMail::saveSettings($t, $c, ['on_fail' => false, 'on_close' => false, 'close_scope' => 'all', 'on_report' => false], 'x');
}
ReportNotify::save(1, '', 'x');
check(runAll() === [] && (int) Db::one('SELECT COUNT(*) AS n FROM notification_sends')['n'] === 0,
    'OFF-3: 設定の行があっても、すべて切・通知先が空なら1通も送らず、記録も作らない');

// ---------------------------------------------------------------------------
// (a) 失敗した直後
// ---------------------------------------------------------------------------
RevealMail::saveSettings(1, $campA, ['on_fail' => true, 'on_close' => false, 'close_scope' => 'all', 'on_report' => false], 'op');
RevealMail::saveSettings(1, $campT, ['on_fail' => true, 'on_close' => true, 'close_scope' => 'all', 'on_report' => true], 'op');
check(runAll() === [], 'RA-1: 入れる前の失敗には送らない');
$ev(1, $campA, 'A000000001', 'click', $later);
$ev(1, $campA, 'A000000006', 'click', $later, 'scanner');
$ev(1, $campA, 'A000000007', 'auth', $later);
$ev(1, $campA, 'A000000005', 'click', $later);
$ev(1, $campA, 'A000000002', 'open', $later);
$ev(1, $campT, 'T000000001', 'click', $later);
$mails = runAll();
check(recipients($mails) === ['target1@example.test'], 'RA-2: 失敗した本人にだけ送る(装置の判定・在籍なし・届かない宛先・開いただけ・テストの訓練には送らない)');
check($mails[0]['subject'] === '【ご確認ください】先ほどのメールは標的型メール訓練でした'
    && str_contains($mails[0]['body'], 'Target One 様') && str_contains($mails[0]['body'], 'https://learner.example.test/reveal_view.php?token=')
    && str_contains($mails[0]['body'], '周りの方に話さないで'), 'RA-3: 文面は既定の reveal_failed で、種明かしのページの URL が入る');
$ev(1, $campA, 'A000000001', 'auth', date('Y-m-d H:i:s', time() + 300));
check(runAll() === [], 'RA-4: 同じ人がもう一度失敗しても、2通目は送らない');
preg_match('/token=([0-9a-f]+)/', $mails[0]['body'], $m);
$token = $m[1] ?? '';

// ---------------------------------------------------------------------------
// 種明かしのページ
// ---------------------------------------------------------------------------
$eventsBefore = (int) Db::one('SELECT COUNT(*) AS n FROM events')['n'];
$page = RevealMail::pageForToken($token);
check($page !== null && str_contains($page, '標的型メール訓練') && (int) Db::one('SELECT COUNT(*) AS n FROM events')['n'] === $eventsBefore,
    'PG-1: トークンで種明かしのページを出し、events には何も書かない(測定に入らない)');
check(RevealMail::pageForToken('') === null && RevealMail::pageForToken(str_repeat('0', 48)) === null
    && RevealMail::pageForToken($token . 'x') === null, 'PG-2: 違うトークンでは出さない');
Db::run("UPDATE notification_sends SET created_at = datetime('now','localtime','-200 days') WHERE token = ?", [$token]);
check(RevealMail::pageForToken($token) === null, 'PG-3: 有効期限の過ぎたトークンでは出さない');
Db::run("UPDATE notification_sends SET created_at = datetime('now','localtime') WHERE token = ?", [$token]);
$dir = sys_get_temp_dir() . '/tet2-reveal-' . getmypid();
@mkdir($dir . '/reveal-pages', 0700, true);
register_shutdown_function(static function () use ($dir): void {
    @unlink($dir . '/reveal-pages/reveal-1.html');
    @unlink($dir . '/reveal.html');
    @rmdir($dir . '/reveal-pages');
    @rmdir($dir);
});
Db::run('UPDATE tenants SET data_dir = ? WHERE id = 1', [$dir]);
file_put_contents($dir . '/reveal.html', '<html><body>既定の種明かし #$6$# #$5$#</body></html>');
check(RevealMail::pageForToken($token) === '<html><body>既定の種明かし  </body></html>', 'PG-4: テナントの既定の reveal.html を出し、宛先と追跡の記号は空にする');
$rp = RevealPages::save(1, null, '選んだページ', '<html><body>選んだ種明かし</body></html>', 'op');
Db::run('UPDATE campaigns SET reveal_page_id = ? WHERE id = ?', [$rp, $campA]);
check(RevealMail::pageForToken($token) === '<html><body>選んだ種明かし</body></html>', 'PG-5: 訓練で選んだ種明かしのページを出す');
Db::run('UPDATE campaigns SET deleted_at = ? WHERE id = ?', [$past, $campA]);
check(RevealMail::pageForToken($token) === null, 'PG-6: 削除した訓練のトークンでは出さない');
Db::run('UPDATE campaigns SET deleted_at = NULL WHERE id = ?', [$campA]);

// ---------------------------------------------------------------------------
// 閉じる前: (b) と (c) は送らない
// ---------------------------------------------------------------------------
RevealMail::saveSettings(1, $campA, ['on_fail' => true, 'on_close' => true, 'close_scope' => 'all', 'on_report' => true], 'op');
check(runAll() === [], 'OPEN-1: 閉じる前の訓練には、終了後と報告した人への種明かしを送らない(入にしても)');
check(RevealMail::settings(1, $campA)['fail_since'] !== null
    && RevealMail::settings(1, $campA)['fail_since'] === Db::one('SELECT fail_since FROM campaign_reveal_settings WHERE campaign_id = ?', [$campA])['fail_since'],
    'OPEN-2: (a) を入れたままの保存では、入れた日時を変えない');

// ---------------------------------------------------------------------------
// (b)(c) 閉じた後
// ---------------------------------------------------------------------------
RevealMail::saveSettings(1, $campA, ['on_fail' => true, 'on_close' => true, 'close_scope' => 'failed', 'on_report' => false], 'op');
Db::run("UPDATE campaigns SET closed_at = datetime('now','localtime') WHERE id IN (?, ?)", [$campA, $campT]);
$mails = runAll();
check(recipients($mails) === ['target1@example.test'] && $mails[0]['subject'] === '【標的型メール訓練】訓練の実施のご報告'
    && str_contains($mails[0]['body'], '訓練のメールを送った日: 2026-09-01'),
    'RB-1: 範囲「失敗した人」: 失敗した人にだけ1通(届かない・在籍なし・テストの訓練を除く)');
RevealMail::saveSettings(1, $campA, ['on_fail' => true, 'on_close' => true, 'close_scope' => 'not_failed', 'on_report' => false], 'op');
check(recipients(runAll()) === ['scanner@example.test', 'target2@example.test'],
    'RB-2: 範囲「失敗しなかった人」: 装置の判定だけの人も失敗なしとして数え、送っていない人は除く');
RevealMail::saveSettings(1, $campA, ['on_fail' => true, 'on_close' => true, 'close_scope' => 'all', 'on_report' => false], 'op');
check(runAll() === [], 'RB-3: 範囲を「全員」に広げても、送った人には2通目を送らない');
RevealMail::saveSettings(1, $campA, ['on_fail' => true, 'on_close' => true, 'close_scope' => 'all', 'on_report' => true], 'op');
$mails = runAll();
check(recipients($mails) === ['target2@example.test'] && $mails[0]['subject'] === '【標的型メール訓練】ご報告ありがとうございました',
    'RC-1: 報告した人へ、閉じた後に1通');
check(runAll() === [], 'RC-2: 何度動かしても2通目は送らない');
$counts = Db::one("SELECT COUNT(*) AS n, COUNT(DISTINCT kind || dedupe_key) AS u FROM notification_sends WHERE tenant_id = 1 AND kind LIKE 'reveal_%'");
check((int) $counts['n'] === 5 && (int) $counts['u'] === 5, 'RC-3: 送った記録は1人に1つの条件で1行(失敗1、終了後3、報告1)');

// ---------------------------------------------------------------------------
// テナントの分離
// ---------------------------------------------------------------------------
RevealMail::saveSettings(2, $campB, ['on_fail' => true, 'on_close' => true, 'close_scope' => 'all', 'on_report' => true], 'op2');
$ev(2, $campB, 'B000000003', 'auth', date('Y-m-d H:i:s', time() + 300));
$mails = runAll();
check(recipients($mails) === ['target@other.example.test'], 'ISO-1: テナント2の設定では、テナント2の失敗した本人にだけ送る');
Db::run("UPDATE campaign_reveal_settings SET tenant_id = 1 WHERE campaign_id = ?", [$campB]);
Db::run("UPDATE campaigns SET closed_at = datetime('now','localtime') WHERE id = ?", [$campB]);
check(runAll() === [], 'ISO-2: 設定の行のテナントが訓練と違えば送らない');
Db::run("UPDATE campaign_reveal_settings SET tenant_id = 2 WHERE campaign_id = ?", [$campB]);
Db::run("UPDATE tenants SET status = 'suspended' WHERE id = 2");
check(runAll() === [], 'ISO-3: 利用停止のテナントには送らない');
Db::run("UPDATE tenants SET status = 'active' WHERE id = 2");
check(recipients(runAll()) === ['target@other.example.test'], 'ISO-4: 再開すると閉じた後の1通を送る');
$save = call_handler('rm_handle_save', ['campaign_id' => $campB, 'on_fail' => false, 'on_close' => false, 'close_scope' => 'all', 'on_report' => false], 'operator');
check($save['code'] === 404 && RevealMail::settings(2, $campB)['on_close'] === 1, 'ISO-5: ほかのテナントの訓練の設定は変えられない');

// ---------------------------------------------------------------------------
// 閉じる操作から送る
// ---------------------------------------------------------------------------
$campD = $ins("INSERT INTO campaigns (tenant_id, name, status, created_by) VALUES (1, '訓練D', 'running', 1)");
$ct($campD, 1, 'D000000001', '2026-09-02 10:00:00');
$ct($campD, 2, 'D000000002', '2026-09-02 10:00:00');
$_GET = ['campaign_id' => (string) $campD];
check(call_handler('report_handle_commit', [], 'operator')['code'] === 200, 'RB-4 前提: レポートを確定');
Db::run("UPDATE campaigns SET status = 'done' WHERE id = ?", [$campD]);
$closed = call_handler('report_handle_close', [], 'superadmin');
check($closed['code'] === 200 && $closed['payload']['reveal_mail'] === ['sent' => 0, 'failed' => 0, 'remaining' => 0] && takeMails() === [],
    'OFF-4: 設定のない訓練を閉じても1通も送らない');
$campE = $ins("INSERT INTO campaigns (tenant_id, name, status, created_by) VALUES (1, '訓練E', 'running', 1)");
$ct($campE, 1, 'E000000001', '2026-09-03 10:00:00');
$ct($campE, 2, 'E000000002', '2026-09-03 10:00:00');
RevealMail::saveSettings(1, $campE, ['on_fail' => false, 'on_close' => true, 'close_scope' => 'all', 'on_report' => false], 'op');
$_GET = ['campaign_id' => (string) $campE];
call_handler('report_handle_commit', [], 'operator');
Db::run("UPDATE campaigns SET status = 'done' WHERE id = ?", [$campE]);
$closed = call_handler('report_handle_close', [], 'superadmin');
check($closed['code'] === 200 && $closed['payload']['reveal_mail']['sent'] === 2
    && recipients(takeMails()) === ['target1@example.test', 'target2@example.test'], 'RB-4: 「終了後」を選んだ訓練は、閉じる操作で送る');
check(runAll() === [], 'RB-5: 閉じる操作で送った人へ、CLI は2通目を送らない');

// ---------------------------------------------------------------------------
// 送れなかった時
// ---------------------------------------------------------------------------
$campF = $ins("INSERT INTO campaigns (tenant_id, name, status, closed_at, created_by) VALUES (1, '訓練F', 'done', ?, 1)", [$past]);
$ct($campF, 1, 'F000000001', '2026-09-04 10:00:00');
RevealMail::saveSettings(1, $campF, ['on_fail' => false, 'on_close' => true, 'close_scope' => 'all', 'on_report' => false], 'op');
$GLOBALS['__FAIL_TO'] = ['target1@example.test'];
$r1 = RevealMail::run();
$r2 = RevealMail::run();
$r3 = RevealMail::run();
$r4 = RevealMail::run();
check($r1['failed'] === 1 && $r2['failed'] === 1 && $r3['failed'] === 1 && $r4['failed'] === 0
    && (int) Db::one("SELECT attempts FROM notification_sends WHERE campaign_id = ? AND kind = 'reveal_closed'", [$campF])['attempts'] === 3,
    'RT-1: 送れなかった時は3回まで送り直し、それ以上は送らない');
$GLOBALS['__FAIL_TO'] = [];
check(runAll() === [], 'RT-2: 回数の切れた宛先には、後で送れるようになっても送らない');
Db::run("INSERT INTO notification_sends (tenant_id, kind, dedupe_key, campaign_id, target_id, recipient, status)
         VALUES (1, 'reveal_closed', ?, ?, 2, 'target2@example.test', 'sending')", ["c{$campF}:t2"]);
$ct($campF, 2, 'F000000002', '2026-09-04 10:00:00');
check(runAll() === [], 'RT-3: 送る途中で止まった記録(sending)は送り直さない(二重に送らない)');

// ---------------------------------------------------------------------------
// 報告の通知(D3)
// ---------------------------------------------------------------------------
check(runAll() === [], 'RN-0: 通知先が空なら送らない');
ReportNotify::save(1, "sec1@example.test\nSEC2@example.test, sec1@example.test", 'admin');
$settings = ReportNotify::settings(1);
check($settings['emails'] === ['sec1@example.test', 'sec2@example.test'] && $settings['since'] !== null, 'RN-1: 通知先は重複を除いて小文字で保存し、入れた日時を持つ');
check(runAll() === [], 'RN-2: 通知先を入れる前に取り込んだ報告には送らない');
$newReport = $sm(1, 'maildir', 0, $later, '至急: パスワードの確認');
$sm(1, 'maildir', 1, $later, '訓練のメール');
$sm(1, 'upload', 0, $later, 'アップロード');
$sm(2, 'maildir', 0, $later, 'ほかのテナント', 'target@other.example.test');
$mails = runAll();
check(recipients($mails) === ['sec1@example.test', 'sec2@example.test'], 'RN-3: 報告用のアドレスに届いた報告を、通知先へ1通ずつ(訓練・アップロード・ほかのテナントは除く)');
check($mails[0]['subject'] === '【不審メールの報告】target1@example.test から報告がありました'
    && str_contains($mails[0]['body'], '件名: 至急: パスワードの確認') && str_contains($mails[0]['body'], '差出人: bad@evil.test')
    && str_contains($mails[0]['body'], 'https://admin.example.test/tet2/#suspiciousMails'), 'RN-4: 件名・差出人・報告者と管理画面の URL だけを入れる');
check(runAll() === [], 'RN-5: 同じ報告で2通目は送らない');
ReportNotify::save(1, 'sec1@example.test', 'admin');
check(ReportNotify::settings(1)['since'] === $settings['since'], 'RN-6: 通知先を入れたまま変えても、入れた日時は変えない');

for ($i = 0; $i < ReportNotify::RATE_LIMIT + 3; $i++) {
    $sm(1, 'maildir', 0, $later, "大量 {$i}");
}
$r = ReportNotify::run();
check($r['sent'] === ReportNotify::RATE_LIMIT - 2 && $r['deferred'] === 5 && count(takeMails()) === ReportNotify::RATE_LIMIT - 2,
    'RN-7: 1時間に30通まで(直前の2通を含めて数え、残りは次へ回す)');
Db::run("UPDATE notification_sends SET created_at = datetime('now','localtime','-2 hours') WHERE kind = 'report_notify'");
$r = ReportNotify::run();
check($r['sent'] === 5 && $r['deferred'] === 0 && count(takeMails()) === 5, 'RN-8: 時間が過ぎたら残りを送る');

// 改行の差し込み(報告者・件名・差出人・受信日時は外から来る値)
$evil = ['reporter_email' => "evil@example.test\r\nBcc: victim@example.test", 'subject' => "件名\r\nBcc: x@example.test\n本文の偽装",
    'from_email' => "a@example.test\nX-Injected: 1", 'received_at' => "2026-09-01 10:00\r\nX: y"];
$vars = ReportNotify::vars($evil);
$noBreak = true;
foreach ($vars as $v) {
    $noBreak = $noBreak && preg_match('/[\r\n\x00]/', $v) !== 1;
}
check($noBreak && $vars['報告者'] === ReportNotify::UNKNOWN, 'RN-9: 差し込むどの値も1行に直し、形の悪い報告者は「(不明)」にする');
$rendered = NotificationTemplates::render(1, 'report_notify', $vars);
$defaultLines = count(explode("\n", NotificationTemplates::definition('report_notify')['body']));
check(preg_match('/[\r\n]/', $rendered['subject']) !== 1 && count(explode("\n", $rendered['body'])) === $defaultLines
    && preg_match('/^(Bcc|X-Injected|X):/m', $rendered['body']) !== 1, 'RN-10: 件名に改行が入らず、本文の行も増えない');
$injected = $sm(1, 'maildir', 0, $later, "件名\r\nBcc: x@example.test", "evil@example.test\r\nBcc: victim@example.test", "a@example.test\nX: 1");
$mails = runAll();
check(count($mails) === 1 && preg_match('/[\r\n]/', $mails[0]['subject']) !== 1 && preg_match('/^(Bcc|X):/m', $mails[0]['body']) !== 1
    && str_contains($mails[0]['subject'], ReportNotify::UNKNOWN), 'RN-11: 改行を含む報告でも、送るメールの件名と本文に行が入らない');

// 取り込みの時点でも、改行を含む報告者のアドレスは保存しない(報告の取り込みは続ける)
$root = sys_get_temp_dir() . '/tet2-sm-da-' . getmypid();
@mkdir($root, 0700, true);
putenv("TET2_SUSPICIOUS_BASE={$root}");
putenv('TET2_SECRETS_FILE=/nonexistent/secrets.ini');
Secrets::reset();
register_shutdown_function(static function () use ($root): void {
    if (!is_dir($root)) {
        return;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($root);
});
$created = SuspiciousMailStore::create((string) file_get_contents(__DIR__ . '/fixtures/eml/benign.eml'),
    ['tenant_id' => null, 'source' => 'maildir', 'reporter_email' => "r@example.test\r\nBcc: x@example.test"]);
$row = Db::one('SELECT reporter_email FROM suspicious_mails WHERE id = ?', [$created['id']]);
check($created['duplicate'] === false && $row !== null && $row['reporter_email'] === null,
    'RN-12: 取り込みで CR・LF を含む報告者は空にして、報告は保存する');
check(SuspiciousMailStore::cleanReporter("a@example.test\0") === null && SuspiciousMailStore::cleanReporter(' a@example.test ') === 'a@example.test',
    'RN-13: NUL を含む値も保存しない。前後の空白は除く');

// ---------------------------------------------------------------------------
// 設定の API(権限、CSRF、検証)
// ---------------------------------------------------------------------------
$_GET = ['campaign_id' => (string) $campA];
$get = call_handler('rm_handle_get', [], 'viewer');
check($get['code'] === 200 && $get['payload']['settings']['on_close'] === 1 && ($get['payload']['sent']['reveal_closed']['sent'] ?? 0) === 3,
    'API-1: 閲覧者は設定と送った通数を見られる');
$body = ['campaign_id' => $campA, 'on_fail' => false, 'on_close' => false, 'close_scope' => 'all', 'on_report' => false];
check(call_handler('rm_handle_save', $body, 'viewer')['code'] === 403 && RevealMail::settings(1, $campA)['on_close'] === 1,
    'API-2: 閲覧者は種明かしメールの設定を変えられない');
$save = call_handler('rm_handle_save', $body, 'operator');
check($save['code'] === 200 && $GLOBALS['__TET2_TEST_CSRF_CALLS'] === 1 && RevealMail::settings(1, $campA)['on_close'] === 0
    && ($GLOBALS['__TET2_TEST_AUDIT'][0]['action'] ?? '') === 'campaign.reveal_mail_settings' && takeMails() === [],
    'API-3: オペレータは CSRF を通して保存でき、監査に残り、保存では送らない');
check(call_handler('rm_handle_save', ['close_scope' => 'everyone'] + $body, 'operator')['code'] === 400
    && call_handler('rm_handle_save', ['on_fail' => 'yes'] + $body, 'operator')['code'] === 400
    && call_handler('rm_handle_save', ['campaign_id' => 0] + $body, 'operator')['code'] === 400, 'API-4: 範囲と真偽値と訓練の番号を確かめる');
$_GET = [];
check(call_handler('rn_handle_get', [], 'operator')['code'] === 403 && call_handler('rn_handle_save', ['emails' => ''], 'operator')['code'] === 403,
    'API-5: オペレータは報告の通知先を見られず、変えられない');
$get = call_handler('rn_handle_get', [], 'tenant_admin');
check($get['code'] === 200 && $get['payload']['emails'] === ['sec1@example.test'], 'API-6: 組織管理者は通知先を見られる');
check(call_handler('rn_handle_save', ['emails' => "ok@example.test\r\nBcc: x@example.test"], 'tenant_admin')['code'] === 400
    && call_handler('rn_handle_save', ['emails' => 'not-an-address'], 'tenant_admin')['code'] === 400
    && call_handler('rn_handle_save', ['emails' => implode(',', array_map(static fn(int $i): string => "s{$i}@example.test", range(1, 11)))], 'tenant_admin')['code'] === 400
    && ReportNotify::settings(1)['emails'] === ['sec1@example.test'], 'API-7: 形の悪いアドレス、11件以上は保存しない');
$save = call_handler('rn_handle_save', ['emails' => ''], 'tenant_admin');
check($save['code'] === 200 && $GLOBALS['__TET2_TEST_CSRF_CALLS'] === 1 && ReportNotify::settings(1) ['emails'] === []
    && ReportNotify::settings(1)['since'] === null, 'API-8: 空にすると通知しない(CSRF を通す)');
$sm(1, 'maildir', 0, date('Y-m-d H:i:s', time() + 600), '空にした後');
check(runAll() === [], 'API-9: 空にした後の報告には送らない');

echo "ALL TESTS PASSED\n";
