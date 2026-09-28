<?php
/**
 * ログイン中のセッションの判定を、本物の bootstrap.php で確かめるための probe(tenant_status_test.php が別プロセスで起動する)。
 *
 * 使い方: TET2_DB_PATH=<合成DB> php session_probe.php <mode> <uid> <tenant_id|-> <role>
 *   mode=auth: require_auth() を呼び、通れば {"probe":"ok"} を出す(拒否なら bootstrap の json_error が出す)。
 *   mode=me:   api/auth.php の me を呼ぶ。
 *   mode=my:   受講者のマイページのセッション($_SESSION[my])だけを入れて require_auth() を呼ぶ(未ログインになるはず)。
 *   mode=pending: 多要素認証のコード待ち($_SESSION[mfa_pending])だけを入れて require_auth() を呼ぶ(未ログインになるはず)。
 *   mode=enroll:  require_auth_for_mfa_enrollment() を呼ぶ(多要素認証が必須で未登録でも通るはず)。
 * 出力の末尾に、セッションに uid が残っているかを1行で足す。
 */
declare(strict_types=1);

ini_set('session.use_cookies', '0');
ini_set('session.use_only_cookies', '0');
ini_set('session.save_path', sys_get_temp_dir());

[$script, $mode, $uid, $tenant, $role] = $argv + [null, 'auth', '0', '-', 'operator'];

register_shutdown_function(static function (): void {
    echo "\nsession_uid=" . (isset($_SESSION['uid']) ? (string) $_SESSION['uid'] : 'none') . "\n";
    // 一時ディレクトリにセッションのファイルを残さない
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
});

if ($mode === 'me') {
    $_GET['action'] = 'me';
    $_SERVER['REQUEST_METHOD'] = 'GET';
    // auth.php が bootstrap を読んでセッションを始めた後に、ログイン中の状態を入れる必要がある。
    // bootstrap を先に読み、セッションを入れてから auth.php を読む(auth.php の require は2回目なので再実行されない)。
    require_once __DIR__ . '/../../lib/bootstrap.php';
    $_SESSION['uid'] = (int) $uid;
    $_SESSION['tenant_id'] = $tenant === '-' ? null : (int) $tenant;
    $_SESSION['role'] = $role;
    $_SESSION['email'] = 'probe@example.test';
    // eval するのはリポジトリの api/auth.php だけ(helpers.php の load_api と同じ手法)。外部の入力は渡さない。
    $src = (string) file_get_contents(__DIR__ . '/../../api/auth.php');
    $src = preg_replace('/^<\?php.*?\n/', '', $src, 1);
    // eval の中の __DIR__ はこの probe の場所になるので、auth.php の require の行は外し、ここで読む
    $src = preg_replace('/^\s*require(_once)?[^\n]*\n/m', '', $src);
    require_once __DIR__ . '/../../lib/AdminMfa.php';
    eval($src);
    exit;
}

if ($mode === 'my') {
    // 受講者のマイページのセッションの中身だけ(LearnerAuth の $_SESSION['my'])を入れ、管理画面の require_auth() を呼ぶ。
    require_once __DIR__ . '/../../lib/bootstrap.php';
    $_SESSION['my'] = ['uid' => (int) $uid, 'tenant_id' => $tenant === '-' ? null : (int) $tenant, 'epoch' => 0, 'csrf' => 'probe'];
    require_auth();
    echo json_encode(['probe' => 'ok']);
    exit;
}

if ($mode === 'pending') {
    // パスワードだけを確かめ、多要素認証のコードを待っているセッション(auth.php の mfa_pending)。管理画面の API は未ログインのはず
    require_once __DIR__ . '/../../lib/bootstrap.php';
    $_SESSION['mfa_pending'] = ['uid' => (int) $uid, 'epoch' => 0, 'expires' => time() + 300];
    require_auth();
    echo json_encode(['probe' => 'ok']);
    exit;
}

require_once __DIR__ . '/../../lib/bootstrap.php';
if ($mode === 'enroll') {
    // 多要素認証の登録の入口(require_auth_for_mfa_enrollment)。必須化で止められているユーザも通るはず
    $_SESSION['uid'] = (int) $uid;
    $_SESSION['tenant_id'] = $tenant === '-' ? null : (int) $tenant;
    $_SESSION['role'] = $role;
    $_SESSION['email'] = 'probe@example.test';
    $u = require_auth_for_mfa_enrollment();
    echo json_encode(['probe' => 'ok', 'mfa_enrollment_required' => $u['mfa_enrollment_required'] ?? null]);
    exit;
}
$_SESSION['uid'] = (int) $uid;
$_SESSION['tenant_id'] = $tenant === '-' ? null : (int) $tenant;
$_SESSION['role'] = $role;
$_SESSION['email'] = 'probe@example.test';
require_auth();
echo json_encode(['probe' => 'ok']);
