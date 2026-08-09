<?php
declare(strict_types=1);

/**
 * targets API の入力検証・CRUD 回帰テスト。
 * 特に「フォームが空欄項目も空文字で送る」ケース(2026-07-19 バグ報告: 会社変更時に
 * 'title が不正です' になる)を回帰として固定する。
 */

require_once __DIR__ . '/helpers.php';
tet2_test_boot();
load_api('targets');

$tenantId = current_user()['tenant_id'];

// テスト対象の既存 target を1件用意(なければ作る)。
$existing = Db::one('SELECT * FROM targets WHERE tenant_id = ? ORDER BY id LIMIT 1', [$tenantId]);
if ($existing === null) {
    Db::run('INSERT INTO targets (tenant_id, email, name, company, department, title, position_category) VALUES (?,?,?,?,?,?,?)',
        [$tenantId, 'unittest@example.com', '氏名', '元会社', '営業部', '課長', '管理職']);
    $existing = Db::one('SELECT * FROM targets WHERE tenant_id = ? AND email = ?', [$tenantId, 'unittest@example.com']);
}
$id = (int) $existing['id'];
$origTitle = $existing['title'];

// --- バグ回帰: 会社だけ変更し、他の任意項目は空文字で送る ---
$r = call_handler('targets_handle_update', [
    'id' => $id, 'name' => (string) $existing['name'], 'company' => '回帰テスト会社',
    'department' => '', 'title' => '', 'position_category' => '',
]);
check($r['code'] === 200, '会社変更+他項目空文字 → 200(空文字でエラーにならない)');
$after = Db::one('SELECT company, title FROM targets WHERE id = ?', [$id]);
check($after['company'] === '回帰テスト会社', '会社が更新される');
check($after['title'] === $origTitle, 'title は空文字送信でも現状維持(上書きされない)');

// --- 通常更新: 全項目に値 ---
$r = call_handler('targets_handle_update', [
    'id' => $id, 'name' => '新氏名', 'company' => 'C2', 'department' => 'D2',
    'title' => '部長', 'position_category' => '役員',
]);
check($r['code'] === 200, '全項目に値 → 200');
$after = Db::one('SELECT name, title, position_category FROM targets WHERE id = ?', [$id]);
check($after['name'] === '新氏名' && $after['title'] === '部長' && $after['position_category'] === '役員', '全項目が更新される');

// --- 旧称「社員」は「一般従業員」へ正規化される(2026-08-10 カテゴリ改称の後方互換) ---
$r = call_handler('targets_handle_update', ['id' => $id, 'position_category' => '社員']);
check($r['code'] === 200, '旧称「社員」で更新 → 200(エイリアスで受け入れる)');
check(Db::one('SELECT position_category FROM targets WHERE id = ?', [$id])['position_category'] === '一般従業員',
    '「社員」は「一般従業員」として保存される');

// --- 不正な position_category は拒否 ---
$r = call_handler('targets_handle_update', ['id' => $id, 'company' => 'C3', 'position_category' => '社長']);
check($r['code'] === 400, '不正な役職カテゴリ → 400');
check(str_contains($r['payload']['error'] ?? '', '役職カテゴリ'), 'エラーメッセージが役職カテゴリを指す');

// --- position_category を空にクリアできる ---
$r = call_handler('targets_handle_update', ['id' => $id, 'company' => 'C4', 'position_category' => '']);
check($r['code'] === 200, '役職カテゴリを空(—)にして保存 → 200');

// --- 更新項目が皆無なら 400 ---
$r = call_handler('targets_handle_update', ['id' => $id]);
check($r['code'] === 400, '更新項目なし → 400');

// --- IDOR: 他テナントの target は 404 ---
$other = Db::one('SELECT id FROM targets WHERE tenant_id != ? LIMIT 1', [$tenantId]);
if ($other !== null) {
    $r = call_handler('targets_handle_update', ['id' => (int) $other['id'], 'company' => 'X']);
    check($r['code'] === 404, '他テナントの target 更新 → 404(IDOR防御)');
} else {
    echo "SKIP: 他テナント target が存在しない\n";
}

