<?php
declare(strict_types=1);

/**
 * send_endpoints API(送信エンドポイントのマスタ)のテスト。
 * - kind ごとの value 検証(beacon=URL、from=メール)
 * - 共有(tenant_id NULL)の作成・編集はシステム管理者(superadmin)だけ
 * - テナント分離(自テナント＋共有だけ見える/触れる)
 * - list は共有＋自テナントを返し、kind で絞れる
 * - CSRF、409(重複)
 */

require_once __DIR__ . '/helpers.php';
tet2_test_boot();
load_api('send_endpoints');

// migration で入る共有の既定IP を揃える(TestDatabase が schema-multi-endpoint.sql を適用済み)。
$sharedDefault = (int) Db::one("SELECT COUNT(*) AS c FROM send_endpoints WHERE tenant_id IS NULL AND kind='beacon' AND value='http://85.131.251.224/'")['c'];
check($sharedDefault === 1, '共有の既定IP が1件だけ入っている');

// ---- list: 既定は共有の beacon 1件が見える ----
$res = call_handler('send_endpoint_handle_list', [], 'viewer');
check($res['code'] === 200, 'list は viewer で 200');
check(count($res['payload']['endpoints']) === 1 && $res['payload']['endpoints'][0]['shared'] === true, '共有の既定 beacon が list に出る');

// ---- create: kind 検証 ----
$res = call_handler('send_endpoint_handle_create', ['kind' => 'beacon', 'value' => 'not-a-url'], 'tenant_admin');
check($res['code'] === 400, 'beacon に非URLは 400');
$res = call_handler('send_endpoint_handle_create', ['kind' => 'from', 'value' => 'not-an-email'], 'tenant_admin');
check($res['code'] === 400, 'from に非メールは 400');
$res = call_handler('send_endpoint_handle_create', ['kind' => 'bogus', 'value' => 'x'], 'tenant_admin');
check($res['code'] === 400, '未知の kind は 400');

// 注: 最低ロール(GET=viewer / POST=tenant_admin)の判定はディスパッチ側 require_role が担う。
// ここではハンドラを直接呼ぶため、共有=superadmin 限定など「行の所有に基づく」権限を検証する。

// ---- create: テナントの from(tenant_admin) ----
$res = call_handler('send_endpoint_handle_create', ['kind' => 'from', 'value' => 'sales@example.test', 'label' => '営業'], 'tenant_admin');
check($res['code'] === 201, 'tenant_admin はテナントの from を作れる');
$tenantFromId = (int) $res['payload']['id'];
check($GLOBALS['__TET2_TEST_CSRF_CALLS'] === 1, 'create は CSRF を要求する');
$row = Db::one('SELECT * FROM send_endpoints WHERE id = ?', [$tenantFromId]);
check((int) $row['tenant_id'] === 1 && $row['kind'] === 'from', 'テナント1の from として保存される');

// ---- create: 重複は 409 ----
$res = call_handler('send_endpoint_handle_create', ['kind' => 'from', 'value' => 'sales@example.test'], 'tenant_admin');
check($res['code'] === 409, '同一 (tenant,kind,value) の重複は 409');

// ---- create: beacon(URL 正規化はしないがスキーム検証は通す) ----
$res = call_handler('send_endpoint_handle_create', ['kind' => 'beacon', 'value' => 'https://track.example.test/'], 'tenant_admin');
check($res['code'] === 201, 'テナントの beacon を作れる');

// ---- 共有の作成は superadmin だけ ----
$res = call_handler('send_endpoint_handle_create', ['kind' => 'from', 'value' => 'noreply@shared.test', 'shared' => true], 'tenant_admin');
// tenant_admin が shared:true を送っても、createShared は superadmin のみ true。よってテナント行になり作成成功する。
check($res['code'] === 201, 'tenant_admin の shared 指定はテナント行として作成される(共有にはならない)');
$mk = (int) $res['payload']['id'];
check(Db::one('SELECT tenant_id FROM send_endpoints WHERE id = ?', [$mk])['tenant_id'] !== null, 'tenant_admin は共有行を作れない(tenant_id が入る)');

