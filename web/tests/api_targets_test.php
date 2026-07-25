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
// 自テナントの target 数 + ヘッダー1行
$targetCount = (int) Db::one('SELECT COUNT(*) c FROM targets WHERE tenant_id = ?', [$tenantId])['c'];
check(count($exportLines) === $targetCount + 1, "export 行数 = 対象者数({$targetCount}) + ヘッダー");
// 他テナントの target が漏れていない(先に他テナントのメールを取得して非存在を確認)
if ($other !== null) {
    $otherEmail = Db::one('SELECT email FROM targets WHERE id = ?', [(int) $other['id']])['email'];
    check(!str_contains($csvBody, (string) $otherEmail), 'export に他テナントの対象者が混ざらない(分離)');
}

// export した CSV をそのまま import → updated(既存と一致するので全件 updated) になる
$r = call_handler('targets_handle_import_csv', ['csv' => $csvBody], 'operator');
check($r['code'] === 200, 'export した CSV を import → 200(往復可能)');
check($r['payload']['updated'] >= 1 && $r['payload']['imported'] === 0, 'export→import は全件 updated(新規追加なし=往復整合)');

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
