<?php
declare(strict_types=1);

/**
 * 教材の版(G20)の回帰テスト。
 * - 作成で版1、内容(スライド)の差し替えで版が上がり履歴が残る(題名だけの修正では上がらない)
 * - 受講の回(edu_attempts)に、その時点の教材の版を記録する
 * - 教育レポートの受講者ごとの行に版が出る
 * - 受講者のマイページ(LearnerPortal::grades)に版が出る
 */

require_once __DIR__ . '/helpers.php';
tet2_test_boot();
require_once __DIR__ . '/../lib/Db.php';
require_once __DIR__ . '/../lib/EduAttempts.php';
require_once __DIR__ . '/../lib/LearnerPortal.php';
load_api('edu_materials');
load_api('edu_report');

$actor = ['id' => 1, 'tenant_id' => 1, 'role' => 'operator', 'email' => 'op@t1'];

// --- 作成で版1、履歴が1行 ---
$GLOBALS['__TET2_TEST_BODY'] = ['tenant_id' => 1, 'title' => 'M1', 'slides' => [['title' => 'a', 'body' => 'b']]];
$GLOBALS['__TET2_TEST_ROLE'] = 'operator';
$create = null;
try { edu_m_handle_create($actor); } catch (Tet2TestExit $e) { $create = $e->payload; }
$mid = (int) $create['material']['id'];
check($create['material']['version'] === 1, 'create: 版1で作られる');
check((int) Db::one('SELECT COUNT(*) n FROM edu_material_versions WHERE material_id=?', [$mid])['n'] === 1, 'create: 版1の履歴が1行');

// --- 題名だけの修正では版は上がらない ---
$GLOBALS['__TET2_TEST_BODY'] = ['tenant_id' => 1, 'id' => $mid, 'title' => 'M1改題'];
try { edu_m_handle_update($actor); } catch (Tet2TestExit) {}
check((int) Db::one('SELECT version FROM edu_materials WHERE id=?', [$mid])['version'] === 1, 'update(題名のみ): 版は上がらない');

// --- 内容(スライド)の差し替えで版が2へ、履歴が2行 ---
$GLOBALS['__TET2_TEST_BODY'] = ['tenant_id' => 1, 'id' => $mid, 'slides' => [['title' => 'c', 'body' => 'd']]];
try { edu_m_handle_update($actor); } catch (Tet2TestExit) {}
check((int) Db::one('SELECT version FROM edu_materials WHERE id=?', [$mid])['version'] === 2, 'update(内容差替): 版が2へ上がる');
$hist = Db::all('SELECT version, replaced_by FROM edu_material_versions WHERE material_id=? ORDER BY version', [$mid]);
check(count($hist) === 2 && (int) $hist[1]['version'] === 2 && $hist[1]['replaced_by'] === 'op@t1', 'update(内容差替): 版2の履歴が残る(差し替えた人つき)');

// --- 受講の回に版を記録する。配信→割当→回を作り、版2で受講、その後版3にして再受講。 ---
$did = Db::insert("INSERT INTO edu_deliveries (tenant_id, title, status, material_id, delivery_type, pass_score) VALUES (1, 'D', 'running', ?, 'elearning', 60)", [$mid]);
$aid = Db::insert("INSERT INTO edu_assignments (tenant_id, delivery_id, target_id, access_token, status) VALUES (1, ?, 1, 'tok1', 'assigned')", [$did]);
$assignment = Db::one('SELECT * FROM edu_assignments WHERE id=?', [$aid]);
$open = EduAttempts::ensureOpen($assignment);
check((int) $open['material_version'] === 2, 'attempt: 受講の回に、その時点の版(2)を記録する');

// 版を3へ上げてから、次の回を開く(前の回は版2のまま)。
Db::run('UPDATE edu_materials SET version = 3 WHERE id=?', [$mid]);
Db::run("UPDATE edu_attempts SET completed_at = datetime('now','localtime'), percentage = 50, passed = 0 WHERE id=?", [$open['id']]);
Db::run("UPDATE edu_assignments SET status = 'completed' WHERE id=?", [$aid]);
$assignment = Db::one('SELECT * FROM edu_assignments WHERE id=?', [$aid]);
$open2 = EduAttempts::ensureOpen($assignment, true);
check((int) $open2['material_version'] === 3, 'attempt: 差し替え後の回は新しい版(3)を記録する');
$v2 = Db::one('SELECT material_version FROM edu_attempts WHERE id=?', [$open['id']]);
check((int) $v2['material_version'] === 2, 'attempt: 前の回の版(2)は変わらない');

// --- 教育レポートの受講者ごとの行に版が出る(最新の提出の回の版) ---
Db::run("UPDATE edu_attempts SET completed_at = datetime('now','localtime'), percentage = 80, passed = 1 WHERE id=?", [$open2['id']]);
Db::run("UPDATE edu_assignments SET status = 'completed' WHERE id=?", [$aid]);
Db::run('INSERT INTO edu_responses (tenant_id, assignment_id, percentage) VALUES (1, ?, 80)', [$aid]);
$GLOBALS['__TET2_TEST_ROLE'] = 'operator';
$_GET = ['id' => $did];
$people = null;
try { edu_rep_handle_delivery_people($actor); } catch (Tet2TestExit $e) { $people = $e->payload; }
$rowP = $people['people'][0];
check((int) $rowP['material_version'] === 3, 'report: 受講者ごとの行に最新の提出の版(3)が出る');

// --- 受講者のマイページに版が出る ---
$grades = LearnerPortal::grades(1, 1);
$g = null;
foreach ($grades['deliveries'] as $d) { if ((int) $d['delivery_id'] === $did) { $g = $d; } }
check($g !== null && (int) $g['material_version'] === 3, 'portal: マイページの成績に、受けた版(3)が出る');

echo "ALL TESTS PASSED\n";
