<?php declare(strict_types=1); require __DIR__."/../lib/bootstrap.php";
require_once __DIR__ . '/../lib/SuspiciousMailReplies.php';

/**
 * 不審メールの報告者への定型文の返信(段D の D4、G10)。オペレータ以上。自動では送らない。
 *
 *   GET  ?action=options&id=  定型文ごとの見本(実際に送る件名と本文)、送れるか、送れない理由、送った記録
 *   POST ?action=send         {id, kind} をその報告の報告者へ1通だけ送る(宛先は指定できない)
 *
 * テナントの範囲は不審メールの API(suspicious_mails.php)と同じ。「訓練メールでした」は訓練を閉じた後だけ送れる。
 */

function smr_tenant(array $actor): ?int
{
    // システム管理者は tenant_id を指定しなければ全テナント横断(不審メールの API と同じ)
    $requested = isset($_GET['tenant_id']) && $_GET['tenant_id'] !== '' ? (int) $_GET['tenant_id'] : null;
    if ($actor['role'] === 'superadmin' && $requested === null) {
        return null;
    }
    return effective_tenant_id($actor, $requested);
}

function smr_handle_options(): never
{
    $actor = require_role('operator');
    $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false || $id === null) {
        json_error('id が不正です', 400);
    }
    try {
        $options = SuspiciousMailReplies::options((int) $id, smr_tenant($actor));
    } catch (DomainException $e) {
        json_error($e->getMessage(), $e->getCode() ?: 400);
    }
    json_out(['success' => true] + $options);
}

function smr_handle_send(): never
{
    $actor = require_role('operator');
    tet2_require_csrf();
    $body = json_body();
    if (!isset($body['id']) || !is_int($body['id']) || $body['id'] < 1) {
        json_error('id が不正です', 400);
    }
    if (!is_string($body['kind'] ?? null)) {
        json_error('kind が不正です', 400);
    }
    try {
        $result = SuspiciousMailReplies::send($body['id'], smr_tenant($actor), $body['kind'], (string) $actor['email']);
    } catch (DomainException $e) {
        json_error($e->getMessage(), $e->getCode() ?: 400);
    }
    // 宛先のアドレスは監査ログに書かない(返信の記録の表に残る)
    audit('suspicious_mail.reply', 'id=' . $body['id'] . ',kind=' . $result['kind'] . ',reply_id=' . $result['reply_id']);
    json_out(['success' => true] + $result);
}

try {
    $action = $_GET['action'] ?? '';
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($action === 'options' && $method === 'GET') {
        smr_handle_options();
    }
    if ($action === 'send' && $method === 'POST') {
        smr_handle_send();
    }
    json_error('不正なアクションです', 400);
} catch (Throwable $e) {
    error_log($e->getMessage());
    json_error('サーバエラー', 500);
}