// --- CSV export/import 往復(2026-07-19 新機能) ---
// 出力副作用のない targets_build_csv を検証(exit/header を伴わない)。
$csvBody = targets_build_csv($tenantId);
$exportLines = array_values(array_filter(explode("\n", str_replace("\r", '', $csvBody)), fn($l) => $l !== ''));
check($exportLines[0] === 'メールアドレス,氏名,会社名,部署,役職,役職カテゴリ', 'export ヘッダーが import と対称(日本語)');
// 既定はアーカイブ除外なので、active/suspended の数 + ヘッダー1行
$targetCount = (int) Db::one("SELECT COUNT(*) c FROM targets WHERE tenant_id = ? AND status != 'archived'", [$tenantId])['c'];
check(count($exportLines) === $targetCount + 1, "export 行数 = 非アーカイブ対象者数({$targetCount}) + ヘッダー");
// 他テナントの target が漏れていない(先に他テナントのメールを取得して非存在を確認)
if ($other !== null) {
    $otherEmail = Db::one('SELECT email FROM targets WHERE id = ?', [(int) $other['id']])['email'];
    check(!str_contains($csvBody, (string) $otherEmail), 'export に他テナントの対象者が混ざらない(分離)');
}

// export した CSV をそのまま import → updated(既存と一致するので全件 updated) になる
$r = call_handler('targets_handle_import_csv', ['csv' => $csvBody], 'operator');
check($r['code'] === 200, 'export した CSV を import → 200(往復可能)');
check($r['payload']['updated'] >= 1 && $r['payload']['imported'] === 0, 'export→import は全件 updated(新規追加なし=往復整合)');

// --- CSV にカテゴリ列が無くても役職マスタから補完する(2026-08-10) ---
Db::run("INSERT INTO position_masters (tenant_id, title, category) VALUES (?, '店長', '管理職')", [$tenantId]);
$csvNoCategory = "メールアドレス,氏名,役職\nmaster_fill@example.com,補完太郎,店長\n";
$r = call_handler('targets_handle_import_csv', ['csv' => $csvNoCategory], 'operator');
check($r['code'] === 200, '役職カテゴリ列なしの CSV 取込 → 200');
check(Db::one('SELECT position_category FROM targets WHERE tenant_id = ? AND email = ?',
    [$tenantId, 'master_fill@example.com'])['position_category'] === '管理職',
    'CSV にカテゴリ列が無くても役職マスタから補完される');
// マスタに無い役職は補完されない(NULL のまま。取込は止めない)
$csvUnknown = "メールアドレス,氏名,役職\nmaster_none@example.com,未登録次郎,未登録役職\n";
$r = call_handler('targets_handle_import_csv', ['csv' => $csvUnknown], 'operator');
check($r['code'] === 200, 'マスタ未登録の役職でも取込は成功する');
check(Db::one('SELECT position_category FROM targets WHERE tenant_id = ? AND email = ?',
    [$tenantId, 'master_none@example.com'])['position_category'] === null,
    'マスタ未登録の役職はカテゴリ NULL のまま(取込を止めない)');

// --- 対象者の論理削除(アーカイブ)。履歴保持し個人別統計に残す(2026-07-19 履歴管理要件) ---
// 訓練履歴のある対象者を削除 → 物理削除せず status='archived' に。FK違反も起きない。
Db::run("INSERT INTO campaigns (tenant_id, name, status) VALUES (?, 'ARCH_TEST', 'done')", [$tenantId]);
$archCamp = (int) Db::one('SELECT id FROM campaigns WHERE tenant_id = ? AND name = ?', [$tenantId, 'ARCH_TEST'])['id'];
Db::run('INSERT INTO targets (tenant_id, email, name) VALUES (?,?,?)', [$tenantId, 'arch_del@test', 'アーカイブ対象']);
$archId = (int) Db::one('SELECT id FROM targets WHERE tenant_id = ? AND email = ?', [$tenantId, 'arch_del@test'])['id'];
Db::run('INSERT INTO campaign_targets (campaign_id, target_id, tracking_id, koban, send_status) VALUES (?,?,?,?,?)',
    [$archCamp, $archId, 'ARCHTRK', 1, 'sent']);

$r = call_handler('targets_handle_delete', ['id' => $archId], 'operator');
check($r['code'] === 200 && ($r['payload']['archived'] ?? null) === true, '削除 → 200/archived=true(論理削除)');
check(Db::one('SELECT status FROM targets WHERE id = ?', [$archId])['status'] === 'archived', 'status が archived になる');
check((int) Db::one('SELECT COUNT(*) c FROM campaign_targets WHERE target_id = ?', [$archId])['c'] === 1, '訓練履歴(campaign_targets)は保持される');

// --- 削除日時の記録(2026-08-09 archived_at 追加) ---
$archivedAt = Db::one('SELECT archived_at FROM targets WHERE id = ?', [$archId])['archived_at'];
check($archivedAt !== null && $archivedAt !== '', '削除すると archived_at に日時が入る');
check((bool) preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $archivedAt), 'archived_at が datetime 形式');

