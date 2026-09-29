<?php
declare(strict_types=1);

/**
 * パスワードの禁止語(A-9、G44 の残り)のテスト。合成 DB と .test ドメインだけを使う。
 *
 *   DL-*   よく使われる語(置き換え文字も戻す)、組織の語(テナント名、slug、メールのドメイン)、4文字未満の語は無視
 *   TW-*   テナントが足した禁止語: 保存の検証、テナントの分離、全体の行、メッセージに語を出さない
 *   PATH-* 管理画面のユーザの作成、変更、パスワード設定のリンク、テナント作成の最初の管理者、受講者のマイページ
 */

require_once __DIR__ . '/helpers.php';
tet2_test_boot();
putenv('TET2_EDU_MAIL_DISABLE=1');
require_once __DIR__ . '/../lib/PasswordPolicy.php';
require_once __DIR__ . '/../lib/PasswordDenyList.php';
require_once __DIR__ . '/../lib/AdminSecurityPolicy.php';
require_once __DIR__ . '/../lib/UserPasswordTokens.php';
require_once __DIR__ . '/../lib/TenantPurge.php';
require_once __DIR__ . '/../lib/AdminMfa.php';
load_api('users');
load_api('tenants');

const OK_PW = 'Heron-Kiwi-2026';
$msg = PasswordDenyList::MESSAGE;

