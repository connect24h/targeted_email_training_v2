<?php
declare(strict_types=1);

/**
 * ユーザ管理 CSV 取込の名簿同期・役職カテゴリ・新規役職検知(2026-11-05)。
 *  - 利用者書式のヘッダー(社員番号/表示氏名（姓名）/メールアドレス（会社）/会社名/本務役職名称)を取り込む
 *  - roster_sync=ON: CSV にいない既存者を archived にする。CSV にいる人は据え置き。is_test は除外。成功0件なら同期しない
 *  - roster_sync 既定 OFF: archived 化しない(後方互換)
 *  - レスポンスに unmapped(役職マスタに無い新規役職)と archived 件数が入る
 */
require_once __DIR__ . '/helpers.php';
tet2_test_boot();
load_api('targets');

$tenantId = (int) current_user()['tenant_id'];
$row = static fn (string $email): ?array => Db::one('SELECT * FROM targets WHERE tenant_id = ? AND email = ?', [$tenantId, $email]);
$import = static fn (array $body, string $role = 'operator'): array => call_handler('targets_handle_import_csv', $body, $role);

// 合成DB(synthetic.sql)に元から居る対象者は名簿同期の検証対象から外す。
// このテストが自分で作る taro/hanako/jiro だけで archived 判定を確かめたいので、
// 既存の現役者を先に archived にしておく(名簿同期は archived を対象にしない)。
Db::run("UPDATE targets SET status = 'archived' WHERE tenant_id = ? AND status != 'archived'", [$tenantId]);

// ===== A. 利用者書式のヘッダーを取り込む =====
$csv = "No,社員番号,表示氏名（姓名）,メールアドレス（会社）,会社名,本務役職名称\n"
     . "1,E-100,山田 太郎,taro@corp.test,コープ商事,部長\n"
     . "2,E-101,鈴木 花子,hanako@corp.test,コープ商事,一般\n";
$r = $import(['csv' => $csv]);
check($r['code'] === 200, 'A: 利用者書式ヘッダーの取込が 200');
check($r['payload']['imported'] === 2, 'A: 2件取り込む');
$taro = $row('taro@corp.test');
check($taro !== null && $taro['employee_no'] === 'E-100' && $taro['name'] === '山田 太郎'
    && $taro['company'] === 'コープ商事' && $taro['title'] === '部長',
    'A: 社員番号/氏名/会社/役職が列名どおり入る(No は無視)');

// ===== C. 役職マスタに無い役職は unmapped として返る =====
// 「部長」「一般」はこの合成DBの position_masters に無い想定 → unmapped に出る。
check(isset($r['payload']['unmapped']) && is_array($r['payload']['unmapped']),
    'C: レスポンスに unmapped 配列がある');
$unmappedTitles = array_column($r['payload']['unmapped'], 'title');
check(in_array('部長', $unmappedTitles, true) && in_array('一般', $unmappedTitles, true),
    'C: 新規役職(部長・一般)が unmapped に挙がる');

// 役職マスタに「部長」=管理職 を登録してから取り込むと、position_category が補完され unmapped から外れる。
Db::run("INSERT INTO position_masters (tenant_id, title, category, sort_order) VALUES (?, '部長', '管理職', 0)", [$tenantId]);
$r2 = $import(['csv' => $csv]);
$taro2 = $row('taro@corp.test');
check($taro2['position_category'] === '管理職', 'C: 役職マスタ登録後の再取込でカテゴリが補完される');
$unmapped2 = array_column($r2['payload']['unmapped'], 'title');
check(!in_array('部長', $unmapped2, true) && in_array('一般', $unmapped2, true),
    'C: マスタに入れた部長は unmapped から消え、一般は残る');

// ===== B. roster_sync 既定OFFでは archived 化しない =====
// いま taro/hanako が居る。別CSV(jiro だけ)を roster_sync なしで取り込む。
$csvJiro = "社員番号,表示氏名（姓名）,メールアドレス（会社）,会社名,本務役職名称\n"
         . "E-200,佐藤 次郎,jiro@corp.test,コープ商事,一般\n";
$r = $import(['csv' => $csvJiro]);
check($r['code'] === 200 && $r['payload']['imported'] === 1, 'B: 追記取込で jiro を1件追加');
check(($r['payload']['archived_count'] ?? 0) === 0, 'B: roster_sync OFF(既定)では archived 化しない');
check($row('taro@corp.test')['status'] === 'active' && $row('hanako@corp.test')['status'] === 'active',
    'B: OFF では既存の taro/hanako は active のまま');

// ===== B. roster_sync=ON で CSV にいない既存者を archived =====
// テストユーザ(is_test=1)は名簿同期の対象外にする。
Db::run("INSERT INTO targets (tenant_id, email, name, is_test, status) VALUES (?, 'testuser@corp.test', 'テスト', 1, 'active')", [$tenantId]);
// 名簿CSV = taro と jiro だけ(hanako は載せない → 退職扱いになるはず)。
$roster = "社員番号,表示氏名（姓名）,メールアドレス（会社）,会社名,本務役職名称\n"
        . "E-100,山田 太郎,taro@corp.test,コープ商事,部長\n"
        . "E-200,佐藤 次郎,jiro@corp.test,コープ商事,一般\n";
$r = $import(['csv' => $roster, 'roster_sync' => true]);
check($r['code'] === 200, 'B: roster_sync=ON の取込が 200');
check($row('taro@corp.test')['status'] === 'active' && $row('jiro@corp.test')['status'] === 'active',
    'B: CSV に載っている taro/jiro は active のまま');
check($row('hanako@corp.test')['status'] === 'archived',
    'B: CSV に載っていない hanako は archived(退職)になる');
check($row('testuser@corp.test')['status'] === 'active',
    'B: テストユーザ(is_test=1)は名簿同期の対象外で active のまま');
check(($r['payload']['archived_count'] ?? 0) === 1, 'B: archived_count=1 を返す');
$archivedEmails = array_column($r['payload']['archived'] ?? [], 'email');
check(in_array('hanako@corp.test', $archivedEmails, true), 'B: archived 一覧に hanako が入る');

// ===== B. roster_sync=ON でも成功0件なら同期しない(誤爆防止) =====
// 全行が不正(email 不正)な名簿を ON で投げても、既存者を archived にしない。
Db::run("UPDATE targets SET status='active' WHERE tenant_id=? AND email='hanako@corp.test'", [$tenantId]);
$badRoster = "社員番号,表示氏名（姓名）,メールアドレス（会社）,会社名,本務役職名称\n"
           . "E-900,壊れ 行,not-an-email,コープ商事,一般\n";
$r = $import(['csv' => $badRoster, 'roster_sync' => true]);
check(($r['payload']['imported'] ?? 0) === 0 && ($r['payload']['archived_count'] ?? 0) === 0,
    'B: 成功0件の名簿は同期しない(hanako を誤って archived にしない)');
check($row('hanako@corp.test')['status'] === 'active', 'B: 誤爆防止で hanako は active のまま');

echo "\n✅ targets_roster_csv_test 完了\n";
