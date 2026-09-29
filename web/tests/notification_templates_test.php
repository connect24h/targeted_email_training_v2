<?php
declare(strict_types=1);

/**
 * 通知の文面(C2、G35)のテスト。合成 DB と .test ドメインだけを使い、メールは送信口の差し替えで数える(SMTP へ出さない)。
 *
 *   DF-*  既定の文面: 上書きのない時、どの種類も以前の直書きの文面と1文字も変わらない
 *         (下の legacy_* は 9156f11 の各送信口の組み立てをそのまま写したもの。値の組み合わせを変えて比べる)
 *   SP-*  実際の送信口(配信の開始、画面の催促、自動の催促、訓練後の自動の割り当て、アンケート、招待と再設定)を通しても同じ
 *   OV-*  テナントの上書きが送信に効く、ほかのテナントには効かない、既定に戻せる
 *   VL-*  保存の検証: 決まった一覧にない差し込み、URL の差し込みの削除、長さ、件名の改行(ヘッダインジェクション)
 *   API-* 一覧、保存、既定に戻す、プレビュー、権限、テナントの分離、CSRF
 *   TS-*  テスト送信: 本人のアドレスにだけ送る、10分に5回まで、監査ログ
 */

require_once __DIR__ . '/helpers.php';

tet2_test_boot();
putenv('TET2_EDU_MAIL_DISABLE=1');
putenv('TET2_EDU_BASE_URL=https://learner.example.test');
putenv('TET2_ADMIN_BASE_URL=https://admin.example.test/tet2');
putenv('TET2_LEARNER_BASE_URL=https://learner.example.test');

require_once __DIR__ . '/../lib/EduMailer.php';
require_once __DIR__ . '/../lib/NotificationTemplates.php';
require_once __DIR__ . '/../lib/EduDeliveryLauncher.php';
require_once __DIR__ . '/../lib/EduReminder.php';
require_once __DIR__ . '/../lib/EduAutoEnroll.php';
require_once __DIR__ . '/../lib/SurveyMailer.php';
require_once __DIR__ . '/../lib/UserPasswordTokens.php';

$GLOBALS['__MAILS'] = [];
EduMailer::useTransport(static function (string $to, string $subject, string $body): bool {
    $GLOBALS['__MAILS'][] = ['to' => $to, 'subject' => $subject, 'body' => $body];
    return true;
});
function takeMails(): array
{
    $m = $GLOBALS['__MAILS'];
    $GLOBALS['__MAILS'] = [];
    return $m;
}

