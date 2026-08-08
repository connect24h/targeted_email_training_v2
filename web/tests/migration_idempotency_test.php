<?php
declare(strict_types=1);

require_once __DIR__ . '/fixtures/TestDatabase.php';
require_once __DIR__ . '/../db/MigrationRunner.php';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException("FAIL: {$message}");
    }
    echo "PASS: {$message}\n";
}

function downgradeConstraints(PDO $pdo): void
{
    $pdo->exec('PRAGMA foreign_keys = OFF');
    $pdo->exec('CREATE TABLE campaign_targets_legacy (
        id INTEGER PRIMARY KEY AUTOINCREMENT, campaign_id INTEGER NOT NULL,
        target_id INTEGER NOT NULL, tracking_id TEXT NOT NULL UNIQUE, koban INTEGER,
        auth_flag INTEGER, from_address TEXT, attachment_path TEXT,
        send_status TEXT NOT NULL DEFAULT \'pending\', sent_at TEXT,
        UNIQUE (campaign_id, target_id))');
    $pdo->exec('INSERT INTO campaign_targets_legacy
        SELECT id, campaign_id, target_id, tracking_id, koban, auth_flag, from_address,
               attachment_path, send_status, sent_at FROM campaign_targets');
    $pdo->exec('DROP TABLE campaign_targets');
    $pdo->exec('ALTER TABLE campaign_targets_legacy RENAME TO campaign_targets');

    $pdo->exec('CREATE TABLE edu_categories_legacy (
        id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, name TEXT NOT NULL,
        slug TEXT NOT NULL, color TEXT, sort_order INTEGER NOT NULL DEFAULT 0,
        is_active INTEGER NOT NULL DEFAULT 1,
        created_at TEXT NOT NULL DEFAULT (datetime(\'now\',\'localtime\')),
        UNIQUE (tenant_id, slug))');
    $pdo->exec('CREATE TABLE edu_questions_legacy (
        id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL,
        category_id INTEGER NOT NULL, title TEXT NOT NULL,
        question_type TEXT NOT NULL DEFAULT \'single_choice\', options TEXT NOT NULL,
        correct_answer TEXT NOT NULL, explanation TEXT, difficulty INTEGER NOT NULL DEFAULT 1,
        is_active INTEGER NOT NULL DEFAULT 1,
        created_at TEXT NOT NULL DEFAULT (datetime(\'now\',\'localtime\')))');
    $pdo->exec('DROP TABLE edu_questions');
    $pdo->exec('DROP TABLE edu_categories');
    $pdo->exec('ALTER TABLE edu_categories_legacy RENAME TO edu_categories');
    $pdo->exec('ALTER TABLE edu_questions_legacy RENAME TO edu_questions');
    $pdo->exec('PRAGMA foreign_keys = ON');
}

$dbPath = sys_get_temp_dir() . '/tet2-migration-' . getmypid() . '.sqlite';
@unlink($dbPath);
register_shutdown_function(static fn() => @unlink($dbPath));
TestDatabase::create($dbPath);

$pdo = new PDO('sqlite:' . $dbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$before = (int) $pdo->query('SELECT COUNT(*) FROM targets')->fetchColumn();

$runner = new MigrationRunner($dbPath);
check(count($runner->pending()) === 1, '未適用migrationが1件ある');
check($runner->migrate() === 1, '初回はmigrationを1件適用する');
check($runner->pending() === [], '適用後にpendingがない');
check($runner->migrate() === 0, '2回目はno-opになる');

$after = (int) $pdo->query('SELECT COUNT(*) FROM targets')->fetchColumn();
check($after === $before, 'migrationで業務data件数が変わらない');
check((int) $pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn() === 1,
    'schema_migrationsへ1件だけ記録される');
check($pdo->query('PRAGMA foreign_key_check')->fetchAll() === [], 'foreign key違反がない');

$legacyPath = sys_get_temp_dir() . '/tet2-migration-legacy-' . getmypid() . '.sqlite';
@unlink($legacyPath);
register_shutdown_function(static fn() => @unlink($legacyPath));
TestDatabase::create($legacyPath);
$legacyPdo = new PDO('sqlite:' . $legacyPath, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
downgradeConstraints($legacyPdo);

$legacyRunner = new MigrationRunner($legacyPath);
check($legacyRunner->migrate() === 1, '旧constraint DBを現行schemaへ移行する');
$legacyPdo->exec("INSERT INTO campaign_targets
    (campaign_id, target_id, tracking_id, content_no) VALUES (2, 1, '0000000011', 1)");
$legacyPdo->exec("INSERT INTO campaign_targets
    (campaign_id, target_id, tracking_id, content_no) VALUES (2, 1, '0000000012', 2)");
check((int) $legacyPdo->query(
    'SELECT COUNT(*) FROM campaign_targets WHERE campaign_id=2 AND target_id=1'
)->fetchColumn() === 3, '同一targetへ複数contentを割り当てられる');
$legacyPdo->exec("INSERT INTO edu_categories
    (tenant_id, name, slug, is_shared) VALUES (NULL, 'Shared', 'shared', 1)");
check((int) $legacyPdo->query(
    'SELECT COUNT(*) FROM edu_categories WHERE tenant_id IS NULL AND is_shared=1'
)->fetchColumn() === 1, '共有教材のnullable tenant constraintへ移行する');
check($legacyPdo->query('PRAGMA foreign_key_check')->fetchAll() === [],
    '旧constraint移行後もforeign key違反がない');

$invalidPath = sys_get_temp_dir() . '/tet2-migration-invalid-' . getmypid() . '.sqlite';
@unlink($invalidPath);
register_shutdown_function(static fn() => @unlink($invalidPath));
TestDatabase::create($invalidPath, false);
$invalidPdo = new PDO('sqlite:' . $invalidPath, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$invalidPdo->exec('PRAGMA foreign_keys = OFF');
$invalidPdo->exec("INSERT INTO campaigns (tenant_id, name) VALUES (999, 'Broken FK')");
$failedClosed = false;
try {
    (new MigrationRunner($invalidPath))->migrate();
} catch (RuntimeException) {
    $failedClosed = true;
}
check($failedClosed, 'foreign key違反があるDBへのmigrationは失敗する');
check((int) $invalidPdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn() === 0,
    'foreign key違反時はversion記録もrollbackする');

echo "ALL TESTS PASSED\n";
