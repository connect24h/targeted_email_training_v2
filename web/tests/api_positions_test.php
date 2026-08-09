<?php
declare(strict_types=1);

/**
 * 役職マスタ API の回帰テスト。
 *
 * 重点は次の3つ:
 *  1. マッピング漏れ(unmapped)と未適用のズレ(stale)を coverage が正しく検知すること
 *  2. apply が他テナントの対象者を巻き込まないこと(相関サブクエリの tenant_id 束縛)
 *  3. 集計がテストユーザ・削除済みを既定で除外すること
 */

require_once __DIR__ . '/helpers.php';
tet2_test_boot();
load_api('positions');

$tenantId = current_user()['tenant_id'];   // = 1 (Example Tenant)
$otherTenantId = 2;

// 役職を持つ対象者を仕込む。シードの target1/2 は title が NULL。
Db::run("UPDATE targets SET title = '部長' WHERE id = 1");
Db::run("UPDATE targets SET title = 'エキスパート' WHERE id = 2");
// 他テナント(t2)にも同名の役職を持つ対象者を置く。apply の越境検証に使う。
Db::run("UPDATE targets SET title = '部長' WHERE id = 3");

// ---- P-1: create → 201 / 一覧に出る ----
$r = call_handler('positions_handle_create', ['title' => '部長', 'category' => '管理職'], 'operator');
check($r['code'] === 201, 'P-1: create → 201');
check(($r['payload']['master']['category'] ?? '') === '管理職', 'P-1: カテゴリが保存される');
$buchoId = (int) $r['payload']['master']['id'];
// 登録した役職の対象者へ即座に反映される(局所適用)
check((int) ($r['payload']['applied'] ?? 0) === 1, 'P-1: create 直後に該当者へ局所適用される');
check(Db::one('SELECT position_category FROM targets WHERE id = 1')['position_category'] === '管理職',
    'P-1: 対象者の position_category が更新される');

$_GET = [];
$r = call_handler('positions_handle_list', [], 'viewer');
check($r['code'] === 200 && count($r['payload']['masters']) === 1, 'P-1: list に1件出る');
check((int) $r['payload']['masters'][0]['target_count'] === 1, 'P-1: list が該当人数を返す');

// ---- P-2: 重複 create → 409(PDOException ではなく事前チェックで返す) ----
$r = call_handler('positions_handle_create', ['title' => '部長', 'category' => '役員'], 'operator');
check($r['code'] === 409, 'P-2: 同一役職名の重複 → 409');

// ---- P-3: 不正なカテゴリ → 400 / 旧称「社員」はエイリアスで通る ----
$r = call_handler('positions_handle_create', ['title' => '主任', 'category' => '社長'], 'operator');
check($r['code'] === 400, 'P-3: 不正カテゴリ → 400');
$r = call_handler('positions_handle_create', ['title' => '主任', 'category' => '社員'], 'operator');
check($r['code'] === 201, 'P-3: 旧称「社員」でも作成できる(エイリアス)');
check(($r['payload']['master']['category'] ?? '') === '一般従業員', 'P-3: 「社員」は「一般従業員」へ正規化される');
$shuninId = (int) $r['payload']['master']['id'];

// title 未指定は 400
$r = call_handler('positions_handle_create', ['category' => '管理職'], 'operator');
check($r['code'] === 400, 'P-3: title 未指定 → 400');

// ---- P-4: coverage が unmapped を返す ----
// 「エキスパート」(target2)はマスタ未登録なので unmapped に出る。
$_GET = [];
$r = call_handler('positions_handle_coverage', [], 'viewer');
check($r['code'] === 200, 'P-4: coverage → 200');
$unmappedTitles = array_column($r['payload']['unmapped'], 'title');
check(in_array('エキスパート', $unmappedTitles, true), 'P-4: マスタ未登録の役職が unmapped に出る');
check(!in_array('部長', $unmappedTitles, true), 'P-4: マスタ登録済みの役職は unmapped に出ない');
check((int) $r['payload']['summary']['unmapped'] === 1, 'P-4: unmapped の人数が集計される');
check($r['payload']['categories'] === ['役員', '管理職', '一般従業員'], 'P-4: categories が正規値3つ');