// ---------------------------------------------------------------------------
// 以前(9156f11)の直書きの組み立て。変えずに写す。
// ---------------------------------------------------------------------------
function legacy_edu_invite(string $rawName, string $title, string $token): array
{
    $name = trim($rawName);
    $greeting = $name !== '' ? ($name . ' 様') : 'ご担当者 様';
    $body = $greeting . "\n\n"
        . 'セキュリティ教育「' . $title . "」が配信されました。\n"
        . "下記URLよりご受講ください（所要5〜10分・ログイン不要）。\n\n"
        . EduMailer::takeUrl($token) . "\n\n"
        . "※本メールは自動送信です。ご不明点は管理者へお問い合わせください。\n";
    return ['subject' => '【受講のご案内】' . $title, 'body' => $body];
}
function legacy_edu_followup(string $name, string $title, string $token): array
{
    $greeting = trim($name) !== '' ? (trim($name) . ' 様') : 'ご担当者 様';
    $body = $greeting . "\n\n"
        . "先日の標的型メール訓練の結果にもとづき、フォローアップ教育「" . $title . "」をご案内します。\n"
        . "訓練で気づけなかった点を短時間で確認できます。下記URLよりご受講ください"
        . "（所要5〜10分・ログイン不要）。\n\n"
        . EduMailer::takeUrl($token) . "\n\n"
        . "※本メールは自動送信です。ご不明点は管理者へお問い合わせください。\n";
    return ['subject' => '【受講のご案内】' . $title, 'body' => $body];
}
function legacy_edu_remind(string $rawName, string $title, string $token): array
{
    $url = EduMailer::takeUrl($token);
    $name = trim($rawName);
    $greeting = $name !== '' ? ($name . ' 様') : 'ご担当者 様';
    $mailBody = $greeting . "\n\n"
        . 'セキュリティ教育「' . $title . "」が未受講です。\n"
        . "下記URLよりご受講ください（所要5〜10分・ログイン不要）。\n\n"
        . $url . "\n\n"
        . "※本メールは自動送信です。ご不明点は管理者へお問い合わせください。\n";
    return ['subject' => '【受講のお願い】' . $title, 'body' => $mailBody];
}
function legacy_edu_remind_auto(string $rawName, string $title, string $token): array
{
    $name = trim($rawName);
    $greeting = $name !== '' ? ($name . ' 様') : 'ご担当者 様';
    $url = EduMailer::takeUrl($token);
    $body = $greeting . "\n\n"
        . 'セキュリティ教育「' . $title . "」が未受講です。\n"
        . "お手数ですが下記URLよりご受講ください（所要5〜10分・ログイン不要）。\n\n"
        . $url . "\n\n"
        . "※本メールは自動送信です。ご不明点は管理者へお問い合わせください。\n";
    return ['subject' => '【受講のお願い(リマインド)】' . $title, 'body' => $body];
}
function legacy_survey(string $rawName, string $title, string $token, ?string $deadline, bool $reminder): array
{
    $name = trim($rawName);
    $subject = ($reminder ? '【回答のお願い（締切間近）】' : '【アンケートのお願い】') . $title;
    $body = ($name !== '' ? $name . ' 様' : 'ご担当者 様') . "\n\n"
        . 'アンケート「' . $title . "」へのご協力をお願いします。\n"
        . "下記の URL から回答できます（ログイン不要）。\n\n"
        . SurveyMailer::surveyUrl($token) . "\n\n"
        . ($deadline !== null ? '回答の締切: ' . substr((string) $deadline, 0, 16) . "\n\n" : '')
        . "※本メールは自動送信です。ご不明点は管理者へお問い合わせください。\n";
    return ['subject' => $subject, 'body' => $body];
}
function legacy_admin(array $user, string $token, string $purpose, string $expiresAt): array
{
    $name = trim((string) ($user['name'] ?? ''));
    if ($purpose === 'invite') {
        $subject = '【TET v2】管理画面のアカウントのパスワード設定のお願い';
        $lead = "TET v2（標的型メール訓練・教育の管理画面）のアカウントが作られました。\n"
            . "下記の URL を開き、パスワードを設定してください。\n";
    } else {
        $subject = '【TET v2】パスワード再設定のご案内';
        $lead = "TET v2（標的型メール訓練・教育の管理画面）のパスワードの再設定を、管理者が受け付けました。\n"
            . "下記の URL を開き、新しいパスワードを設定してください。\n";
    }
    $body = ($name !== '' ? $name . ' 様' : 'ご担当者 様') . "\n\n"
        . $lead . "\n"
        . UserPasswordTokens::url($token) . "\n\n"
        . 'ログインに使うメールアドレス: ' . (string) $user['email'] . "\n"
        . ($expiresAt !== '' ? 'リンクの有効期限: ' . $expiresAt . '（72時間。1回だけ使えます）' . "\n" : '')
        . 'パスワードの決まり: ' . PasswordPolicy::DESCRIPTION . "\n\n"
        . "お心当たりがない場合は、このメールを破棄してください。\n"
        . "※本メールは自動送信です。ご不明点は管理者へお問い合わせください。\n";
    return ['subject' => $subject, 'body' => $body];
}
function legacy_learner(array $user, string $token, string $purpose, string $expiresAt): array
{
    $name = trim((string) ($user['name'] ?? ''));
    if ($purpose === 'invite') {
        $subject = '【セキュリティ教育】マイページのパスワード設定のお願い';
        $lead = "セキュリティ教育の受講者のマイページをご用意しました。\n"
            . "マイページでは、受講する教育と回答するアンケート、ご自分の成績を確認でき、教育を受け直せます。\n"
            . "下記の URL を開き、パスワードを設定してください。\n";
    } else {
        $subject = '【セキュリティ教育】マイページのパスワード再設定のご案内';
        $lead = "セキュリティ教育の受講者のマイページのパスワードの再設定を受け付けました。\n"
            . "下記の URL を開き、新しいパスワードを設定してください。\n";
    }
    $shared = (string) ($user['role'] ?? '') !== 'learner'
        ? "※このパスワードは管理画面のパスワードと共通です（同じアカウントです）。\n" : '';
    $body = ($name !== '' ? $name . ' 様' : 'ご担当者 様') . "\n\n"
        . $lead . "\n"
        . UserPasswordTokens::url($token, 'my') . "\n\n"
        . 'ログインに使うメールアドレス: ' . (string) $user['email'] . "\n"
        . ($expiresAt !== '' ? 'リンクの有効期限: ' . $expiresAt . '（72時間。1回だけ使えます）' . "\n" : '')
        . 'パスワードの決まり: ' . PasswordPolicy::DESCRIPTION . "\n"
        . 'マイページ: ' . UserPasswordTokens::learnerBaseUrl() . "/my.php\n"
        . $shared . "\n"
        . "お心当たりがない場合は、このメールを破棄してください。\n"
        . "※本メールは自動送信です。ご不明点は社内の担当者へお問い合わせください。\n";
    return ['subject' => $subject, 'body' => $body];
}

