<?php
declare(strict_types=1);

/**
 * 報告者への定型文の返信(段D の D4、G10)。
 * 押した時だけ送ること、宛先は報告者だけ、「訓練メールでした」は訓練を閉じた後だけ、二度押しで二重に送らないこと、
 * テナントの分離、送った記録が報告の履歴に残ることを確かめる。送信は EduMailer の差し替えで数え、投函しない。
 */

require_once __DIR__ . '/helpers.php';

tet2_test_boot();
require_once __DIR__ . '/../lib/SuspiciousMailReplies.php';
load_api('suspicious_mail_replies');

$mails = [];
$transportOk = true;
EduMailer::useTransport(static function (string $to, string $subject, string $body) use (&$mails, &$transportOk): bool {
    $mails[] = ['to' => $to, 'subject' => $subject, 'body' => $body];
    return $transportOk;
});

function smr_insert_mail(?int $tenantId, ?string $reporter, ?string $trackingId, string $subject): int
{
    return Db::insert("INSERT INTO suspicious_mails (tenant_id, source, reporter_email, raw_path, sha256, raw_bytes, subject, from_email,
        received_at, is_training, tracking_id, analysis_json, findings_json)
        VALUES (?, 'maildir', ?, '/nonexistent', ?, 10, ?, 'attacker@evil.test', '2026-10-01 09:30:00', ?, ?, '{}', '[]')",
        [$tenantId, $reporter, bin2hex(random_bytes(8)), $subject, $trackingId !== null ? 1 : 0, $trackingId]);
}

