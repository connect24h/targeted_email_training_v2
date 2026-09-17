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
$pdo->exec("INSERT INTO groups (tenant_id, name, kind) VALUES (1, '全職員', 'custom')");
$before = (int) $pdo->query('SELECT COUNT(*) FROM targets')->fetchColumn();

$runner = new MigrationRunner($dbPath);
check(count($runner->pending()) === 14, '未適用migrationが14件ある');
check($runner->migrate() === 14, '初回はmigrationを14件適用する');
check($runner->pending() === [], '適用後にpendingがない');
check($runner->migrate() === 0, '2回目はno-opになる');

// 全旧版が適用済みでも、新版だけで報告メール用tableが追加される。
$pdo->exec('DROP TABLE report_mail_matches');
$pdo->exec('DROP TABLE report_mails');
$pdo->exec("DELETE FROM schema_migrations WHERE version = '20260906-report-mail-ingest'");
check($runner->pending() === ['20260906-report-mail-ingest'], '報告メール新版だけがpending');
check($runner->migrate() === 1, '報告メール新版を単独適用できる');
check($pdo->query('SELECT COUNT(*) FROM report_mails')->fetchColumn() === 0,
    '既存DBに報告メールtableを新規作成する');
check($runner->migrate() === 0, '報告メール新版も冪等');

$after = (int) $pdo->query('SELECT COUNT(*) FROM targets')->fetchColumn();
check($after === $before, 'migrationで業務data件数が変わらない');
check((int) $pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn() === 14,
    'schema_migrationsへ14件だけ記録される');
check(in_array('suppress_prefill_email', array_column(
    $pdo->query('PRAGMA table_info(campaign_contents)')->fetchAll(),
    'name'
), true), 'campaign_contentsへsuppress_prefill_emailを追加する');
check(in_array('attachment_filename', array_column(
    $pdo->query('PRAGMA table_info(campaign_contents)')->fetchAll(),
    'name'
), true), 'campaign_contentsへattachment_filenameを追加する');
check(in_array('attachment_filename', array_column(
    $pdo->query('PRAGMA table_info(campaigns)')->fetchAll(),
    'name'
), true), 'campaignsへattachment_filenameを追加する');
check((int) $pdo->query("SELECT COUNT(*) FROM edu_materials WHERE is_shared = 1")->fetchColumn() >= 1,
    '標的型メール訓練失敗者向けの共有スライド教材を追加する');
check((string) $pdo->query("SELECT kind FROM groups WHERE tenant_id=1 AND name='全職員'")->fetchColumn() === 'all',
    '既存の全職員groupを動的all kindへ移行する');
check((int) $pdo->query(
    "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='integration_idempotency_keys'"
)->fetchColumn() === 1, 'integration idempotency tableを追加する');
check((int) $pdo->query(
    "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='position_masters'"
)->fetchColumn() === 1, 'position masters tableを追加する');
check((int) $pdo->query(
    "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name LIKE 'campaign_automation%'"
)->fetchColumn() === 3, 'automation tableを3件追加する');
check(in_array('assignment_mode', array_column(
    $pdo->query('PRAGMA table_info(campaign_automations)')->fetchAll(),
    'name'
), true), 'automationへassignment_modeを追加する');
check(in_array('max_occurrences', array_column(
    $pdo->query('PRAGMA table_info(campaign_automations)')->fetchAll(),
    'name'
), true), 'automationへmax_occurrencesを追加する');
check($pdo->query('PRAGMA foreign_key_check')->fetchAll() === [], 'foreign key違反がない');