// active に戻すと archived_at はクリアされる(復活した人に削除日が残らない)
$r = call_handler('targets_handle_update', ['id' => $archId, 'status' => 'active'], 'operator');
check($r['code'] === 200, 'アーカイブ対象者を active に戻す → 200');
check(Db::one('SELECT archived_at FROM targets WHERE id = ?', [$archId])['archived_at'] === null, 'active に戻すと archived_at が NULL に戻る');

// update 経由で archived にしても archived_at が入る(delete 経路以外も同期する)
$r = call_handler('targets_handle_update', ['id' => $archId, 'status' => 'archived'], 'operator');
check($r['code'] === 200, 'update で status=archived → 200');
$reArchivedAt = Db::one('SELECT archived_at FROM targets WHERE id = ?', [$archId])['archived_at'];
check($reArchivedAt !== null && $reArchivedAt !== '', 'update 経由の archived でも archived_at が入る');

// status 未指定の更新では archived_at が保持される(誤って消えない)
$r = call_handler('targets_handle_update', ['id' => $archId, 'company' => 'ARCH_KEEP'], 'operator');
check($r['code'] === 200, 'status 未指定の更新 → 200');
check(Db::one('SELECT archived_at FROM targets WHERE id = ?', [$archId])['archived_at'] === $reArchivedAt, 'status 未指定の更新では archived_at が変わらない');

// --- CSV export はアーカイブを既定で除外する(2026-08-09 バグ修正) ---
// 画面(list)に出ない人が CSV には出る、というズレの回帰固定。
$csvDefault = targets_build_csv($tenantId);
check(!str_contains($csvDefault, 'arch_del@test'), 'export(既定)にアーカイブ対象者が含まれない');
check(!str_contains($csvDefault, '削除日'), 'export(既定)は削除日列を持たない(import と往復可能なまま)');

$csvAll = targets_build_csv($tenantId, true);
check(str_contains($csvAll, 'arch_del@test'), 'include_archived=true でアーカイブ対象者も出力される');
$allLines = array_values(array_filter(explode("\n", str_replace("\r", '', $csvAll)), fn($l) => $l !== ''));
check($allLines[0] === 'メールアドレス,氏名,会社名,部署,役職,役職カテゴリ,状態,削除日,テストユーザ', 'include_archived=true のヘッダーに状態・削除日・テストユーザが付く');
$allCount = (int) Db::one('SELECT COUNT(*) c FROM targets WHERE tenant_id = ?', [$tenantId])['c'];
check(count($allLines) === $allCount + 1, "include_archived=true の行数 = 全対象者数({$allCount}) + ヘッダー");
// アーカイブ行に削除日が実際に入っている
$archLine = '';
foreach ($allLines as $line) {
    if (str_contains($line, 'arch_del@test')) { $archLine = $line; }
}
check(str_contains($archLine, 'archived'), 'アーカイブ行の状態列が archived');
check((bool) preg_match('/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', $archLine), 'アーカイブ行に削除日が出力される');

// 既定 export は他テナント同様アーカイブも漏らさない(分離と同じ強度で確認)
$r = call_handler('targets_handle_delete', ['id' => $archId], 'operator');
check($r['code'] === 200, '再アーカイブ → 200(後続テストの前提を戻す)');

// --- restore: 削除済みを在籍に戻す(2026-08-09 誤削除の復旧) ---
$r = call_handler('targets_handle_restore', ['id' => $archId], 'operator');
check($r['code'] === 200 && ($r['payload']['restored'] ?? null) === true, 'restore → 200/restored=true');
$restored = Db::one('SELECT status, archived_at FROM targets WHERE id = ?', [$archId]);
check($restored['status'] === 'active', 'restore で status が active に戻る');
check($restored['archived_at'] === null, 'restore で archived_at が NULL に戻る');
check((int) Db::one('SELECT COUNT(*) c FROM campaign_targets WHERE target_id = ?', [$archId])['c'] === 1, 'restore しても訓練履歴は保持される');

// active な対象者を restore しようとしたら 400(状態不整合を弾く)
$r = call_handler('targets_handle_restore', ['id' => $archId], 'operator');
check($r['code'] === 400, '削除済みでない対象者の restore → 400');

// 他テナントの対象者は restore できない(IDOR)
if ($other !== null) {
    $r = call_handler('targets_handle_restore', ['id' => (int) $other['id']], 'operator');
    check($r['code'] === 404, '他テナントの対象者 restore → 404(IDOR防御)');
}

// list が archived_at を返す(画面の削除日列の前提)
$_GET = ['include_archived' => '1'];
$r = call_handler('targets_handle_list', [], 'viewer');
$sample = $r['payload']['targets'][0] ?? [];
check(array_key_exists('created_at', $sample), 'list が created_at(登録日)を返す');
check(array_key_exists('archived_at', $sample), 'list が archived_at(削除日)を返す');
$_GET = [];

