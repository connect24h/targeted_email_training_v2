<?php declare(strict_types=1); require __DIR__."/../lib/bootstrap.php";
/**
 * 受講者のマイページのアカウントの管理(L1。管理画面、組織管理者以上)。
 *
 *   GET  learners.php?action=status              対象者ごとのマイページの状態(アカウントの種類、パスワード未設定、最終ログイン)
 *   POST learners.php?action=invite  {target_ids} 選んだ対象者へマイページの招待を送る(1件でも複数件でも)
 *   POST learners.php?action=delete  {target_id}  その対象者の受講者のアカウントを消す(成績は消えない)
 *
 * 招待: 対象者に受講者のアカウント(role=learner、target_id)を作り、パスワード設定のメールを送る
 *   (A1 の UserPasswordTokens。リンクは受講者のサイトのパスワード設定のページ)。既にあれば再送する。
 *   同じテナントに同じメールアドレスの管理画面のユーザがいる人は、そのアカウント(管理画面と同じパスワード)で
 *   マイページに入れるので、アカウントを作らずメールも送らない(1人1アカウント)。
 */

require_once __DIR__ . '/../lib/UserPasswordTokens.php';
require_once __DIR__ . '/../lib/LearnerAuth.php';

const LEARNER_INVITE_MAX = 500;