// ---------------------------------------------------------------------------
// DF: 既定の文面 = 以前の文面(レンダラを直接呼ぶ。値の組み合わせを総当たり)
// ---------------------------------------------------------------------------
check(count(NotificationTemplates::KINDS) === 19 && array_keys(NotificationTemplates::definitions()) === NotificationTemplates::KINDS,
    'DF-0: 通知の種類は19個(段D の種明かし3つと報告の通知、報告者への返信4つと集計通知1つを含む)で、一覧と定義が一致する');
$names = ['見本 花子', '', '  前後に空白  ', '{受講URL}'];
$titles = ['標的型メールの見分け方', '  前後に空白の題  ', '題に{氏名}と{配信名}', "改行\nを含む題"];
$token = str_repeat('ab', 16);
$n = 0;
foreach ($names as $name) {
    foreach ($titles as $title) {
        foreach ([null, '2026-12-01 17:00:00', '2026-12-01'] as $deadline) {
            $vars = NotificationTemplates::eduVars($name, $title, $token, $deadline);
            foreach ([
                'edu_invite' => legacy_edu_invite($name, $title, $token),
                'edu_followup_invite' => legacy_edu_followup($name, $title, $token),
                'edu_reminder' => legacy_edu_remind($name, $title, $token),
                'edu_reminder_auto' => legacy_edu_remind_auto($name, $title, $token),
            ] as $kind => $legacy) {
                $got = NotificationTemplates::render(1, $kind, $vars);
                // 件名の改行は以前も EduMailer::send が取り除いていた(送る件名は同じ)
                $legacy['subject'] = str_replace(["\r", "\n"], '', $legacy['subject']);
                if ($got !== $legacy) {
                    throw new RuntimeException("FAIL: DF-1: {$kind} name=" . json_encode($name, JSON_UNESCAPED_UNICODE)
                        . ' title=' . json_encode($title, JSON_UNESCAPED_UNICODE) . "\n" . json_encode([$got, $legacy], JSON_UNESCAPED_UNICODE));
                }
                $n++;
            }
            foreach ([false, true] as $reminder) {
                $legacy = legacy_survey($name, $title, $token, $deadline, $reminder);
                $legacy['subject'] = str_replace(["\r", "\n"], '', $legacy['subject']);
                $got = NotificationTemplates::render(1, $reminder ? 'survey_reminder' : 'survey_invite', [
                    '氏名' => $name, 'アンケート名' => $title, '回答URL' => SurveyMailer::surveyUrl($token),
                    '期限' => $deadline !== null ? substr($deadline, 0, 16) : '',
                ]);
                if ($got !== $legacy) {
                    throw new RuntimeException('FAIL: DF-2: survey ' . json_encode([$got, $legacy], JSON_UNESCAPED_UNICODE));
                }
                $n++;
            }
        }
    }
}
check($n === 4 * 4 * 3 * 6, "DF-1/2: 教育4種とアンケート2種の既定の文面が以前と同じ({$n}通り)");

$n = 0;
foreach (['見本 花子', '', ' 空白 '] as $name) {
    foreach (['admin@example.test'] as $email) {
        foreach (['2026-12-01 09:00', ''] as $expiresAt) {
            foreach (['invite', 'reset'] as $purpose) {
                foreach (['learner', 'tenant_admin', 'operator'] as $role) {
                    $user = ['name' => $name, 'email' => $email, 'role' => $role, 'tenant_id' => 1];
                    foreach (['admin', 'my'] as $site) {
                        if ($site === 'my') {
                            $legacy = legacy_learner($user, $token, $purpose, $expiresAt);
                            $kind = $purpose === 'invite' ? 'learner_invite' : 'learner_reset';
                            $vars = ['マイページURL' => UserPasswordTokens::learnerBaseUrl() . '/my.php',
                                'アカウントの注記' => $role !== 'learner' ? '※このパスワードは管理画面のパスワードと共通です（同じアカウントです）。' : ''];
                        } else {
                            $legacy = legacy_admin($user, $token, $purpose, $expiresAt);
                            $kind = $purpose === 'invite' ? 'admin_invite' : 'admin_reset';
                            $vars = [];
                        }
                        $got = NotificationTemplates::render(1, $kind, $vars + [
                            '氏名' => $name, '設定URL' => UserPasswordTokens::url($token, $site), 'メールアドレス' => $email,
                            '有効期限' => $expiresAt, 'パスワードの決まり' => PasswordPolicy::DESCRIPTION,
                        ]);
                        if ($got !== $legacy) {
                            throw new RuntimeException("FAIL: DF-3: {$kind} " . json_encode([$got, $legacy], JSON_UNESCAPED_UNICODE));
                        }
                        $n++;
                    }
                }
            }
        }
    }
}
check($n === 3 * 2 * 2 * 3 * 2, "DF-3: 招待と再設定の4種の既定の文面が以前と同じ({$n}通り)");

