<?php
declare(strict_types=1);

/**
 * テナントの管理の API(T2 論理削除と復元、T4 一覧、T5 詳細、T6 管理の項目、T7 最初の管理者)。
 * 合成 DB と .test のアドレスだけを使う。完全削除(T3)は tenant_purge_test.php。
 */

require_once __DIR__ . '/helpers.php';
tet2_test_boot();
require_once __DIR__ . '/../lib/TenantPurge.php';
require_once __DIR__ . '/../lib/PasswordPolicy.php';
load_api('tenants');
load_api('targets');

const SA = 'superadmin';

function tenantRow(int $id): array
{
    return Db::one('SELECT * FROM tenants WHERE id = ?', [$id]);
}

function auditActions(): array
{
    return array_column($GLOBALS['__TET2_TEST_AUDIT'], 'action');
}

// ---------- 権限 ----------
foreach (['viewer', 'operator', 'tenant_admin'] as $role) {
    $r = call_handler('require_role', [], $role, ['superadmin']);
    check($r['code'] === 403, "TM-0: {$role} はテナントの管理を使えない");
}
foreach (['tenants_handle_delete', 'tenants_handle_restore', 'tenants_handle_purge', 'tenants_handle_create', 'tenants_handle_update'] as $fn) {
    call_handler($fn, ['id' => 999999, 'name' => 'x', 'slug' => 'xx', 'confirm_slug' => 'x'], SA, []);
    check($GLOBALS['__TET2_TEST_CSRF_CALLS'] === 1, "TM-0: {$fn} は CSRF を確かめる");
}

// ---------- T7 作成と最初の管理者 ----------
$r = call_handler('tenants_handle_create', [
    'name' => '作成テスト社', 'slug' => 'create-co',
    'admin_email' => 'admin@create-co.test', 'admin_name' => '管理 太郎', 'admin_password' => 'InitialPass1',
], SA, []);
check($r['code'] === 201, 'T7-1: 最初の管理者つきで作成できる');
$created = (int) $r['payload']['tenant']['id'];
$admin = Db::one('SELECT * FROM users WHERE email = ?', ['admin@create-co.test']);
check($admin !== null && (int) $admin['tenant_id'] === $created && $admin['role'] === 'tenant_admin' && $admin['status'] === 'active',
    'T7-1: 管理者はそのテナントの組織管理者として有効で作られる');
check(password_verify('InitialPass1', (string) $admin['password_hash']), 'T7-1: 初期パスワードはハッシュで保存する');
check((int) $r['payload']['admin_user_id'] === (int) $admin['id'], 'T7-1: 作った管理者の id を返す');
check(in_array('tenant.create', auditActions(), true) && in_array('user.create', auditActions(), true), 'T7-1: 監査ログに作成を残す');
check(tenantRow($created)['data_dir'] === TenantStatus::dataRoot() . '/create-co', 'T7-1: data_dir は <data_root>/<slug>');

$r = call_handler('tenants_handle_create', ['name' => '管理者なし', 'slug' => 'no-admin'], SA, []);
check($r['code'] === 201 && $r['payload']['admin_user_id'] === null, 'T7-2: 管理者なしでも作成できる');

$r = call_handler('tenants_handle_create', ['name' => '重複', 'slug' => 'dup-admin', 'admin_email' => 'admin@create-co.test', 'admin_name' => 'x', 'admin_password' => 'Password1234'], SA, []);
check($r['code'] === 409 && Db::one("SELECT 1 FROM tenants WHERE slug = 'dup-admin'") === null,
    'T7-3: 管理者のメールが既にあれば 409 で、テナントも作らない');
$r = call_handler('tenants_handle_create', ['name' => '短い', 'slug' => 'short-pw', 'admin_email' => 'a@short.test', 'admin_name' => 'x', 'admin_password' => 'short'], SA, []);
check($r['code'] === 400 && Db::one("SELECT 1 FROM tenants WHERE slug = 'short-pw'") === null, 'T7-4: 初期パスワードがパスワードの決まり(12文字以上)に合わなければ 400');
$r = call_handler('tenants_handle_create', ['name' => '名前なし', 'slug' => 'no-name', 'admin_email' => 'a@noname.test', 'admin_password' => 'Password1234'], SA, []);
check($r['code'] === 400, 'T7-5: 管理者の名前がなければ 400');
$r = call_handler('tenants_handle_create', ['name' => 'メール不正', 'slug' => 'bad-mail', 'admin_email' => 'not-mail', 'admin_name' => 'x', 'admin_password' => 'Password1234'], SA, []);
check($r['code'] === 400, 'T7-6: 管理者のメールの形が不正なら 400');