$currentPath = sys_get_temp_dir() . '/tet2-migration-current-' . getmypid() . '.sqlite';
@unlink($currentPath);
register_shutdown_function(static fn() => @unlink($currentPath));
TestDatabase::create($currentPath, false);
$currentPdo = new PDO('sqlite:' . $currentPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$currentPdo->exec('DROP TABLE campaign_automation_runs');
$currentPdo->exec('DROP TABLE campaign_automation_groups');
$currentPdo->exec('DROP TABLE campaign_automations');
$currentPdo->exec("INSERT INTO schema_migrations (version) VALUES ('20260808-current-schema')");
$currentRunner = new MigrationRunner($currentPath);
check($currentRunner->pending() === [
    '20260809-campaign-automations',
    '20260809-campaign-rotation',
    '20260809-awareness-participant-integration',
    '20260809-targets-archived-at',
    '20260809-targets-is-test',
    '20260810-position-masters',
    '20260810-all-members-group',
    '20260810-elearning-materials',
    '20260812-suppress-prefill-email',
    '20260816-human-risk-score',
    '20260819-attachment-filename-prefix',
    '20260906-report-mail-ingest',
    '20260917-suspicious-mail',
], '現行DBは13件の後続migrationがpending');
check($currentRunner->migrate() === 13, '現行DBへ残りのmigrationを適用する');
check((int) $currentPdo->query(
    "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name LIKE 'campaign_automation%'"
)->fetchColumn() === 3, '現行DBへautomation tableを追加する');
// current-schema 適用済みの既存DBにも独立migrationで追加列が届く。
$currentColumns = $currentPdo->query('PRAGMA table_info(targets)')->fetchAll(PDO::FETCH_COLUMN, 1);
check(in_array('archived_at', $currentColumns, true), '現行DBへtargets.archived_atが追加される');
check(in_array('is_test', $currentColumns, true), '現行DBへtargets.is_testが追加される');
check((int) $currentPdo->query(
    "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='position_masters'"
)->fetchColumn() === 1, '現行DBへposition_masters tableが追加される');
$currentContentCols = $currentPdo->query('PRAGMA table_info(campaign_contents)')->fetchAll(PDO::FETCH_COLUMN, 1);
check(in_array('suppress_prefill_email', $currentContentCols, true),
    '現行DBへcampaign_contents.suppress_prefill_emailが追加される');

$rotationPath = sys_get_temp_dir() . '/tet2-migration-rotation-' . getmypid() . '.sqlite';
@unlink($rotationPath);
register_shutdown_function(static fn() => @unlink($rotationPath));
TestDatabase::create($rotationPath, false);
$rotationPdo = new PDO('sqlite:' . $rotationPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$rotationPdo->exec('PRAGMA foreign_keys = OFF');
$rotationPdo->exec('DROP TABLE campaign_automation_runs');
$rotationPdo->exec('DROP TABLE campaign_automation_groups');
$rotationPdo->exec('DROP TABLE campaign_automations');
$rotationPdo->exec('CREATE TABLE campaign_automations (id INTEGER PRIMARY KEY AUTOINCREMENT)');
$rotationPdo->exec("INSERT INTO schema_migrations (version) VALUES ('20260808-current-schema')");
$rotationPdo->exec("INSERT INTO schema_migrations (version) VALUES ('20260809-campaign-automations')");
$rotationRunner = new MigrationRunner($rotationPath);
check($rotationRunner->migrate() === 12, '既存automation DBへ12件の後続migrationを適用する');
$rotationPdo->exec('INSERT INTO campaign_automations DEFAULT VALUES');
$assignmentConstraint = false;
try {
    $rotationPdo->exec("UPDATE campaign_automations SET assignment_mode='random'");
} catch (PDOException) {
    $assignmentConstraint = true;
}
check($assignmentConstraint, 'upgrade DBでもassignment mode constraintを追加する');
$occurrenceConstraint = false;
try {
    $rotationPdo->exec('UPDATE campaign_automations SET max_occurrences=0');
} catch (PDOException) {
    $occurrenceConstraint = true;
}
check($occurrenceConstraint, 'upgrade DBでもmax occurrences constraintを追加する');

$unversionedPath = sys_get_temp_dir() . '/tet2-migration-unversioned-' . getmypid() . '.sqlite';
@unlink($unversionedPath);
register_shutdown_function(static fn() => @unlink($unversionedPath));
TestDatabase::create($unversionedPath, false);
$unversionedPdo = new PDO('sqlite:' . $unversionedPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$unversionedPdo->exec('DROP TABLE schema_migrations');
$unversionedRunner = new MigrationRunner($unversionedPath);
check($unversionedRunner->migrate() === 14, 'version tableなしDBへ全migrationを適用する');
check((int) $unversionedPdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn() === 14,
    'version tableを作成して14件記録する');

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
check($legacyRunner->migrate() === 14, '旧constraint DBへ全migrationを適用する');
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