// 訓練のキャンペーン(tenant 1)。まだ閉じていない。
Db::run("INSERT INTO campaigns (id, tenant_id, name, status, created_by) VALUES (20, 1, '10月の訓練', 'done', 1)");
Db::run("INSERT INTO campaign_targets (id, campaign_id, target_id, tracking_id, content_no, send_status, sent_at)
    VALUES (20, 20, 1, '2000000001', 1, 'sent', '2026-10-01 09:00:00')");

$plain = smr_insert_mail(1, 'target1@example.test', null, "請求書の確認\nBcc: x@evil.test");
$training = smr_insert_mail(1, 'target1@example.test', '2000000001', '【重要】パスワードの期限');
$noReporter = smr_insert_mail(1, null, null, '報告者なし');
$other = smr_insert_mail(2, 'target@other.example.test', null, '他テナント');

// --- 既定: 画面を開いただけ・登録しただけでは送らない ---
$r = call_handler('smr_handle_options', [], 'operator', []);
check($r['code'] === 400, 'D4-0: id がなければ 400');
$_GET['id'] = (string) $plain;
$r = call_handler('smr_handle_options', [], 'operator', []);
$items = array_column($r['payload']['items'] ?? [], null, 'kind');
check($r['code'] === 200 && count($items) === 4 && $mails === [], 'D4-1: 定型文の一覧を見るだけでは1通も送らない');
check($items['report_reply_checking']['allowed'] === true && str_contains($items['report_reply_checking']['body'], 'Target One 様')
    && str_contains($items['report_reply_checking']['body'], '請求書の確認 Bcc: x@evil.test')
    && !str_contains($items['report_reply_checking']['subject'], "\n"),
    'D4-1: 見本は報告者の氏名と、改行を除いた件名で差し込む');
check($items['report_reply_training']['allowed'] === false && str_contains((string) $items['report_reply_training']['reason'], '特定できない'),
    'D4-1: 訓練に結び付かない報告には「訓練メールでした」を選べない');

// --- 権限と CSRF ---
$r = call_handler('smr_handle_send', ['id' => $plain, 'kind' => 'report_reply_checking'], 'viewer', []);
check($r['code'] === 403 && $mails === [], 'D4-2: 閲覧者は返信できない');

// --- 報告者だけに送る(本文で宛先を指定しても使わない) ---
$r = call_handler('smr_handle_send', ['id' => $plain, 'kind' => 'report_reply_checking', 'to' => 'someone@evil.test'], 'operator', []);
check($r['code'] === 200 && count($mails) === 1 && $mails[0]['to'] === 'target1@example.test'
    && $GLOBALS['__TET2_TEST_CSRF_CALLS'] === 1, 'D4-3: 報告者のアドレスにだけ1通送る(CSRF を確かめる)');
check(str_contains($mails[0]['subject'], '確認しています') && str_contains($mails[0]['body'], '報告の受付日時: 2026-10-01 09:30'),
    'D4-3: 既定の「確認中です」の文面で送る');
$audit = $GLOBALS['__TET2_TEST_AUDIT'][0] ?? [];
check(($audit['action'] ?? '') === 'suspicious_mail.reply' && !str_contains((string) $audit['detail'], '@'),
    'D4-3: 監査ログに残す(宛先のアドレスは書かない)');
$history = Db::all("SELECT * FROM suspicious_mail_history WHERE suspicious_mail_id = ? AND field = 'reply'", [$plain]);
$row = Db::one('SELECT * FROM suspicious_mail_replies WHERE suspicious_mail_id = ?', [$plain]);
check(count($history) === 1 && str_contains((string) $history[0]['new_value'], '確認中です') && $history[0]['actor_email'] === 'test@t'
    && $row['status'] === 'sent' && $row['to_email'] === 'target1@example.test' && $row['sent_at'] !== null,
    'D4-3: 送った記録を報告の履歴と返信の記録に残す');

// --- 二度押し: 同じ定型文は二重に送らない。ほかの定型文は送れる ---
$r = call_handler('smr_handle_send', ['id' => $plain, 'kind' => 'report_reply_checking'], 'operator', []);
check($r['code'] === 409 && count($mails) === 1, 'D4-4: 同じ報告へ同じ定型文をもう一度押しても送らない(409)');
Db::run("INSERT INTO suspicious_mail_replies (tenant_id, suspicious_mail_id, kind, to_email, subject, sent_by, status)
    VALUES (1, ?, 'report_reply_safe', 'target1@example.test', 's', 'x', 'sending')", [$plain]);
$r = call_handler('smr_handle_send', ['id' => $plain, 'kind' => 'report_reply_dangerous'], 'operator', []);
check($r['code'] === 409 && count($mails) === 1, 'D4-4: ほかの返信を送っている最中は送らない(同時の押下)');
Db::run("DELETE FROM suspicious_mail_replies WHERE status = 'sending'");
$r = call_handler('smr_handle_send', ['id' => $plain, 'kind' => 'report_reply_safe'], 'operator', []);
check($r['code'] === 200 && count($mails) === 2 && str_contains($mails[1]['subject'], '安全'), 'D4-4: 別の定型文は送れる');

// --- 「訓練メールでした」は訓練を閉じた後だけ ---
$_GET['id'] = (string) $training;
$r = call_handler('smr_handle_options', [], 'operator', []);
$items = array_column($r['payload']['items'], null, 'kind');
check($items['report_reply_training']['allowed'] === false && str_contains((string) $items['report_reply_training']['reason'], '閉じる前'),
    'D4-5: 閉じる前の訓練の報告では「訓練メールでした」を選べない(理由を出す)');
$r = call_handler('smr_handle_send', ['id' => $training, 'kind' => 'report_reply_training'], 'operator', []);
check($r['code'] === 409 && count($mails) === 2, 'D4-5: 閉じる前は、API を直接呼んでも「訓練メールでした」を送らない');
$r = call_handler('smr_handle_send', ['id' => $plain, 'kind' => 'report_reply_training'], 'operator', []);
check($r['code'] === 409 && count($mails) === 2, 'D4-5: 訓練に結び付かない報告にも「訓練メールでした」は送らない');
$r = call_handler('smr_handle_send', ['id' => $training, 'kind' => 'report_reply_checking'], 'operator', []);
check($r['code'] === 200 && count($mails) === 3 && !str_contains($mails[2]['body'], '訓練'),
    'D4-5: 閉じる前でも「確認中です」は送れて、訓練だとは書かない');
Db::run("UPDATE campaigns SET closed_at = '2026-10-10 10:00:00' WHERE id = 20");
$r = call_handler('smr_handle_send', ['id' => $training, 'kind' => 'report_reply_training'], 'operator', []);
check($r['code'] === 200 && count($mails) === 4 && $mails[3]['to'] === 'target1@example.test' && str_contains($mails[3]['body'], '訓練のメールでした'),
    'D4-5: 訓練を閉じた後は「訓練メールでした」を報告者へ送れる');

// --- テナントの上書きの文面で送る ---
NotificationTemplates::save(1, 'report_reply_dangerous', '危険でした {件名}', "{氏名} さん\n削除してください\n", 'admin@example.test');
$r = call_handler('smr_handle_send', ['id' => $plain, 'kind' => 'report_reply_dangerous'], 'operator', []);
check($r['code'] === 200 && $mails[4]['subject'] === '危険でした 請求書の確認 Bcc: x@evil.test' && $mails[4]['body'] === "Target One さん\n削除してください\n",
    'D4-6: テナントが直した文面で送る');

// --- テナントの分離、報告者なし、不正な種類 ---
$r = call_handler('smr_handle_send', ['id' => $other, 'kind' => 'report_reply_checking'], 'operator', []);
check($r['code'] === 404 && count($mails) === 5, 'D4-7: 他テナントの報告には返信できない(404)');
$_GET['id'] = (string) $other;
check(call_handler('smr_handle_options', [], 'operator', [])['code'] === 404, 'D4-7: 他テナントの報告の定型文も見られない');
$r = call_handler('smr_handle_send', ['id' => $noReporter, 'kind' => 'report_reply_checking'], 'operator', []);
check($r['code'] === 409 && count($mails) === 5, 'D4-7: 報告者のアドレスがない報告には送らない');
$r = call_handler('smr_handle_send', ['id' => $plain, 'kind' => 'edu_invite'], 'operator', []);
check($r['code'] === 400 && count($mails) === 5, 'D4-7: 返信の定型文でない種類は断る');

// --- 送信に失敗したら記録を残し、送り直せる ---
$transportOk = false;
$r = call_handler('smr_handle_send', ['id' => $training, 'kind' => 'report_reply_safe'], 'operator', []);
check($r['code'] === 502 && Db::one("SELECT status FROM suspicious_mail_replies WHERE suspicious_mail_id = ? AND kind = 'report_reply_safe'", [$training])['status'] === 'failed',
    'D4-8: 送れなかった時は 502 で、記録は failed');
$transportOk = true;
$r = call_handler('smr_handle_send', ['id' => $training, 'kind' => 'report_reply_safe'], 'operator', []);
check($r['code'] === 200, 'D4-8: 失敗した定型文は送り直せる');

// --- 取込(自動の処理)は返信しない ---
$before = count($mails);
$raw = "From: a@evil.test\r\nTo: target1@example.test\r\nSubject: test\r\nMessage-ID: <x1@evil.test>\r\nDate: Wed, 01 Oct 2026 09:00:00 +0900\r\n\r\nbody\r\n";
// 保存先はこのテストだけの一時ディレクトリ(終わったら消す)
$root = sys_get_temp_dir() . '/tet2-smr-' . bin2hex(random_bytes(5));
mkdir($root);
putenv("TET2_SUSPICIOUS_BASE=$root");
register_shutdown_function(static function () use ($root): void {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) { $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname()); }
    rmdir($root);
});
SuspiciousMailStore::create($raw, ['tenant_id' => 1, 'source' => 'upload', 'reporter_email' => 'target1@example.test', 'uploaded_by' => 'x']);
check(count($mails) === $before, 'D4-9: 報告を登録しただけでは返信しない(自動では送らない)');

echo "ALL TESTS PASSED\n";