// ---- P-5: coverage が stale を返す(マスタを直したが未適用の状態) ----
Db::run("UPDATE position_masters SET category = '役員' WHERE id = ?", [$buchoId]);
$_GET = [];
$r = call_handler('positions_handle_coverage', [], 'viewer');
$staleTitles = array_column($r['payload']['stale'], 'title');
check(in_array('部長', $staleTitles, true), 'P-5: マスタと対象者のズレが stale に出る');
$staleRow = null;
foreach ($r['payload']['stale'] as $s) { if ($s['title'] === '部長') { $staleRow = $s; } }
check(($staleRow['master_category'] ?? '') === '役員', 'P-5: stale がマスタ側のカテゴリを返す');
check(($staleRow['current_category'] ?? '') === '管理職', 'P-5: stale が対象者側の現在値を返す');

// ---- P-6: apply が対象者を更新し件数を返す ----
$r = call_handler('positions_handle_apply', [], 'operator');
check($r['code'] === 200, 'P-6: apply → 200');
check((int) $r['payload']['updated'] === 1, 'P-6: apply が更新件数を返す');
check(Db::one('SELECT position_category FROM targets WHERE id = 1')['position_category'] === '役員',
    'P-6: apply でマスタの値が対象者へ反映される');
// 適用後は stale が消える
$_GET = [];
$r = call_handler('positions_handle_coverage', [], 'viewer');
check($r['payload']['stale'] === [], 'P-6: apply 後に stale が解消される');
// マスタに無い役職は apply でも触られない
check(Db::one('SELECT position_category FROM targets WHERE id = 2')['position_category'] === null,
    'P-6: マスタ未登録の役職は apply で変更されない');

// ---- P-7: apply が他テナントの対象者を巻き込まない(最重要) ----
// t2 の target3 も title='部長' だが、t1 のマスタが適用されてはならない。
check(Db::one('SELECT position_category FROM targets WHERE id = 3')['position_category'] === null,
    'P-7: apply が他テナントの対象者を更新しない(tenant_id 束縛)');
// 他テナントのマスタも見えない
$r = call_handler('positions_handle_create', ['title' => '部長', 'category' => '管理職', 'tenant_id' => $otherTenantId], 'operator');
check($r['code'] === 403, 'P-7: 他テナント指定の create → 403(IDOR防御)');

// ---- P-8: update / delete ----
$r = call_handler('positions_handle_update', ['id' => $buchoId, 'category' => '管理職', 'note' => 'テスト'], 'operator');
check($r['code'] === 200, 'P-8: update → 200');
check(($r['payload']['master']['category'] ?? '') === '管理職', 'P-8: カテゴリが更新される');
check(($r['payload']['master']['note'] ?? '') === 'テスト', 'P-8: メモが更新される');
// title は変更不可(targets との突合キーのため)
$r = call_handler('positions_handle_update', ['id' => $buchoId, 'title' => '本部長'], 'operator');
check($r['code'] === 400, 'P-8: title の変更 → 400');
// 更新項目なしは 400
$r = call_handler('positions_handle_update', ['id' => $buchoId], 'operator');
check($r['code'] === 400, 'P-8: 更新項目なし → 400');
// 他テナントのマスタは 404
$otherId = Db::insert("INSERT INTO position_masters (tenant_id, title, category) VALUES (?, '他社役職', '管理職')", [$otherTenantId]);
$r = call_handler('positions_handle_update', ['id' => $otherId, 'category' => '役員'], 'operator');
check($r['code'] === 404, 'P-8: 他テナントのマスタ update → 404(IDOR防御)');

