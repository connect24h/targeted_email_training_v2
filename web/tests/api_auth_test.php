<?php declare(strict_types=1);
/**
 * auth.php デシジョンテーブルテスト
 * ログイン全パターン (A-1〜A-12) + logout/me (A-13〜A-14)
 *
 * auth.php は session_regenerate_id / session_destroy を使うため、
 * CLI でもセッションを開始してから実行する。
 */

// CLIセッション設定
ini_set('session.use_cookies', '0');
ini_set('session.use_only_cookies', '0');
ini_set('session.use_trans_sid', '0');
session_start();

require __DIR__ . '/helpers.php';

$tmp = tet2_test_boot();
load_api('auth');

// --- テスト用ユーザーのシード ---
$testPw = 'TestPass123!';
$hash = password_hash($testPw, PASSWORD_DEFAULT);
Db::run("INSERT OR IGNORE INTO users (id, tenant_id, email, password_hash, name, role, status, failed_count, locked_until)
         VALUES (9990, 1, 'auth-test-active@test.local', ?, 'Active User', 'operator', 'active', 0, NULL)", [$hash]);
Db::run("INSERT OR IGNORE INTO users (id, tenant_id, email, password_hash, name, role, status, failed_count, locked_until)
         VALUES (9991, 1, 'auth-test-suspended@test.local', ?, 'Suspended User', 'operator', 'suspended', 0, NULL)", [$hash]);
Db::run("INSERT OR IGNORE INTO users (id, tenant_id, email, password_hash, name, role, status, failed_count, locked_until)
         VALUES (9992, 1, 'auth-test-locked@test.local', ?, 'Locked User', 'operator', 'active', 5, datetime('now','localtime','+15 minutes'))", [$hash]);
Db::run("INSERT OR IGNORE INTO users (id, tenant_id, email, password_hash, name, role, status, failed_count, locked_until)
         VALUES (9993, 1, 'auth-test-lockexpired@test.local', ?, 'Lock-Expired User', 'operator', 'active', 5, datetime('now','localtime','-1 minutes'))", [$hash]);
Db::run("INSERT OR IGNORE INTO users (id, tenant_id, email, password_hash, name, role, status, failed_count, locked_until)
         VALUES (9994, 1, 'auth-test-fail4@test.local', ?, 'Fail4 User', 'operator', 'active', 4, NULL)", [$hash]);

echo "=== auth.php デシジョンテーブルテスト ===\n\n";

// A-1: 正しいemail + 正しいpassword + active + failed_count=0 → ログイン成功
$r = call_handler('auth_handle_login', ['email' => 'auth-test-active@test.local', 'password' => $testPw]);
check($r['code'] === 200, 'A-1: ログイン成功 (200)');
check($r['payload']['success'] === true, 'A-1: success=true');
check($r['payload']['user']['email'] === 'auth-test-active@test.local', 'A-1: ユーザー情報返却');
check(isset($r['payload']['csrf']), 'A-1: CSRFトークン返却');

// A-2: failed_count=4でもログイン成功, failed_count=0リセット
$r = call_handler('auth_handle_login', ['email' => 'auth-test-fail4@test.local', 'password' => $testPw]);
check($r['code'] === 200, 'A-2: failed_count=4でもログイン成功');
$u = Db::one('SELECT failed_count FROM users WHERE id = 9994');
check((int) $u['failed_count'] === 0, 'A-2: failed_count=0にリセット');

// A-3: 不正password + failed_count=0 → 401, failed_count=1
Db::run("UPDATE users SET failed_count = 0, locked_until = NULL WHERE id = 9990");
$r = call_handler('auth_handle_login', ['email' => 'auth-test-active@test.local', 'password' => 'WrongPassword']);
check($r['code'] === 401, 'A-3: 不正パスワード → 401');
$u = Db::one('SELECT failed_count FROM users WHERE id = 9990');
check((int) $u['failed_count'] === 1, 'A-3: failed_count=1');

// A-4: 不正password + failed_count=4 → 401, failed_count=5, locked_until設定
Db::run("UPDATE users SET failed_count = 4, locked_until = NULL WHERE id = 9990");
$r = call_handler('auth_handle_login', ['email' => 'auth-test-active@test.local', 'password' => 'WrongPassword']);
check($r['code'] === 401, 'A-4: 5回目失敗 → 401');
$u = Db::one('SELECT failed_count, locked_until FROM users WHERE id = 9990');
check((int) $u['failed_count'] === 5, 'A-4: failed_count=5');
check($u['locked_until'] !== null, 'A-4: locked_until設定');

// A-5: ロック中 → 423
$r = call_handler('auth_handle_login', ['email' => 'auth-test-locked@test.local', 'password' => $testPw]);
check($r['code'] === 423, 'A-5: ロック中 → 423');

// A-6: ロック期限切れ → ログイン成功
$r = call_handler('auth_handle_login', ['email' => 'auth-test-lockexpired@test.local', 'password' => $testPw]);
check($r['code'] === 200, 'A-6: ロック期限切れ → ログイン成功');
$u = Db::one('SELECT failed_count, locked_until FROM users WHERE id = 9993');
check((int) $u['failed_count'] === 0, 'A-6: failed_countリセット');
check($u['locked_until'] === null, 'A-6: locked_untilクリア');

// A-7: suspended → 403
$r = call_handler('auth_handle_login', ['email' => 'auth-test-suspended@test.local', 'password' => $testPw]);
check($r['code'] === 403, 'A-7: サスペンド中 → 403');

// A-8: 存在しないemail → 401
$r = call_handler('auth_handle_login', ['email' => 'nonexistent@test.local', 'password' => 'anything']);
check($r['code'] === 401, 'A-8: 存在しないemail → 401');

// A-9: email未指定 → 400
$r = call_handler('auth_handle_login', ['password' => $testPw]);
check($r['code'] === 400, 'A-9: email未指定 → 400');

// A-10: email不正形式 → 400
$r = call_handler('auth_handle_login', ['email' => 'not-an-email', 'password' => $testPw]);
check($r['code'] === 400, 'A-10: email不正形式 → 400');

// A-11: password未指定 → 400
$r = call_handler('auth_handle_login', ['email' => 'auth-test-active@test.local']);
check($r['code'] === 400, 'A-11: password未指定 → 400');

// A-12: password空文字 → 400
$r = call_handler('auth_handle_login', ['email' => 'auth-test-active@test.local', 'password' => '']);
check($r['code'] === 400, 'A-12: password空文字 → 400');

// A-13: logout → セッション破棄, 200
// logout は session_destroy を呼ぶが、セッション非アクティブ状態で再呼びするとwarningが出る
// → テスト用に再startしておく
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
$r = call_handler('auth_handle_logout', [], 'operator', []);
check($r['code'] === 200, 'A-13: logout → 200');
check($r['payload']['success'] === true, 'A-13: success=true');

// A-14: me → ユーザー情報+csrf
$r = call_handler('auth_handle_me', [], 'operator', []);
check($r['code'] === 200, 'A-14: me → 200');
check($r['payload']['success'] === true, 'A-14: success=true');
check(isset($r['payload']['csrf']), 'A-14: csrf返却');

echo "\n✅ auth.php 全テスト完了\n";