// ---------------------------------------------------------------------------
// SP: 実際の送信口を通しても以前と同じ
// ---------------------------------------------------------------------------
Db::run("INSERT INTO targets (id, tenant_id, tenant_no, email, name, status) VALUES (50, 1, 50, 'noname@example.test', '', 'active')");
Db::run("INSERT INTO targets (id, tenant_id, tenant_no, email, name, status) VALUES (51, 2, 51, 'other51@other.example.test', 'Other Fifty', 'active')");
$did = Db::insert("INSERT INTO edu_deliveries (tenant_id, title, status, send_invites, deadline) VALUES (1, '送信口の確認', 'running', 1, '2026-12-01 17:00:00')");
$tok1 = str_repeat('1', 32);
$tok2 = str_repeat('2', 32);
Db::run('INSERT INTO edu_assignments (tenant_id, delivery_id, target_id, access_token) VALUES (1, ?, 1, ?), (1, ?, 50, ?)', [$did, $tok1, $did, $tok2]);

takeMails();
check(EduDeliveryLauncher::sendInvites($did, 1, '送信口の確認', [$tok1, $tok2]) === 2, 'SP-1: 配信の開始の案内を2通送る');
$mails = takeMails();
check($mails[0]['subject'] === legacy_edu_invite('Target One', '送信口の確認', $tok1)['subject']
    && $mails[0]['body'] === legacy_edu_invite('Target One', '送信口の確認', $tok1)['body']
    && $mails[1]['body'] === legacy_edu_invite('', '送信口の確認', $tok2)['body'], 'SP-1: 開始の案内は以前と同じ文面');

$m = new ReflectionMethod(EduAutoEnroll::class, 'sendInvites');
$aid = (int) Db::one('SELECT id FROM edu_assignments WHERE access_token = ?', [$tok1])['id'];
$m->invoke(null, [$aid], $did, 1);
$mails = takeMails();
$legacy = legacy_edu_followup('Target One', '送信口の確認', $tok1);
check(count($mails) === 1 && $mails[0]['subject'] === $legacy['subject'] && $mails[0]['body'] === $legacy['body'],
    'SP-2: 訓練後の自動の割り当ての案内は以前と同じ文面');

load_api('edu_deliveries');
$r = call_handler('edu_d_handle_remind', ['id' => $did], 'operator');
$mails = takeMails();
check($r['code'] === 200 && count($mails) === 2 && $mails[0]['body'] === legacy_edu_remind('Target One', '送信口の確認', $tok1)['body']
    && $mails[0]['subject'] === legacy_edu_remind('Target One', '送信口の確認', $tok1)['subject']
    && $mails[1]['body'] === legacy_edu_remind('', '送信口の確認', $tok2)['body'], 'SP-3: 画面の催促は以前と同じ文面');

Db::run('UPDATE edu_assignments SET last_reminded_at = NULL WHERE delivery_id = ?', [$did]);
Db::run("UPDATE edu_deliveries SET deadline = datetime('now','localtime','+3 days') WHERE id = ?", [$did]);
$r = EduReminder::run();
$mails = takeMails();
check($r['sent'] === 2 && $mails[0]['subject'] === legacy_edu_remind_auto('Target One', '送信口の確認', $tok1)['subject']
    && $mails[0]['body'] === legacy_edu_remind_auto('Target One', '送信口の確認', $tok1)['body']
    && $mails[1]['body'] === legacy_edu_remind_auto('', '送信口の確認', $tok2)['body'], 'SP-4: 自動の催促は以前と同じ文面');

