<?php declare(strict_types=1); require __DIR__."/../lib/bootstrap.php";
require_once __DIR__ . '/../lib/NotificationTemplates.php';
require_once __DIR__ . '/../lib/EduMailer.php';
require_once __DIR__ . '/../lib/UserPasswordTokens.php';

/**
 * 通知の文面(C2、G35)。組織管理者以上。システム管理者はテナントを指定する。
 *
 *   GET  ?action=list        種類ごとの今の件名と本文(上書きか既定)、既定の文面、差し込みの一覧
 *   POST ?action=save        {kind, subject, body} を保存(決まった差し込みだけ。URL の差し込みは消せない)
 *   POST ?action=reset       {kind} の上書きを消して既定に戻す
 *   POST ?action=preview     {kind, subject?, body?} を見本の値で差し込んだ件名と本文(下書きのまま見られる)
 *   POST ?action=test_send   {kind, subject?, body?} を、ログインしている本人のアドレスにだけ送る(10分に5回まで)
 */

function nt_body_optional_int(array $body, string $key): ?int
{
    if (!array_key_exists($key, $body) || $body[$key] === null || $body[$key] === '') {
        return null;
    }
    $v = filter_var($body[$key], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($v === false) {
        json_error($key . ' が不正です', 400);
    }
    return (int) $v;
}

function nt_query_tenant_id(): ?int
{
    if (!isset($_GET['tenant_id']) || $_GET['tenant_id'] === '') {
        return null;
    }
    $v = filter_var($_GET['tenant_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($v === false) {
        json_error('tenant_id が不正です', 400);
    }
    return (int) $v;
}

/** 操作の対象のテナント。存在しないテナントは 404。 */
function nt_tenant(array $actor, ?int $requested): int
{
    $tenantId = effective_tenant_id($actor, $requested);
    if (Db::one('SELECT id FROM tenants WHERE id = ?', [$tenantId]) === null) {
        json_error('テナントが見つかりません', 404);
    }
    return $tenantId;
}

function nt_kind(array $body): string
{
    $kind = $body['kind'] ?? null;
    if (!is_string($kind) || !in_array($kind, NotificationTemplates::KINDS, true)) {
        json_error('通知の種類が正しくありません', 400);
    }
    return $kind;
}

/** 下書き(subject と body)があればそれ、なければテナントの今の文面。 */
function nt_draft(int $tenantId, string $kind, array $body): array
{
    $hasSubject = array_key_exists('subject', $body) && $body['subject'] !== null;
    $hasBody = array_key_exists('body', $body) && $body['body'] !== null;
    if (($hasSubject && !is_string($body['subject'])) || ($hasBody && !is_string($body['body']))) {
        json_error('件名と本文は文字列で指定してください', 400);
    }
    $current = NotificationTemplates::current($tenantId, $kind);
    $subject = $hasSubject ? $body['subject'] : $current['subject'];
    $text = $hasBody ? $body['body'] : $current['body'];
    try {
        return NotificationTemplates::validate($kind, $subject, $text);
    } catch (NotificationTemplateException $e) {
        json_error($e->getMessage(), 400);
    }
}

function nt_handle_list(): never
{
    $actor = require_role('tenant_admin');
    $tenantId = nt_tenant($actor, nt_query_tenant_id());
    $items = [];
    foreach (NotificationTemplates::definitions() as $kind => $def) {
        $cur = NotificationTemplates::current($tenantId, $kind);
        $items[] = [
            'kind' => $kind,
            'label' => $def['label'],
            'used_by' => $def['used_by'],
            'subject' => $cur['subject'],
            'body' => $cur['body'],
            'customized' => $cur['customized'],
            'updated_by' => $cur['updated_by'],
            'updated_at' => $cur['updated_at'],
            'default_subject' => $def['subject'],
            'default_body' => $def['body'],
            'required' => $def['required'],
            'variables' => array_map(static fn(string $n): array => ['name' => $n, 'label' => NotificationTemplates::variableLabel($n)],
                $def['variables']),
        ];
    }
    json_out([
        'success' => true,
        'tenant_id' => $tenantId,
        'items' => $items,
        'limits' => [
            'subject' => NotificationTemplates::SUBJECT_MAX,
            'body' => NotificationTemplates::BODY_MAX,
            'test_send' => NotificationTemplates::TEST_SEND_LIMIT,
            'test_send_minutes' => NotificationTemplates::TEST_SEND_WINDOW_MINUTES,
        ],
    ]);
}

function nt_handle_save(): never
{
    $actor = require_role('tenant_admin');
    tet2_require_csrf();
    $body = json_body();
    $tenantId = nt_tenant($actor, nt_body_optional_int($body, 'tenant_id'));
    $kind = nt_kind($body);
    if (!is_string($body['subject'] ?? null) || !is_string($body['body'] ?? null)) {
        json_error('件名と本文を指定してください', 400);
    }
    try {
        $saved = NotificationTemplates::save($tenantId, $kind, $body['subject'], $body['body'], (string) ($actor['email'] ?? ''));
    } catch (NotificationTemplateException $e) {
        json_error($e->getMessage(), 400);
    }
    audit('notification_template.save', 'tenant_id=' . $tenantId . ',kind=' . $kind
        . ',subject_length=' . mb_strlen($saved['subject']) . ',body_length=' . mb_strlen($saved['body']));
    json_out(['success' => true, 'kind' => $kind, 'subject' => $saved['subject'], 'body' => $saved['body'], 'customized' => true]);
}

function nt_handle_reset(): never
{
    $actor = require_role('tenant_admin');
    tet2_require_csrf();
    $body = json_body();
    $tenantId = nt_tenant($actor, nt_body_optional_int($body, 'tenant_id'));
    $kind = nt_kind($body);
    $removed = NotificationTemplates::reset($tenantId, $kind);
    audit('notification_template.reset', 'tenant_id=' . $tenantId . ',kind=' . $kind . ',removed=' . ($removed ? 1 : 0));
    $def = NotificationTemplates::definition($kind);
    json_out(['success' => true, 'kind' => $kind, 'subject' => $def['subject'], 'body' => $def['body'], 'customized' => false]);
}

/** 本人のアドレス(セッションではなく users の行から引く)。 */
function nt_own_email(array $actor): string
{
    $row = Db::one("SELECT email FROM users WHERE id = ? AND status = 'active'", [(int) $actor['id']]);
    $email = $row !== null ? trim((string) $row['email']) : '';
    if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        json_error('ログインしているアカウントのメールアドレスに送れません', 409);
    }
    return $email;
}

function nt_handle_preview(): never
{
    $actor = require_role('tenant_admin');
    tet2_require_csrf();
    $body = json_body();
    $tenantId = nt_tenant($actor, nt_body_optional_int($body, 'tenant_id'));
    $kind = nt_kind($body);
    $draft = nt_draft($tenantId, $kind, $body);
    $mail = NotificationTemplates::renderText($kind, $draft['subject'], $draft['body'],
        NotificationTemplates::sampleValues($tenantId, $kind, (string) ($actor['email'] ?? '')));
    json_out(['success' => true, 'kind' => $kind, 'subject' => $mail['subject'], 'body' => $mail['body']]);
}

function nt_handle_test_send(): never
{
    $actor = require_role('tenant_admin');
    tet2_require_csrf();
    $body = json_body();
    $tenantId = nt_tenant($actor, nt_body_optional_int($body, 'tenant_id'));
    $kind = nt_kind($body);
    $draft = nt_draft($tenantId, $kind, $body);
    // 宛先は本人だけ(本文の宛先の指定は受け付けない)
    $to = nt_own_email($actor);
    $userId = (int) $actor['id'];
    if (NotificationTemplates::testSendLimited($userId)) {
        json_error('テスト送信は' . NotificationTemplates::TEST_SEND_WINDOW_MINUTES . '分に'
            . NotificationTemplates::TEST_SEND_LIMIT . '回までです。しばらく待ってから送ってください', 429);
    }
    $mail = NotificationTemplates::renderText($kind, $draft['subject'], $draft['body'],
        NotificationTemplates::sampleValues($tenantId, $kind, $to));
    $ok = EduMailer::send($to, '[テスト送信] ' . $mail['subject'], $mail['body']);
    // 回数の上限は監査ログで数えるので、テストの差し替えの audit() ではなく直接書く(失敗も数える)
    Db::run(
        'INSERT INTO audit_log (tenant_id, user_id, action, detail, ip) VALUES (?,?,?,?,?)',
        [$tenantId, $userId, 'notification_template.test_send',
            'tenant_id=' . $tenantId . ',kind=' . $kind . ',ok=' . ($ok ? 1 : 0), (string) ($_SERVER['REMOTE_ADDR'] ?? '')]
    );
    if (!$ok) {
        json_error('テストのメールを送れませんでした（メールサーバーに接続できないか、宛先が受け付けられませんでした）', 502);
    }
    json_out(['success' => true, 'kind' => $kind, 'to' => $to]);
}

try {
    $action = $_GET['action'] ?? '';
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    // 各ハンドラの先頭で組織管理者以上を確かめる(テストからハンドラだけを呼んでも権限を確かめられるように)
    if ($action === 'list' && $method === 'GET') {
        nt_handle_list();
    }
    if ($method === 'POST') {
        match ($action) {
            'save' => nt_handle_save(),
            'reset' => nt_handle_reset(),
            'preview' => nt_handle_preview(),
            'test_send' => nt_handle_test_send(),
            default => null,
        };
    }
    json_error('不正なアクションです', 400);
} catch (Throwable $e) {
    error_log($e->getMessage());
    json_error('サーバエラー', 500);
}
