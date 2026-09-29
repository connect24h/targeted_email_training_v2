<?php
declare(strict_types=1);

/**
 * シナリオの概要(A-6、G13)のテスト。
 * - 作成、シナリオの作成、更新で概要を保存し、一覧と取得で返す
 * - 共有プリセットの概要はシステム管理者だけが変えられる(内容と同じ権限)
 * - ほかのテナントのテンプレートの概要は変えられない
 * - 長さと制御文字の検証、空文字で消せる、送らなければ変えない
 */

require_once __DIR__ . '/helpers.php';
tet2_test_boot();
load_api('templates');

$desc = "元の事例: 宅配業者を装う不在通知\n手口: 短縮 URL で偽サイトへ誘導\n見分けるポイント: 送信元のドメイン";

// 作成(単独のテンプレート)
$r = call_handler('templates_handle_create', ['kind' => 'debrief', 'name' => '概要つき', 'content' => '<p>x</p>', 'description' => $desc], 'operator');
check($r['code'] === 201 && $r['payload']['template']['description'] === $desc, '作成: 概要を保存して返す');
$ownId = (int) $r['payload']['template']['id'];

// シナリオの作成: 概要は本文の行に入り、件名の行には入らない
$r = call_handler('templates_handle_create_scenario', ['name' => '宅配の不在通知', 'subject_content' => 'お荷物のお届け',
    'body_content' => '本文', 'description' => '  ' . $desc . '  '], 'operator');
check($r['code'] === 201 && $r['payload']['body']['description'] === $desc && $r['payload']['subject']['description'] === null,
    'シナリオの作成: 概要は前後の空白を除いて本文の行に保存する');
$scenarioBodyId = (int) $r['payload']['body']['id'];

// 一覧に概要が出る
$r = call_handler('templates_handle_list', [], 'viewer');
$byId = array_column($r['payload']['templates'], null, 'id');
check($r['code'] === 200 && $byId[$scenarioBodyId]['description'] === $desc && array_key_exists('description', $byId[$ownId]),
    '一覧: 概要を返す');

// 更新: 概要だけを送っても更新できる。内容は変えない
$r = call_handler('templates_handle_update', ['id' => $ownId, 'description' => '改訂した概要'], 'operator');
check($r['code'] === 200 && $r['payload']['template']['description'] === '改訂した概要' && $r['payload']['template']['content'] === '<p>x</p>',
    '更新: 概要だけを変えられる');
check(str_contains($GLOBALS['__TET2_TEST_AUDIT'][0]['detail'], 'description=1'), '更新: 監査に概要の変更を残す');

// 概要を送らなければ変えない
$r = call_handler('templates_handle_update', ['id' => $ownId, 'content' => '<p>y</p>'], 'operator');
check($r['code'] === 200 && $r['payload']['template']['description'] === '改訂した概要', '更新: 概要を送らなければ変えない');

// 空文字で消す
$r = call_handler('templates_handle_update', ['id' => $ownId, 'description' => ''], 'operator');
check($r['code'] === 200 && $r['payload']['template']['description'] === null, '更新: 空文字で概要を消す');

// 検証
$r = call_handler('templates_handle_update', ['id' => $ownId, 'description' => str_repeat('あ', TEMPLATE_DESCRIPTION_MAX + 1)], 'operator');
check($r['code'] === 400, '検証: 上限の文字数を超える概要は 400');
$r = call_handler('templates_handle_update', ['id' => $ownId, 'description' => "制御\x07文字"], 'operator');
check($r['code'] === 400, '検証: 制御文字を含む概要は 400');
$r = call_handler('templates_handle_update', ['id' => $ownId, 'description' => ['配列']], 'operator');
check($r['code'] === 400, '検証: 文字列でない概要は 400');
check(Db::one('SELECT description FROM templates WHERE id = ?', [$ownId])['description'] === null, '検証で拒んだ値は保存しない');

// 閲覧者は変えられない(本番の入口の require_role と同じ判定)
$GLOBALS['__TET2_TEST_ROLE'] = 'viewer';
$denied = false;
try {
    require_role('operator');
} catch (Tet2TestExit $e) {
    $denied = $e->httpCode === 403;
}
check($denied, '権限: 閲覧者は更新の入口で 403');

// 共有プリセット: operator と tenant_admin は 403、superadmin は変えられる
Db::run("INSERT INTO templates (tenant_id, kind, name, content, is_preset) VALUES (NULL, 'body', 'PRESET_DESC', '共有の本文', 1)");
$presetId = (int) Db::one("SELECT id FROM templates WHERE name = 'PRESET_DESC'")['id'];
foreach (['operator', 'tenant_admin'] as $role) {
    $r = call_handler('templates_handle_update', ['id' => $presetId, 'description' => $role . 'の概要'], $role);
    check($r['code'] === 403, "共有プリセット: {$role} は概要を変えられない(403)");
}
check(Db::one('SELECT description FROM templates WHERE id = ?', [$presetId])['description'] === null, '共有プリセット: 拒まれた変更は反映されない');
$r = call_handler('templates_handle_update', ['id' => $presetId, 'description' => '共有の概要'], 'superadmin');
check($r['code'] === 200 && $r['payload']['template']['description'] === '共有の概要', '共有プリセット: システム管理者は概要を変えられる');

// ほかのテナントのテンプレート: 見えず(404)、概要も変わらない
Db::run("INSERT INTO templates (tenant_id, kind, name, content) VALUES (2, 'body', 'OTHER_TENANT', '他社の本文')");
$otherId = (int) Db::one("SELECT id FROM templates WHERE name = 'OTHER_TENANT'")['id'];
$r = call_handler('templates_handle_update', ['id' => $otherId, 'description' => '書き換え'], 'tenant_admin');
check($r['code'] === 404, 'テナントの分離: ほかのテナントのテンプレートは 404');
$r = call_handler('templates_handle_update', ['id' => $otherId, 'description' => '書き換え', 'tenant_id' => 2], 'tenant_admin');
check($r['code'] === 403, 'テナントの分離: ほかのテナントを指定すると 403');
check(Db::one('SELECT description FROM templates WHERE id = ?', [$otherId])['description'] === null, 'テナントの分離: ほかのテナントの概要は変わらない');
$r = call_handler('templates_handle_list', [], 'viewer');
check(!in_array($otherId, array_map('intval', array_column($r['payload']['templates'], 'id')), true), 'テナントの分離: 一覧にほかのテナントの行は出ない');

echo "ALL TESTS PASSED\n";
