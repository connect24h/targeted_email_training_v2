<?php
declare(strict_types=1);

// 教材のページ画像、設問の画像と選択肢ごとの解説、答えた直後の答え合わせ（08 設計書の G16〜G18）のスキーマ。
require_once __DIR__ . '/fixtures/TestDatabase.php';
require_once __DIR__ . '/../db/MigrationRunner.php';

function ems_check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException("FAIL: {$message}");
    }
    echo "PASS: {$message}\n";
}

function ems_columns(PDO $pdo, string $table): array
{
    return array_column($pdo->query("PRAGMA table_info({$table})")->fetchAll(PDO::FETCH_ASSOC), 'name');
}

function ems_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name = ?");
    $stmt->execute([$table]);
    return $stmt->fetchColumn() !== false;
}

// --- 新しく作る DB（テスト用の合成 DB と同じ組み立て） ---
$fresh = sys_get_temp_dir() . '/tet2-ems-fresh-' . getmypid() . '.sqlite';
@unlink($fresh);
register_shutdown_function(static fn() => @unlink($fresh));
TestDatabase::create($fresh);
$pdo = new PDO('sqlite:' . $fresh, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$materialCols = ems_columns($pdo, 'edu_materials');
ems_check(in_array('format', $materialCols, true) && in_array('page_count', $materialCols, true)
    && in_array('source_name', $materialCols, true), 'EM-1: 教材に形式、ページ数、元のファイル名がある');
$questionCols = ems_columns($pdo, 'edu_questions');
ems_check(in_array('image_name', $questionCols, true) && in_array('option_explanations', $questionCols, true),
    'EM-2: 設問に画像と選択肢ごとの解説がある');
ems_check(in_array('feedback_mode', ems_columns($pdo, 'edu_deliveries'), true), 'EM-3: 配信に答え合わせの時機がある');
ems_check(ems_table_exists($pdo, 'edu_material_pages'), 'EM-4: 教材のページのテーブルがある');
ems_check(ems_table_exists($pdo, 'edu_answer_locks'), 'EM-5: 1問ずつの解答を固定するテーブルがある');

// 既定値: 既存の教材と配信の意味を変えない
$pdo->exec("INSERT INTO edu_materials (tenant_id, title, slides) VALUES (1, '既存の教材', '[]')");
$m = $pdo->query('SELECT format, page_count FROM edu_materials ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
ems_check($m['format'] === 'text_slides' && (int) $m['page_count'] === 0, 'EM-6: 既存の教材は文字のスライドのまま');
$pdo->exec("INSERT INTO edu_deliveries (tenant_id, title) VALUES (1, '既存の配信')");
$d = $pdo->query('SELECT feedback_mode FROM edu_deliveries ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
ems_check($d['feedback_mode'] === 'after_submit', 'EM-7: 既存の配信は提出後の答え合わせのまま');

// 同じ教材で同じページ番号を2回入れられない
$materialId = (int) $pdo->query('SELECT id FROM edu_materials ORDER BY id DESC LIMIT 1')->fetchColumn();
$pdo->exec("INSERT INTO edu_material_pages (material_id, page_no, image_name) VALUES ({$materialId}, 1, 'page-001.jpg')");
$dup = false;
try {
    $pdo->exec("INSERT INTO edu_material_pages (material_id, page_no, image_name) VALUES ({$materialId}, 1, 'x.jpg')");
} catch (PDOException) {
    $dup = true;
}
ems_check($dup, 'EM-8: 同じ教材の同じページ番号は重複できない');

// --- 本番と同じく、既存 DB に migration で追加できる ---
$old = sys_get_temp_dir() . '/tet2-ems-old-' . getmypid() . '.sqlite';
@unlink($old);
register_shutdown_function(static fn() => @unlink($old));
TestDatabase::create($old);
$opdo = new PDO('sqlite:' . $old, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
// 新しい列とテーブルを外して、この版より前の DB を再現する
$opdo->exec('DROP TABLE edu_material_pages');
$opdo->exec('DROP TABLE edu_answer_locks');
foreach (['edu_materials' => ['format', 'page_count', 'source_name'], 'edu_questions' => ['image_name', 'option_explanations'],
    'edu_deliveries' => ['feedback_mode']] as $table => $cols) {
    foreach ($cols as $col) {
        $opdo->exec("ALTER TABLE {$table} DROP COLUMN {$col}");
    }
}
$opdo->exec("INSERT INTO groups (tenant_id, name, kind) VALUES (1, '全職員', 'custom')");
$opdo = null;
$runner = new MigrationRunner($old);
ems_check(in_array('20260927-edu-rich-content', $runner->pending(), true), 'EM-9: 新しい版が未適用として見える');
$runner->migrate();
$opdo = new PDO('sqlite:' . $old, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
ems_check(in_array('format', ems_columns($opdo, 'edu_materials'), true)
    && in_array('option_explanations', ems_columns($opdo, 'edu_questions'), true)
    && in_array('feedback_mode', ems_columns($opdo, 'edu_deliveries'), true),
    'EM-10: migration で既存 DB に列を追加する');
ems_check(ems_table_exists($opdo, 'edu_material_pages') && ems_table_exists($opdo, 'edu_answer_locks'),
    'EM-11: migration で既存 DB にテーブルを追加する');
ems_check($runner->migrate() === 0, 'EM-12: 2回目の migration は何もしない');

echo "ALL TESTS PASSED\n";