Db::run("INSERT INTO groups (id, tenant_id, name, kind) VALUES (10, 1, '全職員', 'all')");
$sid = SurveyService::createSurvey(1, 1, ['title' => '文面の確認', 'questions' => [
    ['question_type' => 'single', 'title' => 'q', 'options' => ['a', 'b'], 'is_required' => true],
]]);
putenv('TET2_SURVEY_MAIL_ENABLED=1');
$surveyDeadline = date('Y-m-d H:i:s', time() + 86400);
$sd = SurveyService::createDelivery(1, $sid, 1, '期限あり', $surveyDeadline, [10]);
$sdNo = SurveyService::createDelivery(1, $sid, 1, '期限なし', null, [10]);
SurveyMailer::sendInvitations(1, $sd['delivery_id']);
SurveyMailer::sendInvitations(1, $sdNo['delivery_id']);
$mails = takeMails();
$tokens = Db::all('SELECT a.delivery_id, a.access_token, t.name FROM survey_assignments a JOIN targets t ON t.id = a.target_id ORDER BY a.id');
$ok = count($mails) === count($tokens) && count($mails) >= 4;
foreach ($tokens as $i => $t) {
    $legacy = legacy_survey((string) $t['name'], '文面の確認', (string) $t['access_token'],
        (int) $t['delivery_id'] === (int) $sd['delivery_id'] ? $surveyDeadline : null, false);
    $ok = $ok && $mails[$i]['subject'] === $legacy['subject'] && $mails[$i]['body'] === $legacy['body'];
}
check($ok, 'SP-5: アンケートの案内(期限あり・なし)は以前と同じ文面');
SurveyMailer::remind(1);
$mails = takeMails();
$ok = count($mails) >= 2;
foreach ($mails as $mail) {
    $row = null;
    foreach ($tokens as $t) {
        if (str_contains($mail['body'], (string) $t['access_token'])) {
            $row = $t;
        }
    }
    $legacy = legacy_survey((string) $row['name'], '文面の確認', (string) $row['access_token'], $surveyDeadline, true);
    $ok = $ok && $mail['subject'] === $legacy['subject'] && $mail['body'] === $legacy['body'];
}
check($ok, 'SP-6: アンケートの催促は以前と同じ文面');
putenv('TET2_SURVEY_MAIL_ENABLED');

