<?php
declare(strict_types=1);

/**
 * test_redirect_emails 任意化の回帰テスト(2026-08-12)。
 *
 * バグ: is_test OFF でも app.js が test_redirect_emails を空文字 '' で送るため、
 *   campaigns_optional_string が「空文字=不正」と弾き保存できなかった。
 * 修正: campaigns_optional_string_or_null で空文字を NULL 扱いにする。
 *
 * 検証:
 *  - create: test_redirect_emails='' (is_test OFF) で 201、DBは NULL
 *  - create: キー未指定でも 201、DBは NULL
 *  - create: is_test ON + 実メール指定なら従来通り保存される
 *  - update: test_redirect_emails='' で 200(不正エラーが出ない)
 */

require_once __DIR__ . '/helpers.php';
$dbPath = tet2_test_boot();
load_api('campaigns');

$tenantId = current_user()['tenant_id'];

function mk_tpl_tr(int $tenantId, string $kind, string $name, string $content, ?int $authFlag = null): int
{
    Db::run('INSERT INTO templates (tenant_id, kind, name, content, auth_flag) VALUES (?,?,?,?,?)',
        [$tenantId, $kind, $name, $content, $authFlag]);
    return (int) Db::one('SELECT id FROM templates WHERE tenant_id = ? AND name = ?', [$tenantId, $name])['id'];
}
$subj = mk_tpl_tr($tenantId, 'subject', 'TR_SUBJ', '件名');
$body = mk_tpl_tr($tenantId, 'body', 'TR_BODY', '本文 #$2$#');
$phish = mk_tpl_tr($tenantId, 'phish_login', 'TR_PHISH', '偽ログイン', 0);

$tids = [];
foreach (['tr1@test', 'tr2@test'] as $i => $em) {
    Db::run('INSERT INTO targets (tenant_id, email, name, status) VALUES (?,?,?,?)', [$tenantId, $em, 'TR' . $i, 'active']);
    $tids[] = (int) Db::one('SELECT id FROM targets WHERE tenant_id = ? AND email = ?', [$tenantId, $em])['id'];
}

$now = '2026-08-12 09:00:00';
$baseReq = [
    'from_address' => 'tr@example.com',
    'send_mode' => 'normal',
    'start_at' => $now,
    'end_at' => '2026-08-20 18:00:00',
    'target_ids' => $tids,
    'contents' => [
        ['subject_template_id' => $subj, 'body_template_id' => $body, 'phish_template_id' => $phish, 'link_mode' => 'link'],
    ],
];

// --- ケース1: is_test OFF + test_redirect_emails='' → 保存できる(バグ再現→修正確認) ---
$req1 = $baseReq;
$req1['name'] = 'TR_EMPTY';
$req1['is_test'] = false;
$req1['test_redirect_emails'] = '';
$r1 = call_handler('campaigns_handle_create', $req1, 'operator');
check($r1['code'] === 201 || $r1['code'] === 200, "is_test OFF + 空文字 → 保存できる(code={$r1['code']})");
$cid1 = (int) ($r1['payload']['campaign']['id'] ?? 0);
$saved1 = Db::one('SELECT test_redirect_emails, is_test FROM campaigns WHERE id = ?', [$cid1]);
check($saved1['test_redirect_emails'] === null, '空文字は DB に NULL で保存される');
check((int) $saved1['is_test'] === 0, 'is_test は 0');

// --- ケース2: キー未指定でも保存できる ---
$req2 = $baseReq;
$req2['name'] = 'TR_ABSENT';
$req2['is_test'] = false;
// test_redirect_emails キーなし
$r2 = call_handler('campaigns_handle_create', $req2, 'operator');
check($r2['code'] === 201 || $r2['code'] === 200, 'キー未指定 → 保存できる');
$cid2 = (int) ($r2['payload']['campaign']['id'] ?? 0);
$saved2 = Db::one('SELECT test_redirect_emails FROM campaigns WHERE id = ?', [$cid2]);
check($saved2['test_redirect_emails'] === null, 'キー未指定は DB に NULL');

// --- ケース3: is_test ON + 実メール指定 → 従来通り保存される(回帰確認) ---
$req3 = $baseReq;
$req3['name'] = 'TR_ONREAL';
$req3['is_test'] = true;
$req3['test_redirect_emails'] = 'redirect1@example.com,redirect2@example.com';
$r3 = call_handler('campaigns_handle_create', $req3, 'operator');
check($r3['code'] === 201 || $r3['code'] === 200, 'is_test ON + 実メール → 保存できる');
$cid3 = (int) ($r3['payload']['campaign']['id'] ?? 0);
$saved3 = Db::one('SELECT test_redirect_emails, is_test FROM campaigns WHERE id = ?', [$cid3]);
check($saved3['test_redirect_emails'] === 'redirect1@example.com,redirect2@example.com', '実メールはそのまま保存される');
check((int) $saved3['is_test'] === 1, 'is_test は 1');

// --- ケース4: update 経路でも空文字が不正にならない ---
// update は body の id を使い、対象が draft である必要がある(ケース3の cid3 は draft)。
$upd = ['id' => $cid3, 'test_redirect_emails' => ''];
$r4 = call_handler('campaigns_handle_update', $upd, 'operator');
check($r4['code'] === 200 || $r4['code'] === 201, "update で空文字 → 不正にならない(code={$r4['code']})");
$saved4 = Db::one('SELECT test_redirect_emails FROM campaigns WHERE id = ?', [$cid3]);
check($saved4['test_redirect_emails'] === null, 'update 空文字で NULL に更新される');

echo "ALL TESTS PASSED\n";