// 後続テストのために再度アーカイブしておく
$r = call_handler('targets_handle_delete', ['id' => $archId], 'operator');
check($r['code'] === 200, '再アーカイブ → 200(後続テストの前提を戻す)');

// --- テストユーザフラグ(2026-08-09 is_test) ---
// 既定は本番ユーザ(0)。
$r = call_handler('targets_handle_create', [
    'email' => 'plain_user@test.example.com', 'name' => '本番ユーザ',
], 'operator');
check($r['code'] === 201, 'is_test 未指定で作成 → 201');
$plainId = (int) $r['payload']['target']['id'];
check((int) Db::one('SELECT is_test FROM targets WHERE id = ?', [$plainId])['is_test'] === 0, 'is_test 未指定なら 0(本番ユーザ)');

// 作成時に true を渡すと 1 になる。
$r = call_handler('targets_handle_create', [
    'email' => 'test_user@test.example.com', 'name' => 'テストユーザ', 'is_test' => true,
], 'operator');
check($r['code'] === 201, 'is_test=true で作成 → 201');
$testId = (int) $r['payload']['target']['id'];
check((int) Db::one('SELECT is_test FROM targets WHERE id = ?', [$testId])['is_test'] === 1, 'is_test=true で 1 が入る');

// update で切り替えられる(0/1 と bool の両方を受ける)。
$r = call_handler('targets_handle_update', ['id' => $plainId, 'is_test' => 1], 'operator');
check($r['code'] === 200, 'update で is_test=1 → 200');
check((int) Db::one('SELECT is_test FROM targets WHERE id = ?', [$plainId])['is_test'] === 1, 'update で is_test が 1 になる');
$r = call_handler('targets_handle_update', ['id' => $plainId, 'is_test' => false], 'operator');
check((int) Db::one('SELECT is_test FROM targets WHERE id = ?', [$plainId])['is_test'] === 0, 'update で is_test=false → 0 に戻る');

// is_test だけの更新も「更新項目なし」にならない。
$r = call_handler('targets_handle_update', ['id' => $plainId, 'is_test' => 1], 'operator');
check($r['code'] === 200, 'is_test だけの更新でも 400 にならない');
// 未指定の更新では is_test が変わらない(現状維持)。
$r = call_handler('targets_handle_update', ['id' => $plainId, 'company' => 'KEEP_TEST'], 'operator');
check((int) Db::one('SELECT is_test FROM targets WHERE id = ?', [$plainId])['is_test'] === 1, 'is_test 未指定の更新では値が変わらない');
// 不正値は 400。
$r = call_handler('targets_handle_update', ['id' => $plainId, 'is_test' => 'yes'], 'operator');
check($r['code'] === 400, 'is_test に不正値 → 400');

// list が is_test を返す(画面の TEST バッジの前提)。
$_GET = [];
$r = call_handler('targets_handle_list', [], 'viewer');
$listSample = $r['payload']['targets'][0] ?? [];
check(array_key_exists('is_test', $listSample), 'list が is_test を返す');

// CSV(include_archived)にテスト区分列が出る。
$csvTest = targets_build_csv($tenantId, true);
$csvTestLines = array_values(array_filter(explode("\n", str_replace("\r", '', $csvTest)), fn($l) => $l !== ''));
check(str_contains($csvTestLines[0], 'テストユーザ'), 'include_archived=true のヘッダーにテストユーザ列が付く');
$testLine = '';
foreach ($csvTestLines as $line) { if (str_contains($line, 'test_user@test.example.com')) { $testLine = $line; } }
check(str_ends_with(trim($testLine), 'テスト'), 'テストユーザ行の末尾にテスト表記が出る');

// list(既定)からはアーカイブが除外される
$_GET = [];
$r = call_handler('targets_handle_list', [], 'viewer');
$ids = array_column($r['payload']['targets'], 'id');
check(!in_array($archId, $ids, true), 'アーカイブ対象者は一覧(既定)から除外される');

// include_archived=1 なら出る
$_GET = ['include_archived' => '1'];
$r = call_handler('targets_handle_list', [], 'viewer');
$ids = array_column($r['payload']['targets'], 'id');
check(in_array($archId, $ids, true), 'include_archived=1 でアーカイブ対象者も一覧に出る');
$_GET = [];

// 他テナントの対象者削除 → 404(IDOR)
if ($other !== null) {
    $r = call_handler('targets_handle_delete', ['id' => (int) $other['id']], 'operator');
    check($r['code'] === 404, '他テナントの対象者削除 → 404(IDOR)');
}

echo "ALL TESTS PASSED\n";