function learners_query_int(string $key): ?int
{
    if (!isset($_GET[$key]) || $_GET[$key] === '') {
        return null;
    }
    $v = filter_var($_GET[$key], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($v === false) {
        json_error($key . ' が不正です', 400);
    }
    return (int) $v;
}

function learners_body_tenant(array $body): ?int
{
    $v = $body['tenant_id'] ?? null;
    if ($v === null) {
        return null;
    }
    if (!is_int($v) || $v < 1) {
        json_error('tenant_id が不正です', 400);
    }
    return $v;
}

/** 同じテナントで、そのメールアドレスでマイページに入れる管理画面のユーザ。 */
function learners_admin_account(int $tenantId, string $email): ?array
{
    return Db::one(
        "SELECT id, role, status, last_login_at FROM users
         WHERE tenant_id = ? AND lower(email) = lower(?) AND role IN ('viewer','operator','tenant_admin')",
        [$tenantId, $email]
    );
}

function learners_handle_status(array $actor): never
{
    $tenantId = effective_tenant_id($actor, learners_query_int('tenant_id'));
    $rows = Db::all(
        "SELECT t.id AS target_id, u.id AS user_id, u.role, u.status, u.password_pending, u.last_login_at,
                (SELECT MAX(k.expires_at) FROM user_password_tokens k
                  WHERE k.user_id = u.id AND k.used_at IS NULL AND k.revoked_at IS NULL
                    AND k.expires_at > datetime('now','localtime')) AS link_expires_at
         FROM targets t
         INNER JOIN users u ON u.tenant_id = t.tenant_id
              AND ((u.role = 'learner' AND u.target_id = t.id)
                OR (u.role IN ('viewer','operator','tenant_admin') AND lower(u.email) = lower(t.email)))
         WHERE t.tenant_id = ?
         ORDER BY t.id, CASE WHEN u.role = 'learner' THEN 0 ELSE 1 END",
        [$tenantId]
    );
    $out = [];
    foreach ($rows as $r) {
        $tid = (int) $r['target_id'];
        if (isset($out[$tid])) {
            continue;
        }
        $out[$tid] = [
            'target_id' => $tid,
            'account' => (string) $r['role'] === 'learner' ? 'learner' : 'admin',
            'status' => (string) $r['status'],
            'password_pending' => (int) $r['password_pending'] === 1,
            'last_login_at' => $r['last_login_at'],
            'link_expires_at' => $r['link_expires_at'],
        ];
    }
    json_out(['success' => true, 'learners' => array_values($out)]);
}

/**
 * 1人分の招待。結果の行を返す(例外にしない。まとめて送る時に1人の失敗で止めない)。
 * @return array{target_id:int, email:string, result:string, message:string}
 */
function learners_invite_one(array $actor, int $tenantId, int $targetId): array
{
    $target = Db::one("SELECT id, email, name, status FROM targets WHERE id = ? AND tenant_id = ?", [$targetId, $tenantId]);
    if ($target === null) {
        return ['target_id' => $targetId, 'email' => '', 'result' => 'error', 'message' => '対象者が見つかりません'];
    }
    $row = static fn(string $result, string $message): array =>
        ['target_id' => $targetId, 'email' => (string) $target['email'], 'result' => $result, 'message' => $message];
    if ((string) $target['status'] !== 'active') {
        return $row('error', '削除済みの対象者には送れません');
    }
    $email = (string) $target['email'];
    $learner = Db::one("SELECT * FROM users WHERE target_id = ? AND role = 'learner' AND tenant_id = ?", [$targetId, $tenantId]);
    if ($learner === null) {
        if (learners_admin_account($tenantId, $email) !== null) {
            return $row('admin_account', '管理画面のアカウントがあるため送りません。管理画面と同じパスワードでマイページに入れます');
        }
        if (Db::one('SELECT 1 FROM users WHERE lower(email) = lower(?)', [$email]) !== null) {
            return $row('error', 'このメールアドレスはほかのアカウントで使われているため、マイページのアカウントを作れません');
        }
        $userId = Db::insert(
            "INSERT INTO users (tenant_id, email, password_hash, name, role, status, password_pending, target_id)
             VALUES (?, ?, ?, ?, 'learner', 'active', 1, ?)",
            [$tenantId, $email, UserPasswordTokens::unusableHash(), $target['name'], $targetId]
        );
        audit('learner.create', 'user_id=' . $userId . ',target_id=' . $targetId);
        $learner = Db::one('SELECT * FROM users WHERE id = ?', [$userId]);
    } elseif (strtolower((string) $learner['email']) !== strtolower($email)) {
        // 対象者のメールアドレスが変わっていたら、アカウントも合わせる(ログインできるのは対象者と同じアドレスだけ)
        if (Db::one('SELECT 1 FROM users WHERE lower(email) = lower(?) AND id != ?', [$email, (int) $learner['id']]) !== null) {
            return $row('error', 'このメールアドレスはほかのアカウントで使われているため、マイページのアカウントを合わせられません');
        }
        Db::run('UPDATE users SET email = ?, session_epoch = session_epoch + 1 WHERE id = ?', [$email, (int) $learner['id']]);
        UserPasswordTokens::revokeOpen((int) $learner['id']);
        $learner = Db::one('SELECT * FROM users WHERE id = ?', [(int) $learner['id']]);
    }
    try {
        $r = UserPasswordTokens::issueAndSend($learner, (int) $actor['id'], 'my');
    } catch (PasswordTokenException $e) {
        audit('learner.invite', 'user_id=' . (int) $learner['id'] . ',target_id=' . $targetId . ',sent=0,reason=' . $e->reason);
        return $row('error', $e->getMessage());
    }
    audit('learner.invite', 'user_id=' . (int) $learner['id'] . ',target_id=' . $targetId . ',sent=1,purpose=' . $r['purpose']);
    return $row('sent', $r['purpose'] === 'invite' ? '招待を送りました' : 'パスワード再設定のメールを送りました');
}

function learners_handle_invite(array $actor): never
{
    tet2_require_csrf();
    $body = json_body();
    $tenantId = effective_tenant_id($actor, learners_body_tenant($body));
    $ids = $body['target_ids'] ?? null;
    if (!is_array($ids) || $ids === []) {
        json_error('target_ids を1件以上指定してください', 400);
    }
    if (count($ids) > LEARNER_INVITE_MAX) {
        json_error('一度に送れるのは ' . LEARNER_INVITE_MAX . ' 件までです', 400);
    }
    $unique = [];
    foreach ($ids as $id) {
        if (!is_int($id) || $id < 1) {
            json_error('target_ids が不正です', 400);
        }
        $unique[$id] = true;
    }
    if (!TenantStatus::isOperational($tenantId)) {
        json_error('停止中・削除済みのテナントの対象者には送れません', 409);
    }
    $results = [];
    foreach (array_keys($unique) as $id) {
        $results[] = learners_invite_one($actor, $tenantId, $id);
    }
    $count = static fn(string $kind): int => count(array_filter($results, static fn(array $r): bool => $r['result'] === $kind));
    json_out(['success' => true, 'sent' => $count('sent'), 'admin_account' => $count('admin_account'),
        'errors' => $count('error'), 'results' => $results]);
}

function learners_handle_delete(array $actor): never
{
    tet2_require_csrf();
    $body = json_body();
    $tenantId = effective_tenant_id($actor, learners_body_tenant($body));
    $targetId = $body['target_id'] ?? null;
    if (!is_int($targetId) || $targetId < 1) {
        json_error('target_id が不正です', 400);
    }
    $learner = Db::one("SELECT id FROM users WHERE target_id = ? AND role = 'learner' AND tenant_id = ?", [$targetId, $tenantId]);
    if ($learner === null) {
        json_error('マイページのアカウントがありません', 404);
    }
    // 成績(edu_*、survey_*)は対象者につながっているので消えない。トークンは外部キーで一緒に消える。
    Db::run('DELETE FROM users WHERE id = ?', [(int) $learner['id']]);
    audit('learner.delete', 'user_id=' . (int) $learner['id'] . ',target_id=' . $targetId);
    json_out(['success' => true]);
}

try {
    $actor = require_role('tenant_admin');
    $action = $_GET['action'] ?? '';
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($action === 'status' && $method === 'GET') {
        learners_handle_status($actor);
    }
    if ($action === 'invite' && $method === 'POST') {
        learners_handle_invite($actor);
    }
    if ($action === 'delete' && $method === 'POST') {
        learners_handle_delete($actor);
    }
    json_error('不正なアクションです', 400);
} catch (Throwable $e) {
    error_log('learners: ' . $e->getMessage());
    json_error('サーバエラー', 500);
}