// トランザクション: ユーザの作成が DB で失敗したら、テナントも残さない
Db::run("CREATE TRIGGER fail_admin_insert BEFORE INSERT ON users WHEN NEW.email = 'boom@tx.test'
         BEGIN SELECT RAISE(ABORT, 'forced failure'); END");
$failed = false;
try {
    call_handler('tenants_handle_create', ['name' => 'Tx', 'slug' => 'tx-co', 'admin_email' => 'boom@tx.test', 'admin_name' => 'x', 'admin_password' => 'Password1234'], SA, []);
} catch (PDOException) {
    $failed = true;
}
check($failed && Db::one("SELECT 1 FROM tenants WHERE slug = 'tx-co'") === null,
    'T7-7: ユーザの作成が失敗したらテナントの作成も取り消す(1つのトランザクション)');
Db::run('DROP TRIGGER fail_admin_insert');

// ---------- T6 管理の項目 ----------
$r = call_handler('tenants_handle_create', [
    'name' => '契約社', 'slug' => 'contract-co', 'contact_name' => '担当 花子', 'contact_email' => 'hanako@contract.test',
    'contract_end_date' => TenantStatus::today()->modify('+10 days')->format('Y-m-d'), 'target_limit' => 2, 'memo' => "1行目\n2行目",
], SA, []);
check($r['code'] === 201, 'T6-1: 管理の項目つきで作成できる');
$contract = $r['payload']['tenant'];
check($contract['contact_name'] === '担当 花子' && $contract['target_limit'] === 2 && $contract['memo'] === "1行目\n2行目", 'T6-1: 項目を保存する');
check($contract['contract_expiring'] === true && $contract['contract_days_left'] === 10, 'T6-2: 契約の終了日が30日以内なら印を付ける');
$contractId = (int) $contract['id'];

foreach ([
    ['contact_email', 'not-an-email', 'メール'],
    ['contract_end_date', '2026-02-30', '日付'],
    ['contract_end_date', '2026/10/01', '日付'],
    ['target_limit', 0, '上限'],
    ['target_limit', -3, '上限'],
    ['target_limit', '1.5', '上限'],
    ['target_limit', 'abc', '上限'],
    ['memo', str_repeat('あ', 2001), 'メモ'],
] as [$key, $value, $label]) {
    $r = call_handler('tenants_handle_update', ['id' => $contractId, $key => $value], SA, []);
    check($r['code'] === 400, "T6-3: {$key}=" . (is_string($value) ? mb_substr($value, 0, 12) : $value) . " は 400");
}
$r = call_handler('tenants_handle_update', ['id' => $contractId, 'target_limit' => '', 'contract_end_date' => '', 'contact_email' => ''], SA, []);
check($r['code'] === 200 && $r['payload']['tenant']['target_limit'] === null && $r['payload']['tenant']['contract_end_date'] === null,
    'T6-4: 空は「なし」として消せる');
$r = call_handler('tenants_handle_update', ['id' => $contractId, 'target_limit' => '5', 'contract_end_date' => TenantStatus::today()->modify('+60 days')->format('Y-m-d')], SA, []);
check($r['code'] === 200 && $r['payload']['tenant']['target_limit'] === 5 && $r['payload']['tenant']['contract_expiring'] === false,
    'T6-5: 上限は数字の文字列も受け、30日より先の終了日には印を付けない');
$r = call_handler('tenants_handle_update', ['id' => $contractId, 'contract_end_date' => TenantStatus::today()->modify('-1 day')->format('Y-m-d')], SA, []);
check($r['payload']['tenant']['contract_expiring'] === true && $r['payload']['tenant']['contract_days_left'] === -1, 'T6-6: 終了日を過ぎたら印を付ける');

// 対象者の上限: tenant 1 の有効な対象者は2人(検証用を除く)。上限を超えると警告し、登録は拒否しない。
Db::run('UPDATE tenants SET target_limit = 2 WHERE id = 1');
$r = call_handler('targets_handle_create', ['email' => 'third@example.test', 'name' => '3人目'], 'operator');
check($r['code'] === 201, 'T6-7: 上限を超えても登録は拒否しない');
check(is_string($r['payload']['limit_warning']) && str_contains($r['payload']['limit_warning'], '上限(2人)'), 'T6-7: 上限を超えたら警告を返す');
$r = call_handler('targets_handle_create', ['email' => 'tester@example.test', 'is_test' => true], 'operator');
check(str_contains((string) $r['payload']['limit_warning'], '3人'), 'T6-8: 検証用の対象者は数に入れない');
$r = call_handler('targets_handle_import_csv', ['csv' => "email,name\nfourth@example.test,4人目"], 'operator');
check($r['code'] === 200 && $r['payload']['imported'] === 1 && str_contains((string) $r['payload']['limit_warning'], '4人'), 'T6-9: CSV の取込でも警告する');
Db::run('UPDATE tenants SET target_limit = 100 WHERE id = 1');
$r = call_handler('targets_handle_create', ['email' => 'fifth@example.test'], 'operator');
check($r['payload']['limit_warning'] === null, 'T6-10: 上限以内なら警告しない');
Db::run('UPDATE tenants SET target_limit = NULL WHERE id = 1');
$r = call_handler('targets_handle_create', ['email' => 'sixth@example.test'], 'operator');
check($r['payload']['limit_warning'] === null, 'T6-11: 上限がなければ警告しない');

// ---------- T2 論理削除と復元 ----------
$r = call_handler('tenants_handle_delete', ['id' => $created, 'confirm_slug' => 'create-co'], SA, []);
check($r['code'] === 409 && str_contains($r['payload']['error'], '停止'), 'T2-1: 有効なテナントは削除できない(409)');
Db::run("UPDATE tenants SET status = 'suspended' WHERE id = ?", [$created]);
$r = call_handler('tenants_handle_delete', ['id' => $created, 'confirm_slug' => 'wrong'], SA, []);
check($r['code'] === 400 && tenantRow($created)['status'] === 'suspended', 'T2-2: 確認の slug が違えば 400 で消さない');
$r = call_handler('tenants_handle_delete', ['id' => $created], SA, []);
check($r['code'] === 400, 'T2-2: 確認の slug がなければ 400');

$campaignId = Db::insert("INSERT INTO campaigns (tenant_id, name, status) VALUES (?, '実行中', 'running')", [$created]);
$r = call_handler('tenants_handle_delete', ['id' => $created, 'confirm_slug' => 'create-co'], SA, []);
check($r['code'] === 409 && str_contains($r['payload']['error'], 'キャンペーン'), 'T2-3: 実行中のキャンペーンがあれば 409 と理由');
Db::run("UPDATE campaigns SET status = 'scheduled' WHERE id = ?", [$campaignId]);
$r = call_handler('tenants_handle_delete', ['id' => $created, 'confirm_slug' => 'create-co'], SA, []);
check($r['code'] === 409, 'T2-3: 予約中のキャンペーンがあれば 409');
Db::run("UPDATE campaigns SET status = 'done' WHERE id = ?", [$campaignId]);
$deliveryId = Db::insert("INSERT INTO edu_deliveries (tenant_id, title, status) VALUES (?, '配信中', 'running')", [$created]);
$r = call_handler('tenants_handle_delete', ['id' => $created, 'confirm_slug' => 'create-co'], SA, []);
check($r['code'] === 409 && str_contains($r['payload']['error'], '教育の配信'), 'T2-4: 実行中の教育の配信があれば 409 と理由');
Db::run("UPDATE edu_deliveries SET status = 'scheduled' WHERE id = ?", [$deliveryId]);
$r = call_handler('tenants_handle_delete', ['id' => $created, 'confirm_slug' => 'create-co'], SA, []);
check($r['code'] === 409, 'T2-4: 予約中の教育の配信があれば 409');
Db::run("UPDATE edu_deliveries SET status = 'closed' WHERE id = ?", [$deliveryId]);

$r = call_handler('tenants_handle_delete', ['id' => $created, 'confirm_slug' => 'create-co'], SA, []);
check($r['code'] === 200 && $r['payload']['tenant']['status'] === 'deleted', 'T2-5: 条件を満たせば削除済みにする');
$row = tenantRow($created);
check($row['deleted_at'] !== null, 'T2-5: 削除の日時を記録する');
check((int) Db::one('SELECT COUNT(*) AS c FROM campaigns WHERE tenant_id = ?', [$created])['c'] === 1, 'T2-5: データは残す');
check($r['payload']['tenant']['retention_days_left'] === 90 && $r['payload']['tenant']['purge_available'] === false,
    'T2-5: 保持期間の残り日数(90日)を返す');
$deleteAudit = array_values(array_filter($GLOBALS['__TET2_TEST_AUDIT'], static fn(array $a): bool => $a['action'] === 'tenant.delete'));
check($deleteAudit !== [] && str_starts_with($deleteAudit[0]['detail'], 'tenant_id=' . $created . ','), 'T2-5: 監査ログに tenant.delete を残す');
$r = call_handler('tenants_handle_delete', ['id' => $created, 'confirm_slug' => 'create-co'], SA, []);
check($r['code'] === 409, 'T2-6: 削除済みをもう一度削除すると 409');
$r = call_handler('tenants_handle_update', ['id' => $created, 'status' => 'active'], SA, []);
check($r['code'] === 409 && tenantRow($created)['status'] === 'deleted', 'T2-7: 削除済みは編集で有効に戻せない(復元が先)');
$r = call_handler('tenants_handle_update', ['id' => $created, 'status' => 'deleted'], SA, []);
check($r['code'] === 400, 'T2-7: 編集で削除済みにはできない');

// 削除済みのテナントのユーザはログインできない(T1 の判定に乗る)
check(TenantStatus::userAllowed($created, 'tenant_admin') === false, 'T2-8: 削除済みのテナントのユーザは使えない');

$r = call_handler('tenants_handle_restore', ['id' => $created], SA, []);
check($r['code'] === 200 && tenantRow($created)['status'] === 'suspended' && tenantRow($created)['deleted_at'] === null,
    'T2-9: 復元すると停止中に戻り、削除の日時を消す');
check(in_array('tenant.restore', auditActions(), true), 'T2-9: 監査ログに tenant.restore を残す');
$r = call_handler('tenants_handle_restore', ['id' => $created], SA, []);
check($r['code'] === 409, 'T2-10: 削除済みでないものは復元できない(409)');
$r = call_handler('tenants_handle_update', ['id' => $created, 'status' => 'active'], SA, []);
check($r['code'] === 200 && in_array('tenant.activate', auditActions(), true), 'T2-11: 復元の後は編集で有効に戻せる');
$r = call_handler('tenants_handle_update', ['id' => $created, 'status' => 'suspended'], SA, []);
check(in_array('tenant.suspend', auditActions(), true), 'T2-12: 停止は監査ログに tenant.suspend を残す');

// 保持期間の前の完全削除は 409(T3 の API の入口)
call_handler('tenants_handle_delete', ['id' => $created, 'confirm_slug' => 'create-co'], SA, []);
$tmpRoot = sys_get_temp_dir() . '/tet2-tm-root-' . getmypid();
@mkdir($tmpRoot, 0700, true);
$GLOBALS['__TET2_TEST_BODY'] = ['id' => $created, 'confirm_slug' => 'create-co'];
$GLOBALS['__TET2_TEST_ROLE'] = SA;
try {
    tenants_handle_purge(new TenantPurge($tmpRoot));
    $code = 0;
} catch (Tet2TestExit $e) {
    $code = $e->httpCode;
    $message = $e->payload['error'] ?? '';
}
check($code === 409 && str_contains($message, 'あと 90 日'), 'T3-API: 保持期間の前の完全削除は 409 と残り日数');
check(tenantRow($created) !== null, 'T3-API: 保持期間の前は何も消さない');
$GLOBALS['__TET2_TEST_BODY'] = ['id' => $created, 'confirm_slug' => 'wrong'];
try {
    tenants_handle_purge(new TenantPurge($tmpRoot));
    $code = 0;
} catch (Tet2TestExit $e) {
    $code = $e->httpCode;
}
check($code === 400, 'T3-API: 完全削除も確認の slug が違えば 400');
@rmdir($tmpRoot . '/_deleted');
@rmdir($tmpRoot);

// ---------- T4 一覧の数字 ----------
Db::run("INSERT INTO users (tenant_id, email, password_hash, name, role, status, last_login_at) VALUES
    (1, 'late@example.test', 'x', 'Late', 'viewer', 'active', '2026-09-20 10:00:00'),
    (1, 'early@example.test', 'x', 'Early', 'viewer', 'active', '2026-09-01 10:00:00')");
Db::run("INSERT INTO campaigns (id, tenant_id, name, status) VALUES (70, 1, '実行中', 'running'), (71, 1, '予約', 'scheduled'), (72, 1, '削除', 'done')");
Db::run("UPDATE campaigns SET deleted_at = '2026-09-01 00:00:00' WHERE id = 72");
Db::run("INSERT INTO campaign_targets (campaign_id, target_id, tracking_id, content_no, send_status, sent_at) VALUES
    (70, 1, '0000000070', 1, 'sent', '2026-09-25 09:00:00'),
    (70, 2, '0000000071', 1, 'failed', '2026-09-26 09:00:00'),
    (71, 1, '0000000072', 1, 'sent', '2026-09-24 09:00:00')");
Db::run("INSERT INTO edu_deliveries (tenant_id, title, status) VALUES (1, 'a', 'running'), (1, 'b', 'closed')");
$r = call_handler('tenants_handle_list', [], SA, []);
check($r['code'] === 200 && $r['payload']['retention_days'] === 90, 'T4-1: 一覧は保持期間の日数も返す');
$byId = array_column($r['payload']['tenants'], null, 'id');
$t1 = $byId[1];
$expectedTargets = (int) Db::one("SELECT COUNT(*) AS c FROM targets WHERE tenant_id = 1 AND status = 'active' AND is_test = 0")['c'];
check($t1['target_count'] === $expectedTargets, 'T4-2: 対象者数は有効で検証用を除いた数');
check($t1['user_count'] === (int) Db::one('SELECT COUNT(*) AS c FROM users WHERE tenant_id = 1')['c'], 'T4-3: ユーザ数');
check($t1['campaign_count'] === 3, 'T4-4: キャンペーン数は削除済みを除く(fixture の1件 + 実行中 + 予約)');
check($t1['campaign_active_count'] === 2, 'T4-5: 実行中・予約中のキャンペーンの数');
check($t1['edu_delivery_count'] === 2 && $t1['edu_active_count'] === 1, 'T4-6: 教育配信の数と実行中の数');
check($t1['last_sent_at'] === '2026-09-25 09:00:00', 'T4-7: 最後の送信日は送信できた宛先の最新の sent_at');
check($t1['last_login_at'] >= '2026-09-20 10:00:00', 'T4-8: 最後のログイン日はユーザの最新');
check(isset($byId[$created]) && $byId[$created]['status'] === 'deleted' && $byId[$created]['retention_days_left'] === 90,
    'T4-9: 削除済みも一覧に含め、状態と残り日数を返す(画面で分けて出す)');

// ---------- T5 詳細 ----------
for ($i = 0; $i < 25; $i++) {
    Db::run("INSERT INTO audit_log (tenant_id, user_id, action, detail) VALUES (1, 1, 'target.update', ?)", ['n=' . $i]);
}
Db::run("INSERT INTO audit_log (tenant_id, user_id, action, detail) VALUES (NULL, NULL, 'tenant.update', 'tenant_id=1,fields=name')");
Db::run("INSERT INTO audit_log (tenant_id, user_id, action, detail) VALUES (NULL, NULL, 'tenant.update', 'tenant_id=10,fields=name')");
Db::run("INSERT INTO audit_log (tenant_id, user_id, action, detail) VALUES (2, 2, 'target.update', 'other-tenant')");
$_GET['id'] = '1';
$r = call_handler('tenants_handle_get', [], SA, []);
check($r['code'] === 200, 'T5-1: 詳細を返す');
check($r['payload']['tenant']['campaign_active_count'] === 2, 'T5-1: 概要の数字は一覧と同じ');
check(count($r['payload']['users']) === (int) Db::one('SELECT COUNT(*) AS c FROM users WHERE tenant_id = 1')['c'], 'T5-2: そのテナントのユーザの一覧');
check(array_keys($r['payload']['users'][0]) === ['id', 'email', 'name', 'role', 'status', 'last_login_at', 'created_at'],
    'T5-2: ユーザはパスワードのハッシュを返さない');
check(count($r['payload']['audit']) === 20, 'T5-3: 最近の操作は20件');
check($r['payload']['audit'][0]['action'] === 'tenant.update' && $r['payload']['audit'][0]['detail'] === 'tenant_id=1,fields=name',
    'T5-3: superadmin のこのテナントへの操作も含め、新しい順');
$details = array_column($r['payload']['audit'], 'detail');
check(!in_array('tenant_id=10,fields=name', $details, true) && !in_array('other-tenant', $details, true), 'T5-4: ほかのテナントの操作は含めない');
$_GET['id'] = '999999';
$r = call_handler('tenants_handle_get', [], SA, []);
check($r['code'] === 404, 'T5-5: ないテナントは 404');
$_GET['id'] = 'abc';
$r = call_handler('tenants_handle_get', [], SA, []);
check($r['code'] === 400, 'T5-6: id が不正なら 400');
unset($_GET['id']);

echo "ALL TESTS PASSED\n";