$res = call_handler('send_endpoint_handle_create', ['kind' => 'from', 'value' => 'noreply2@shared.test', 'shared' => true], 'superadmin');
check($res['code'] === 201, 'superadmin は共有の from を作れる');
$sharedFromId = (int) $res['payload']['id'];
check(Db::one('SELECT tenant_id FROM send_endpoints WHERE id = ?', [$sharedFromId])['tenant_id'] === null, 'superadmin の shared は tenant_id NULL(共有)');

// ---- 共有行の編集は superadmin だけ ----
$res = call_handler('send_endpoint_handle_update', ['id' => $sharedFromId, 'label' => '共有の差出人'], 'tenant_admin');
check($res['code'] === 403, 'tenant_admin は共有行を編集できない');
$res = call_handler('send_endpoint_handle_update', ['id' => $sharedFromId, 'label' => '共有の差出人'], 'superadmin');
check($res['code'] === 200, 'superadmin は共有行を編集できる');
check(Db::one('SELECT label FROM send_endpoints WHERE id = ?', [$sharedFromId])['label'] === '共有の差出人', '共有行の label が更新される');

// ---- update: value の kind 検証(from 行に非メールは 400) ----
$res = call_handler('send_endpoint_handle_update', ['id' => $tenantFromId, 'value' => 'bad'], 'tenant_admin');
check($res['code'] === 400, 'from 行の value 更新でも非メールは 400');
$res = call_handler('send_endpoint_handle_update', ['id' => $tenantFromId, 'value' => 'sales2@example.test'], 'tenant_admin');
check($res['code'] === 200, 'from 行の value を更新できる');

// ---- list: kind フィルタと共有＋テナントの合成 ----
$res = call_handler('send_endpoint_handle_list', [], 'tenant_admin');
$kinds = array_count_values(array_column($res['payload']['endpoints'], 'kind'));
check(($kinds['beacon'] ?? 0) === 2 && ($kinds['from'] ?? 0) === 3, 'list は共有＋テナントの beacon2・from3 を返す(既定IP+テナントbeacon / テナント2+共有1)');
$GLOBALS['tmpG'] = null;
$_GET['kind'] = 'from';
$res = call_handler('send_endpoint_handle_list', [], 'tenant_admin');
unset($_GET['kind']);
check(count($res['payload']['endpoints']) === 3 && array_values(array_unique(array_column($res['payload']['endpoints'], 'kind'))) === ['from'], 'kind=from で from だけに絞れる');

// ---- テナント分離: 他テナント(tenant 2)の行は見えない ----
Db::run("INSERT INTO send_endpoints (tenant_id, kind, value) VALUES (2, 'from', 'other@t2.test')");
$res = call_handler('send_endpoint_handle_list', [], 'tenant_admin');
$values = array_column($res['payload']['endpoints'], 'value');
check(!in_array('other@t2.test', $values, true), '他テナントの行は list に出ない(テナント分離)');
// 他テナント行の編集は 403/404
$otherId = (int) Db::one("SELECT id FROM send_endpoints WHERE value='other@t2.test'")['id'];
$res = call_handler('send_endpoint_handle_update', ['id' => $otherId, 'label' => 'x'], 'tenant_admin');
check($res['code'] === 404, '他テナントの行は見えない(404)');

// ---- delete: 自テナントの行を消せる。過去のキャンペーンの確定値は壊れない(値のコピーのため) ----
$res = call_handler('send_endpoint_handle_delete', ['id' => $tenantFromId], 'tenant_admin');
check($res['code'] === 200 && Db::one('SELECT id FROM send_endpoints WHERE id = ?', [$tenantFromId]) === null, '自テナントの行を削除できる');
$res = call_handler('send_endpoint_handle_delete', ['id' => $sharedFromId], 'tenant_admin');
check($res['code'] === 403, '共有行の削除は tenant_admin では 403');

echo "ALL TESTS PASSED\n";
