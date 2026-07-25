<?php
declare(strict_types=1);

/**
 * users API の削除回帰テスト。
 * 特に「キャンペーン作成者(campaigns.created_by の外部キー)を削除するとサーバエラー」
 * (2026-07-19 バグ報告)を回帰として固定する。作成者は NULL 化して削除できるべき。
 */

require_once __DIR__ . '/helpers.php';
tet2_test_boot();
load_api('users');

$tenantId = current_user()['tenant_id'];

// 削除操作は tenant_admin。current_user は id=1 を返すので、削除対象は別ユーザにする。
// このテナントにキャンペーン作成者のユーザを1人用意する。
$maker = Db::one(
    'SELECT u.id FROM users u JOIN campaigns c ON c.created_by = u.id WHERE u.tenant_id = ? AND u.id != 1 LIMIT 1',
    [$tenantId]
);
if ($maker === null) {
    // 作成者ユーザが居なければ、テスト用に作って campaign を紐付ける。
    Db::run('INSERT INTO users (tenant_id, email, name, role, status, password_hash) VALUES (?,?,?,?,?,?)',
        [$tenantId, 'maker_del@test', 'maker', 'operator', 'active', 'x']);
    $makerId = (int) Db::one('SELECT id FROM users WHERE tenant_id = ? AND email = ?', [$tenantId, 'maker_del@test'])['id'];
    Db::run("INSERT INTO campaigns (tenant_id, name, status, created_by) VALUES (?, 'USERDEL_TEST', 'draft', ?)", [$tenantId, $makerId]);
} else {
    $makerId = (int) $maker['id'];
}

$campaignCountBefore = (int) Db::one('SELECT COUNT(*) c FROM campaigns WHERE created_by = ?', [$makerId])['c'];
check($campaignCountBefore >= 1, "削除対象ユーザはキャンペーン作成者(created_by {$campaignCountBefore}件)");

// --- バグ回帰: キャンペーン作成者を削除 → 200(FK違反でサーバエラーにならない) ---
$r = call_handler('users_handle_delete', ['id' => $makerId], 'tenant_admin');
check($r['code'] === 200, 'キャンペーン作成者の削除 → 200(FK違反でエラーにならない)');
check(Db::one('SELECT id FROM users WHERE id = ?', [$makerId]) === null, 'ユーザは実際に削除された');
$orphan = Db::one('SELECT COUNT(*) c FROM campaigns WHERE created_by = ?', [$makerId])['c'];
check((int) $orphan === 0, '当該ユーザ作成の campaigns.created_by は NULL 化された');
// キャンペーン自体は残る(履歴保持)
$stillThere = Db::one('SELECT COUNT(*) c FROM campaigns WHERE tenant_id = ? AND created_by IS NULL', [$tenantId])['c'];
check((int) $stillThere >= 1, 'キャンペーン自体は残る(created_by=NULL で保持)');

// --- 自分自身は削除できない ---
$r = call_handler('users_handle_delete', ['id' => 1], 'tenant_admin');
check($r['code'] === 400, '自分自身の削除 → 400');

// --- 存在しないユーザ → 404 ---
$r = call_handler('users_handle_delete', ['id' => 999999], 'tenant_admin');
check($r['code'] === 404, '存在しないユーザの削除 → 404');

echo "ALL TESTS PASSED\n";