Db::run("INSERT INTO users (id, tenant_id, email, password_hash, name, role, status) VALUES
    (30, 1, 'deny-admin@example.test', 'x', 'Deny Admin', 'tenant_admin', 'active'),
    (31, 2, 'deny-other@other.example.test', 'x', 'Other Admin', 'tenant_admin', 'active'),
    (32, NULL, 'deny-root@root.test', 'x', 'Root', 'superadmin', 'active'),
    (33, 1, 'deny-op@acme-widgets.co.test', 'x', 'Op', 'operator', 'active')");
function denyActor(int $id): array
{
    $u = Db::one('SELECT id, tenant_id, role, email FROM users WHERE id = ?', [$id]);
    return ['id' => (int) $u['id'], 'tenant_id' => $u['tenant_id'] !== null ? (int) $u['tenant_id'] : null,
        'role' => (string) $u['role'], 'email' => (string) $u['email']];
}
function denyAs(string $fn, int $id, array $body = []): array
{
    $a = denyActor($id);
    return call_handler($fn, $body, $a['role'], [$a]);
}

// ============ よく使われる語と組織の語 ============
check(PasswordDenyList::violation(OK_PW) === null, 'DL-1: よく使われる語を含まなければ通る');
foreach (['Password2026!', 'MyQwerty-2026x', 'Letmein-2026-Z', 'FirstAdmin-2026'] as $pw) {
    check(PasswordDenyList::violation($pw) === $msg, "DL-2: よく使われる語を含むと拒む({$pw})");
}
check(PasswordDenyList::violation('P@ssw0rd-2026x') === $msg && PasswordDenyList::violation('Adm1n-Heron-26') === $msg,
    'DL-3: @→a、0→o、1→i などの置き換えを戻して比べる');
check(PasswordDenyList::violation('Zz9-123456-Heron') === $msg, 'DL-4: 連番の数字も拒む');
$words = PasswordDenyList::organizationWords('Example Tenant', 'example-tenant', 'someone@mail.acme-widgets.co.test');
foreach (['example', 'tenant', 'exampletenant', 'example-tenant', 'acme-widgets', 'acme', 'widgets'] as $w) {
    check(in_array($w, $words, true), "DL-5: 組織の語に {$w} が入る");
}
check(!in_array('mail', $words, true) && !in_array('test', $words, true) && !in_array('co', $words, true),
    'DL-6: メールのドメインの最後のラベル、汎用の語(mail)、4文字未満(co)は入れない');
check(PasswordDenyList::organizationWords('Xy Q', 'xy', null) === [], 'DL-7: 4文字未満のテナント名と slug は無視する');
check(PasswordDenyList::violation('Xyq-Heron-2026', PasswordDenyList::organizationWords('Xy Q', 'xy', null)) === null,
    'DL-8: 短い組織名はパスワードに含まれていても拒まない');
check(AdminSecurityPolicy::violation('EXAMPLE-Heron-26', 1) === $msg, 'DL-9: テナント名を含むと拒む(大文字と小文字を区別しない)');
check(AdminSecurityPolicy::violation('Heron-Tenant-26', 1) === $msg, 'DL-10: テナントの slug の語を含むと拒む');
check(AdminSecurityPolicy::violation('Widgets-Heron-26', 1, 'deny-op@acme-widgets.co.test') === $msg, 'DL-11: 本人のメールのドメインを含むと拒む');
check(AdminSecurityPolicy::violation('Widgets-Heron-26', 1, 'deny-admin@example.test') === null, 'DL-12: ほかの人のドメインの語は関係しない');
check(AdminSecurityPolicy::violation(OK_PW, 1, 'deny-admin@example.test') === null, 'DL-13: 禁止語を含まなければ通る');

// ============ テナントが足した禁止語 ============
$r = denyAs('users_handle_policy_set', 30, ['min_length' => 12, 'min_classes' => 3, 'require_mfa' => false, 'banned_words' => "Kiwifruit\n\n  Sakuraya  \nkiwifruit"]);
check($r['code'] === 200 && $r['payload']['policy']['banned_words'] === ['Kiwifruit', 'Sakuraya'], 'TW-1: 組織管理者は禁止語を保存できる(空行と重複を除く)');
check(auditHas('security_policy.update', 'banned_words=2'), 'TW-2: 監査に禁止語の数だけを残す(語は残さない)');
$v = AdminSecurityPolicy::violation('My-KIWIFRUIT-26', 1);
check($v === $msg && !str_contains(mb_strtolower((string) $v), 'kiwi'), 'TW-3: 足した禁止語を含むと拒み、メッセージに語を出さない');
check(AdminSecurityPolicy::violation('My-KIWIFRUIT-26', 2) === null, 'TW-4: テナントの分離: ほかのテナントには効かない');
$r = denyAs('users_handle_policy_set', 30, ['min_length' => 12, 'min_classes' => 3, 'require_mfa' => false]);
check($r['code'] === 200 && $r['payload']['policy']['banned_words'] === ['Kiwifruit', 'Sakuraya'], 'TW-5: 禁止語を送らなければ今の値を残す');
$r = denyAs('users_handle_policy_set', 30, ['min_length' => 12, 'min_classes' => 3, 'require_mfa' => false, 'banned_words' => 'abc']);
check($r['code'] === 400 && str_contains($r['payload']['error'], '4〜64文字') && !str_contains($r['payload']['error'], 'abc'), 'TW-6: 4文字未満の禁止語は保存できない(語は出さない)');
$many = implode("\n", array_map(static fn(int $i): string => 'word' . $i, range(1, PasswordDenyList::MAX_WORDS + 1)));
$r = denyAs('users_handle_policy_set', 30, ['min_length' => 12, 'min_classes' => 3, 'require_mfa' => false, 'banned_words' => $many]);
check($r['code'] === 400, 'TW-7: 上限の語数を超えると保存できない');
$r = denyAs('users_handle_policy_set', 30, ['min_length' => 12, 'min_classes' => 3, 'require_mfa' => false, 'banned_words' => str_repeat('a', 65)]);
check($r['code'] === 400, 'TW-8: 長すぎる禁止語は保存できない');
$r = denyAs('users_handle_policy_set', 30, ['min_length' => 12, 'min_classes' => 3, 'require_mfa' => false, 'banned_words' => ['配列']]);
check($r['code'] === 400, 'TW-9: 文字列でない禁止語は 400');
check(AdminSecurityPolicy::row(1)['banned_words'] === ['Kiwifruit', 'Sakuraya'], 'TW-10: 拒んだ保存は禁止語を変えない');
$r = denyAs('users_handle_policy_set', 30, ['tenant_id' => 2, 'min_length' => 12, 'min_classes' => 3, 'require_mfa' => false, 'banned_words' => 'Blocked']);
check($r['code'] === 403 && AdminSecurityPolicy::row(2)['banned_words'] === [], 'TW-11: 組織管理者はほかのテナントの禁止語を変えられない');
$r = denyAs('users_handle_policy_get', 30);
check($r['code'] === 200 && $r['payload']['tenant']['banned_words'] === ['Kiwifruit', 'Sakuraya'] && $r['payload']['limits']['banned_words'] === 50,
    'TW-12: 方針の取得は自組織の禁止語と上限を返す');
$r = denyAs('users_handle_policy_set', 32, ['scope' => 'global', 'min_length' => 12, 'min_classes' => 3, 'require_mfa' => false, 'banned_words' => 'Globalword']);
check($r['code'] === 200 && AdminSecurityPolicy::violation('Globalword-Zz9', 2) === $msg && AdminSecurityPolicy::violation('Globalword-Zz9', 1) === $msg,
    'TW-13: 全体の行の禁止語はすべてのテナントに効く');
$r = denyAs('users_handle_policy_get', 30);
check($r['payload']['global']['banned_words'] === [] && $r['payload']['global']['banned_words_count'] === 1,
    'TW-13b: 組織管理者には全体の禁止語の中身を見せず、数だけを返す');
$r = denyAs('users_handle_policy_get', 32);
check($r['payload']['global']['banned_words'] === ['Globalword'], 'TW-13c: システム管理者には全体の禁止語を返す');
$r = denyAs('users_handle_policy_set', 30, ['min_length' => 12, 'min_classes' => 3, 'require_mfa' => false, 'banned_words' => '']);
check($r['code'] === 200 && AdminSecurityPolicy::row(1)['banned_words'] === [] && AdminSecurityPolicy::violation('My-KIWIFRUIT-26', 1) === null,
    'TW-14: 空で保存すると禁止語を消す');
denyAs('users_handle_policy_set', 30, ['min_length' => 12, 'min_classes' => 3, 'require_mfa' => false, 'banned_words' => 'Kiwifruit']);

// ============ 各経路 ============
$r = denyAs('users_handle_create', 30, ['email' => 'deny-new@example.test', 'name' => 'N', 'role' => 'viewer', 'password' => 'Example-Heron-26']);
check($r['code'] === 400 && $r['payload']['error'] === $msg, 'PATH-1: ユーザの作成でテナント名を含むパスワードを拒む');
$r = denyAs('users_handle_create', 30, ['email' => 'deny-new@example.test', 'name' => 'N', 'role' => 'viewer', 'password' => 'Kiwifruit-Zz9']);
check($r['code'] === 400 && $r['payload']['error'] === $msg, 'PATH-2: ユーザの作成で足した禁止語を拒む');
$r = denyAs('users_handle_create', 30, ['email' => 'deny-new@example.test', 'name' => 'N', 'role' => 'viewer', 'password' => OK_PW]);
check($r['code'] === 201, 'PATH-3: 禁止語を含まなければ作成できる');
$r = denyAs('users_handle_update', 30, ['id' => 33, 'password' => 'Widgets-Heron-26']);
check($r['code'] === 400 && $r['payload']['error'] === $msg, 'PATH-4: パスワードの変更(管理者による再設定)で本人のドメインの語を拒む');
$r = denyAs('users_handle_update', 30, ['id' => 33, 'password' => OK_PW]);
check($r['code'] === 200, 'PATH-5: 禁止語を含まなければ変更できる');
check(UserPasswordTokens::policyViolation(['role' => 'operator', 'tenant_id' => 1, 'email' => 'deny-op@acme-widgets.co.test'], 'Acme-Heron-2026') === $msg,
    'PATH-6: パスワード設定のリンク(管理画面のユーザ)でも禁止語を拒む');
check(UserPasswordTokens::policyViolation(['role' => 'operator', 'tenant_id' => 1, 'email' => 'x@example.test'], 'Kiwifruit-Zz9') === $msg,
    'PATH-7: パスワード設定のリンクでも組織が足した禁止語を拒む');
$learner = ['role' => 'learner', 'tenant_id' => 1, 'email' => 'learner@example.test'];
check(UserPasswordTokens::policyViolation($learner, 'Password-2026x') === $msg, 'PATH-8: 受講者のパスワードもよく使われる語を拒む');
check(UserPasswordTokens::policyViolation($learner, 'Example-Heron-26') === $msg, 'PATH-9: 受講者のパスワードもテナント名を拒む');
check(UserPasswordTokens::policyViolation($learner, 'Kiwifruit-Zz9') === null, 'PATH-10: 受講者には組織が足した禁止語は当てない(管理画面のユーザ向け)');
check(PasswordDenyList::learnerViolation('Tenant-Heron-26', 1, null) === $msg && PasswordDenyList::learnerViolation('Tenant-Heron-26', 2, null) === $msg
    && PasswordDenyList::learnerViolation('Other-Heron-26', 1, null) === null, 'PATH-11: 受講者の組織の語は所属のテナントだけ');
$r = call_handler('tenants_handle_create', ['name' => 'Sakura Trading', 'slug' => 'sakura-trading', 'admin_email' => 'first@sakura-trading.test',
    'admin_name' => 'F', 'admin_password' => 'Sakura-Heron-26'], 'superadmin', []);
check($r['code'] === 400 && str_contains($r['payload']['error'], $msg) && Db::one("SELECT 1 FROM tenants WHERE slug = 'sakura-trading'") === null,
    'PATH-12: テナント作成の最初の管理者も、作るテナントの名前を含むパスワードを拒み、テナントを作らない');
$r = call_handler('tenants_handle_create', ['name' => 'Sakura Trading', 'slug' => 'sakura-trading', 'admin_email' => 'first@sakura-trading.test',
    'admin_name' => 'F', 'admin_password' => 'Qwerty-Heron-26'], 'superadmin', []);
check($r['code'] === 400, 'PATH-13: 最初の管理者もよく使われる語を拒む');
$r = call_handler('tenants_handle_create', ['name' => 'Sakura Trading', 'slug' => 'sakura-trading', 'admin_email' => 'first@sakura-trading.test',
    'admin_name' => 'F', 'admin_password' => OK_PW], 'superadmin', []);
check($r['code'] === 201, 'PATH-14: 禁止語を含まなければテナントと最初の管理者を作れる');

echo "ALL TESTS PASSED\n";

function auditHas(string $action, string $detailPart): bool
{
    foreach ($GLOBALS['__TET2_TEST_AUDIT'] as $row) {
        if ($row['action'] === $action && str_contains($row['detail'], $detailPart)) {
            return true;
        }
    }
    return false;
}