Db::run("INSERT INTO users (id, tenant_id, email, password_hash, name, role, status, password_pending)
         VALUES (30, 1, 'nt-admin@example.test', 'x', 'NT Admin', 'tenant_admin', 'active', 0),
                (31, 1, 'nt-learner@example.test', 'x', '', 'learner', 'active', 1),
                (32, NULL, 'nt-root@example.test', 'x', 'NT Root', 'superadmin', 'active', 0),
                (33, 2, 'nt-other@other.example.test', 'x', 'NT Other', 'tenant_admin', 'active', 0)");
$ok = true;
foreach ([30, 31, 32] as $uid) {
    $user = Db::one('SELECT * FROM users WHERE id = ?', [$uid]);
    foreach (['invite', 'reset'] as $purpose) {
        foreach (['admin', 'my'] as $site) {
            $tok = UserPasswordTokens::issue($uid, $purpose, null);
            check(UserPasswordTokens::sendMail($user, $tok, $purpose, $site), "SP-7: {$uid} {$purpose} {$site} を送れる");
            $exp = substr((string) Db::one('SELECT expires_at FROM user_password_tokens WHERE token_hash = ?', [UserPasswordTokens::hashToken($tok)])['expires_at'], 0, 16);
            $legacy = $site === 'my' ? legacy_learner($user, $tok, $purpose, $exp) : legacy_admin($user, $tok, $purpose, $exp);
            $mail = takeMails()[0];
            $ok = $ok && $mail['subject'] === $legacy['subject'] && $mail['body'] === $legacy['body'] && $mail['to'] === $user['email'];
        }
    }
}
check($ok, 'SP-7: 招待と再設定(管理画面・マイページ、受講者・管理者・システム管理者)は以前と同じ文面');

// ---------------------------------------------------------------------------
// VL: 保存の検証
// ---------------------------------------------------------------------------
function expectInvalid(callable $fn, string $needle, string $message): void
{
    try {
        $fn();
    } catch (NotificationTemplateException $e) {
        check(str_contains($e->getMessage(), $needle), $message . '（' . $e->getMessage() . '）');
        return;
    }
    throw new RuntimeException('FAIL: ' . $message . ': 拒まれなかった');
}
expectInvalid(fn() => NotificationTemplates::validate('edu_invite', '件名', "{氏名} 様\n{受講URL}\n{パスワード}"), '{パスワード}',
    'VL-1: 一覧にない差し込みは拒む');
expectInvalid(fn() => NotificationTemplates::validate('edu_invite', '件名 {回答URL}', "{受講URL}"), '{回答URL}',
    'VL-1: 別の種類の差し込み(件名)も拒む');
expectInvalid(fn() => NotificationTemplates::validate('edu_invite', '件名', "{氏名} 様\nURL なし"), '{受講URL}',
    'VL-2: 受講の URL の差し込みを消した本文は拒む');
expectInvalid(fn() => NotificationTemplates::validate('survey_invite', '件名', "{受講URL}"), '{受講URL}',
    'VL-2: アンケートは回答の URL が要る(受講の URL は使えない)');
expectInvalid(fn() => NotificationTemplates::validate('admin_reset', '件名', "{氏名}"), '{設定URL}',
    'VL-2: 再設定は設定の URL が要る');
expectInvalid(fn() => NotificationTemplates::validate('edu_invite', '', "{受講URL}"), '件名', 'VL-3: 空の件名は拒む');
expectInvalid(fn() => NotificationTemplates::validate('edu_invite', str_repeat('あ', 201), "{受講URL}"), '200', 'VL-3: 長すぎる件名は拒む');
expectInvalid(fn() => NotificationTemplates::validate('edu_invite', 's', "{受講URL}" . str_repeat('あ', 5000)), '5000', 'VL-3: 長すぎる本文は拒む');
expectInvalid(fn() => NotificationTemplates::validate('nope', 's', 'b'), '種類', 'VL-3: 知らない種類は拒む');
$v = NotificationTemplates::validate('edu_invite', "  件名\r\nBcc: evil@example.test ", "{受講URL}\r\n");
check($v['subject'] === '件名Bcc: evil@example.test' && $v['body'] === "{受講URL}\n", 'VL-4: 保存する件名から CR/LF を除き、本文の改行は LF に揃える');
$r = NotificationTemplates::renderText('edu_invite', '{配信名}', '{受講URL}', ['配信名' => "題\r\nBcc: evil@example.test", '受講URL' => 'u']);
check(!str_contains($r['subject'], "\n") && !str_contains($r['subject'], "\r"), 'VL-4: 差し込んだ値の改行も件名に残さない');
$r = NotificationTemplates::renderText('edu_invite', 's', "問い合わせ先: {問い合わせ先}\n{受講URL}\n", ['受講URL' => 'u', '問い合わせ先' => '']);
check($r['body'] === "u\n", 'VL-5: 値が空の省略できる差し込み(問い合わせ先)の行は消す');

// ---------------------------------------------------------------------------
// OV: テナントの上書きが送信に効く
// ---------------------------------------------------------------------------
Db::run("UPDATE tenants SET edu_contact = '情報システム部 内線 0000' WHERE id = 1");
NotificationTemplates::save(1, 'edu_invite', '[{組織名}] {配信名} のご案内', "{氏名} さん\n{配信名}\n{受講URL}\n期限: {期限}\n問い合わせ: {問い合わせ先}\n", 'nt-admin@example.test');
EduDeliveryLauncher::sendInvites($did, 1, '送信口の確認', [$tok1]);
$mail = takeMails()[0];
$deadlineNow = substr((string) Db::one('SELECT deadline FROM edu_deliveries WHERE id = ?', [$did])['deadline'], 0, 16);
check($mail['subject'] === '[Example Tenant] 送信口の確認 のご案内'
    && $mail['body'] === "Target One さん\n送信口の確認\n" . EduMailer::takeUrl($tok1) . "\n期限: {$deadlineNow}\n問い合わせ: 情報システム部 内線 0000\n",
    'OV-1: テナントの上書きで送る(組織名・期限・問い合わせ先も差し込む)');
$did2 = Db::insert("INSERT INTO edu_deliveries (tenant_id, title, status, send_invites) VALUES (2, '他テナント', 'running', 1)");
$tok3 = str_repeat('3', 32);
Db::run('INSERT INTO edu_assignments (tenant_id, delivery_id, target_id, access_token) VALUES (2, ?, 51, ?)', [$did2, $tok3]);
EduDeliveryLauncher::sendInvites($did2, 2, '他テナント', [$tok3]);
$mail = takeMails()[0];
check($mail['body'] === legacy_edu_invite('Other Fifty', '他テナント', $tok3)['body'], 'OV-2: ほかのテナントの送信には効かない(既定の文面)');
$u = Db::one('SELECT * FROM users WHERE id = 32');
$tok = UserPasswordTokens::issue(32, 'reset', null);
NotificationTemplates::save(1, 'admin_reset', '上書き', "{設定URL}", null);
UserPasswordTokens::sendMail($u, $tok, 'reset');
check(takeMails()[0]['subject'] === '【TET v2】パスワード再設定のご案内', 'OV-3: システム管理者(テナントなし)は既定の文面');
check(NotificationTemplates::reset(1, 'edu_invite') === true && NotificationTemplates::current(1, 'edu_invite')['customized'] === false,
    'OV-4: 既定に戻すと上書きの行を消す');
EduDeliveryLauncher::sendInvites($did, 1, '送信口の確認', [$tok1]);
check(takeMails()[0]['body'] === legacy_edu_invite('Target One', '送信口の確認', $tok1)['body'], 'OV-4: 戻した後は以前と同じ文面');
NotificationTemplates::reset(1, 'admin_reset');

// ---------------------------------------------------------------------------
// API
// ---------------------------------------------------------------------------
load_api('notification_templates');
$r = call_handler('nt_handle_list', [], 'operator');
check($r['code'] === 403, 'API-1: オペレータは一覧を見られない');
$r = call_handler('nt_handle_save', ['kind' => 'edu_invite', 'subject' => 's', 'body' => '{受講URL}'], 'viewer');
check($r['code'] === 403, 'API-1: 閲覧者は保存できない');
foreach (['nt_handle_reset', 'nt_handle_preview', 'nt_handle_test_send'] as $fn) {
    check(call_handler($fn, ['kind' => 'edu_invite'], 'operator')['code'] === 403, "API-1: オペレータは {$fn} を使えない");
}
$r = call_handler('nt_handle_list', [], 'tenant_admin');
$items = array_column($r['payload']['items'], null, 'kind');
check($r['code'] === 200 && count($items) === 19 && $items['edu_invite']['customized'] === false
    && $items['edu_invite']['body'] === $items['edu_invite']['default_body']
    && in_array('受講URL', array_column($items['edu_invite']['variables'], 'name'), true)
    && $items['edu_invite']['required'] === ['受講URL'], 'API-2: 組織管理者は種類ごとの今の文面と差し込みの一覧を見られる');

$r = call_handler('nt_handle_save', ['kind' => 'edu_reminder', 'subject' => "催促 {配信名}\nBcc: x@example.test", 'body' => "{氏名} 様\n{受講URL}\n"], 'tenant_admin');
check($r['code'] === 200 && $GLOBALS['__TET2_TEST_CSRF_CALLS'] === 1 && $r['payload']['subject'] === '催促 {配信名}Bcc: x@example.test',
    'API-3: 保存できる(CSRF を確かめ、件名の改行を除く)');
check(($GLOBALS['__TET2_TEST_AUDIT'][0]['action'] ?? '') === 'notification_template.save', 'API-3: 保存は監査ログに残す');
$r = call_handler('nt_handle_save', ['kind' => 'edu_reminder', 'subject' => 's', 'body' => '{氏名} だけ'], 'tenant_admin');
check($r['code'] === 400 && str_contains($r['payload']['error'], '{受講URL}'), 'API-4: URL の差し込みを消した保存は 400');
$r = call_handler('nt_handle_save', ['kind' => 'edu_reminder', 'subject' => 's', 'body' => '{受講URL} {社長名}'], 'tenant_admin');
check($r['code'] === 400 && str_contains($r['payload']['error'], '{社長名}'), 'API-4: 知らない差し込みの保存は 400');
$r = call_handler('nt_handle_save', ['kind' => 'nope', 'subject' => 's', 'body' => 'b'], 'tenant_admin');
check($r['code'] === 400, 'API-4: 知らない種類は 400');
check(NotificationTemplates::current(1, 'edu_reminder')['body'] === "{氏名} 様\n{受講URL}\n", 'API-4: 拒んだ保存は前の上書きを変えない');

// テナントの分離
$r = call_handler('nt_handle_save', ['kind' => 'edu_invite', 'subject' => 's', 'body' => '{受講URL}', 'tenant_id' => 2], 'tenant_admin');
check($r['code'] === 403, 'API-5: 組織管理者はほかのテナントの文面を変えられない');
$GLOBALS['__TET2_TEST_BODY'] = [];
$_GET['tenant_id'] = '2';
$r = call_handler('nt_handle_list', [], 'tenant_admin');
check($r['code'] === 403, 'API-5: 組織管理者はほかのテナントの文面を見られない');
$r = call_handler('nt_handle_list', [], 'superadmin');
$items2 = array_column($r['payload']['items'], null, 'kind');
check($r['code'] === 200 && $r['payload']['tenant_id'] === 2 && $items2['edu_reminder']['customized'] === false,
    'API-5: システム管理者は指定したテナントの文面を見る(テナント1の上書きは見えない)');
unset($_GET['tenant_id']);
$r = call_handler('nt_handle_save', ['kind' => 'edu_reminder', 'subject' => '二番目', 'body' => '{受講URL}', 'tenant_id' => 2], 'superadmin');
check($r['code'] === 200 && NotificationTemplates::current(2, 'edu_reminder')['subject'] === '二番目'
    && NotificationTemplates::current(1, 'edu_reminder')['subject'] === '催促 {配信名}Bcc: x@example.test',
    'API-5: システム管理者はテナントを指定して保存し、ほかのテナントの上書きは変わらない');
$r = call_handler('nt_handle_save', ['kind' => 'edu_reminder', 'subject' => 's', 'body' => '{受講URL}', 'tenant_id' => 999], 'superadmin');
check($r['code'] === 404, 'API-5: ないテナントは 404');

// プレビュー
$r = call_handler('nt_handle_preview', ['kind' => 'edu_reminder'], 'tenant_admin');
check($r['code'] === 200 && str_starts_with($r['payload']['body'], "見本 太郎 様\n") && str_contains($r['payload']['body'], 'sample-token-for-preview')
    && !str_contains($r['payload']['body'], '{'), 'API-6: プレビューは保存した文面に見本の値を差し込む');
$r = call_handler('nt_handle_preview', ['kind' => 'survey_invite', 'subject' => '下書き {アンケート名}', 'body' => "{回答URL}\n締切 {期限}\n"], 'tenant_admin');
check($r['code'] === 200 && $r['payload']['subject'] === '下書き 見本のアンケート' && str_contains($r['payload']['body'], '締切 20'),
    'API-6: 保存前の下書きもプレビューできる');
$r = call_handler('nt_handle_preview', ['kind' => 'survey_invite', 'body' => '{受講URL}'], 'tenant_admin');
check($r['code'] === 400, 'API-6: 使えない差し込みの下書きのプレビューは 400');
check(takeMails() === [], 'API-6: プレビューはメールを送らない');

// 既定に戻す
$r = call_handler('nt_handle_reset', ['kind' => 'edu_reminder'], 'tenant_admin');
check($r['code'] === 200 && $r['payload']['customized'] === false && NotificationTemplates::current(1, 'edu_reminder')['customized'] === false
    && NotificationTemplates::current(2, 'edu_reminder')['customized'] === true, 'API-7: 既定に戻すのは自テナントの上書きだけ');

// ---------------------------------------------------------------------------
// TS: テスト送信
// ---------------------------------------------------------------------------
takeMails();
$r = call_handler('nt_handle_test_send', ['kind' => 'edu_invite', 'to' => 'victim@example.test', 'email' => 'victim@example.test'], 'tenant_admin');
$mails = takeMails();
check($r['code'] === 200 && $r['payload']['to'] === 'operator@example.test' && count($mails) === 1
    && $mails[0]['to'] === 'operator@example.test' && str_starts_with($mails[0]['subject'], '[テスト送信] 【受講のご案内】'),
    'TS-1: テスト送信はログインしている本人のアドレスにだけ送る(宛先の指定は無視する)');
$row = Db::one("SELECT tenant_id, user_id, detail FROM audit_log WHERE action = 'notification_template.test_send' ORDER BY id DESC LIMIT 1");
check($row !== null && (int) $row['user_id'] === 1 && (int) $row['tenant_id'] === 1 && str_contains((string) $row['detail'], 'kind=edu_invite'),
    'TS-2: テスト送信は監査ログに残す');
$r = call_handler('nt_handle_test_send', ['kind' => 'edu_invite', 'subject' => '下書き', 'body' => '{受講URL}'], 'tenant_admin');
$mails = takeMails();
check($r['code'] === 200 && $mails[0]['subject'] === '[テスト送信] 下書き' && str_contains($mails[0]['body'], 'sample-token-for-preview'),
    'TS-3: 保存前の下書きもテスト送信できる(見本の URL)');
$r = call_handler('nt_handle_test_send', ['kind' => 'edu_invite', 'body' => 'URL なし'], 'tenant_admin');
check($r['code'] === 400 && takeMails() === [], 'TS-3: 検証を通らない下書きは送らない');
for ($i = 0; $i < 3; $i++) {
    check(call_handler('nt_handle_test_send', ['kind' => 'admin_invite'], 'tenant_admin')['code'] === 200, 'TS-4: ' . (3 + $i) . '回目までは送れる');
}
$r = call_handler('nt_handle_test_send', ['kind' => 'admin_invite'], 'tenant_admin');
check($r['code'] === 429 && count(takeMails()) === 3, 'TS-4: 10分に5回を超えるテスト送信は 429 で送らない');
Db::run("UPDATE audit_log SET occurred_at = datetime('now','localtime','-11 minutes') WHERE action = 'notification_template.test_send'");
check(call_handler('nt_handle_test_send', ['kind' => 'admin_invite'], 'tenant_admin')['code'] === 200, 'TS-4: 10分を過ぎれば、また送れる');
takeMails();
EduMailer::useTransport(static fn(): bool => false);
$r = call_handler('nt_handle_test_send', ['kind' => 'admin_invite'], 'tenant_admin');
check($r['code'] === 502, 'TS-5: 送れなかった時は 502');
EduMailer::useTransport(null);
check(getenv('TET2_EDU_MAIL_DISABLE') === '1', 'TS-6: このテストは SMTP へ投函しない(TET2_EDU_MAIL_DISABLE=1 と送信口の差し替え)');

echo "ALL TESTS PASSED\n";
