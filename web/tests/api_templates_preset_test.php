<?php
declare(strict_types=1);

/**
 * 共有プリセットテンプレートの編集権限テスト(2026-07-19)。
 * 共有プリセット(tenant_id NULL / is_preset=1)はほかのテナントの訓練にも効くので、
 * 編集と削除は superadmin だけ(2026-09-28 から。以前は tenant_admin 以上)。operator と tenant_admin は 403。
 */

require_once __DIR__ . '/helpers.php';
tet2_test_boot();
load_api('templates');

// 共有プリセットの subject を1件用意(なければ作る)。
$preset = Db::one("SELECT id, content FROM templates WHERE kind='subject' AND is_preset=1 AND tenant_id IS NULL LIMIT 1");
if ($preset === null) {
    Db::run("INSERT INTO templates (tenant_id, kind, name, content, is_preset) VALUES (NULL,'subject','PRESET_TEST','元の件名',1)");
    $preset = Db::one("SELECT id, content FROM templates WHERE name='PRESET_TEST'");
}
$id = (int) $preset['id'];
$orig = (string) $preset['content'];

// operator は共有プリセットを編集できない → 403
$r = call_handler('templates_handle_update', ['id' => $id, 'content' => 'operatorによる変更'], 'operator');
check($r['code'] === 403, 'operator による共有プリセット編集 → 403');
check(Db::one('SELECT content FROM templates WHERE id = ?', [$id])['content'] === $orig, 'operator の変更は反映されない');

// tenant_admin も共有プリセットは編集できない(自テナントの素材だけ) → 403
$r = call_handler('templates_handle_update', ['id' => $id, 'content' => 'tenant_adminによる変更'], 'tenant_admin');
check($r['code'] === 403, 'tenant_admin による共有プリセット編集 → 403');
check(Db::one('SELECT content FROM templates WHERE id = ?', [$id])['content'] === $orig, 'tenant_admin の変更は反映されない');

// superadmin は編集できる → 200 + 反映
$r = call_handler('templates_handle_update', ['id' => $id, 'content' => 'superadminによる変更'], 'superadmin');
check($r['code'] === 200, 'superadmin による共有プリセット編集 → 200');
check(Db::one('SELECT content FROM templates WHERE id = ?', [$id])['content'] === 'superadminによる変更', 'superadmin の変更が反映される');

// ---- 共有プリセットの削除権限(2026-07-19) ----
// 削除テスト用の未使用共有プリセットを作る。
Db::run("INSERT INTO templates (tenant_id, kind, name, content, is_preset) VALUES (NULL,'subject','DEL_PRESET_TEST','削除テスト',1)");
$delId = (int) Db::one("SELECT id FROM templates WHERE name='DEL_PRESET_TEST'")['id'];

// operator は共有プリセットを削除できない → 403
$r = call_handler('templates_handle_delete', ['id' => $delId], 'operator');
check($r['code'] === 403, 'operator の共有プリセット削除 → 403');
check(Db::one('SELECT id FROM templates WHERE id = ?', [$delId]) !== null, '削除されず残る');

// 使用中(campaign_contents 参照)の共有プリセットは 409
Db::run("INSERT INTO campaigns (tenant_id, name, status) VALUES (1,'DEL_USE_CAMP','draft')");
$useCamp = (int) Db::one("SELECT id FROM campaigns WHERE name='DEL_USE_CAMP'")['id'];
Db::run('INSERT INTO campaign_contents (campaign_id, content_no, subject_template_id, link_mode) VALUES (?,1,?,?)', [$useCamp, $delId, 'link']);
$r = call_handler('templates_handle_delete', ['id' => $delId], 'tenant_admin');
check($r['code'] === 403, 'tenant_admin の共有プリセット削除 → 403');
$r = call_handler('templates_handle_delete', ['id' => $delId], 'superadmin');
check($r['code'] === 409, '使用中の共有プリセット削除 → 409');

// 使用参照を消せば superadmin が削除できる → 200
Db::run('DELETE FROM campaign_contents WHERE campaign_id = ?', [$useCamp]);
$r = call_handler('templates_handle_delete', ['id' => $delId], 'superadmin');
check($r['code'] === 200, 'superadmin の共有プリセット削除(未使用) → 200');
check(Db::one('SELECT id FROM templates WHERE id = ?', [$delId]) === null, '実際に削除される');

echo "ALL TESTS PASSED\n";