// delete しても対象者の position_category は消えない(誤クリック事故の防止)
$r = call_handler('positions_handle_delete', ['id' => $shuninId], 'operator');
check($r['code'] === 200, 'P-8: delete → 200');
check(Db::one('SELECT id FROM position_masters WHERE id = ?', [$shuninId]) === null, 'P-8: マスタが実際に削除される');
// delete したのは「主任」マスタ。target1 の値(P-6 の apply で入った「役員」)は残る。
// マスタ更新(P-8 の update)は apply するまで対象者へ反映されない設計。
check(Db::one('SELECT position_category FROM targets WHERE id = 1')['position_category'] === '役員',
    'P-8: delete しても対象者の役職カテゴリは残る');

// ---- P-9: 集計が is_test を既定で除外する ----
Db::run('UPDATE targets SET is_test = 1 WHERE id = 2');
$_GET = [];
$r = call_handler('positions_handle_by_company', [], 'viewer');
check($r['code'] === 200, 'P-9: by_company → 200');
check((int) $r['payload']['total'] === 1, 'P-9: by_company の既定がテストユーザを除外する');

$_GET = ['include_test' => '1'];
$r = call_handler('positions_handle_by_company', [], 'viewer');
check((int) $r['payload']['total'] === 2, 'P-9: include_test=1 でテストユーザを含める');
check($r['payload']['scope']['include_test'] === true, 'P-9: scope が include_test を返す');

// ---- P-10: 集計が archived を既定で除外する ----
Db::run('UPDATE targets SET is_test = 0 WHERE id = 2');
Db::run("UPDATE targets SET status = 'archived' WHERE id = 2");
$_GET = [];
$r = call_handler('positions_handle_by_position', [], 'viewer');
check($r['code'] === 200, 'P-10: by_position → 200');
check((int) $r['payload']['total'] === 1, 'P-10: by_position の既定が削除済みを除外する');

$_GET = ['include_archived' => '1'];
$r = call_handler('positions_handle_by_position', [], 'viewer');
check((int) $r['payload']['total'] === 2, 'P-10: include_archived=1 で削除済みを含める');
// 集計側でもマスタ未登録が識別できる
$byTitle = $r['payload']['by_title'];
$expert = null;
foreach ($byTitle as $row) { if ($row['title'] === 'エキスパート') { $expert = $row; } }
check($expert !== null && (int) $expert['in_master'] === 0, 'P-10: by_title がマスタ未登録を in_master=0 で示す');
$bucho = null;
foreach ($byTitle as $row) { if ($row['title'] === '部長') { $bucho = $row; } }
check($bucho !== null && (int) $bucho['in_master'] === 1, 'P-10: マスタ登録済みは in_master=1');

// 会社別集計はカテゴリ内訳を返す
$_GET = [];
$r = call_handler('positions_handle_by_company', [], 'viewer');
check(isset($r['payload']['rows'][0]['by_category']), 'P-10: by_company がカテゴリ内訳を返す');

// ---- P-11: viewer は変更系を叩けない ----
// ロール判定はディスパッチ側の require_role が担うため(ハンドラ内では呼ばない)、
// ここでは POST=operator という要求がその関門で弾かれることを直接検証する。
$_GET = [];
$GLOBALS['__TET2_TEST_ROLE'] = 'viewer';
$denied = false;
try {
    require_role('operator');
} catch (Tet2TestExit $e) {
    $denied = ($e->httpCode === 403);
}
check($denied, 'P-11: viewer が POST 系のロール要求(operator)で 403 になる');
$GLOBALS['__TET2_TEST_ROLE'] = 'viewer';
$allowed = true;
try {
    require_role('viewer');
} catch (Tet2TestExit) {
    $allowed = false;
}
check($allowed, 'P-11: viewer は GET 系(viewer)なら通る');
$GLOBALS['__TET2_TEST_ROLE'] = 'operator';

// ---- P-12: CSRF が変更系で検証される ----
$GLOBALS['__TET2_TEST_CSRF_CALLS'] = 0;
call_handler('positions_handle_create', ['title' => 'CSRF確認', 'category' => '管理職'], 'operator');
check($GLOBALS['__TET2_TEST_CSRF_CALLS'] === 1, 'P-12: create で CSRF 検証が呼ばれる');

echo "ALL TESTS PASSED\n";
