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
check(count($runner->pending()) === 22, '未適用migrationが22件ある');
check($runner->migrate() === 22, '初回はmigrationを22件適用する');
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

$pdo->exec('DROP TABLE campaign_template_snapshots');
$pdo->exec("DELETE FROM schema_migrations WHERE version = '20260923-template-snapshots'");
check($runner->pending() === ['20260923-template-snapshots'], 'シナリオ版固定のmigrationだけがpending');
check($runner->migrate() === 1, 'シナリオ版固定を既存DBへ単独適用できる');
check((int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='campaign_template_snapshots'")->fetchColumn() === 1,
    '既存DBへ配信版記録tableを作成する');

$pdo->exec('DROP TABLE credential_captures');
$pdo->exec('ALTER TABLE campaigns DROP COLUMN credential_capture_approval_ref');
$pdo->exec("DELETE FROM schema_migrations WHERE version = '20260923-credential-captures'");
check($runner->pending() === ['20260923-credential-captures'], '本文保管のmigrationだけがpending');
check($runner->migrate() === 1, '既存DBへ本文保管の列とtableを追加できる');
check(in_array('credential_capture_approval_ref', array_column(
    $pdo->query('PRAGMA table_info(campaigns)')->fetchAll(), 'name'
), true), '旧campaign tableへ承認参照列を追加する');

$pdo->exec('ALTER TABLE campaigns DROP COLUMN closed_at');
$pdo->exec('ALTER TABLE campaigns DROP COLUMN closed_by');
$pdo->exec("DELETE FROM schema_migrations WHERE version = '20260925-surveys'");
check($runner->pending() === ['20260925-surveys'], 'アンケートのmigrationだけがpending');
check($runner->migrate() === 1, 'アンケートのmigrationを再適用しても既存tableと衝突しない');
check((int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name LIKE 'survey%'")->fetchColumn() === 6,
    'アンケートのtableを6件作成する');

$pdo->exec("DELETE FROM schema_migrations WHERE version = '20260923-campaign-close'");
check($runner->pending() === ['20260923-campaign-close'], 'クローズのmigrationだけがpending');
check($runner->migrate() === 1, '既存DBへクローズ列を追加できる');
check(in_array('closed_at', array_column($pdo->query('PRAGMA table_info(campaigns)')->fetchAll(), 'name'), true),
    '既存campaign tableへクローズ日時列を追加する');

// 教育の配信の機能(予約の自動開始、毎月の配信、役職と訓練の結果での対象、新入社員)。
// 既存の配信の行を壊さず、列とテーブルを足すだけであることを確かめる。
$pdo->exec("INSERT INTO edu_deliveries (tenant_id, title, status) VALUES (1, '既存の配信', 'running')");
$pdo->exec('DROP TABLE edu_delivery_series');
$pdo->exec('ALTER TABLE edu_deliveries DROP COLUMN send_invites');
$pdo->exec('ALTER TABLE edu_deliveries DROP COLUMN new_target_days');
$pdo->exec("DELETE FROM schema_migrations WHERE version = '20261001-edu-delivery-features'");
check($runner->pending() === ['20261001-edu-delivery-features'], '教育の配信の機能のmigrationだけがpending');
check($runner->migrate() === 1, '既存DBへ教育の配信の機能の列とtableを追加できる');
$deliveryCols = array_column($pdo->query('PRAGMA table_info(edu_deliveries)')->fetchAll(), 'name');
check(in_array('send_invites', $deliveryCols, true) && in_array('new_target_days', $deliveryCols, true),
    '既存edu_deliveriesへ案内メールと新入社員の日数の列を追加する');
check((int) $pdo->query("SELECT send_invites FROM edu_deliveries WHERE title = '既存の配信'")->fetchColumn() === 0,
    '既存の配信は案内メールを送らない設定になる');
check((string) $pdo->query("SELECT status FROM edu_deliveries WHERE title = '既存の配信'")->fetchColumn() === 'running',
    '既存の配信の状態を変えない');
check((int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='edu_delivery_series'")->fetchColumn() === 1,
    '既存DBへ毎月の配信の系列tableを作成する');
check($runner->migrate() === 0, '教育の配信の機能のmigrationも冪等');
$pdo->exec("DELETE FROM edu_deliveries WHERE title = '既存の配信'");

// テナントの管理(論理削除と管理の項目)。既存のテナントの行と状態を変えず、列を足すだけであることを確かめる。
$pdo->exec("UPDATE tenants SET status = 'suspended' WHERE id = 2");
foreach (['deleted_at', 'contact_name', 'contact_email', 'contract_end_date', 'target_limit', 'memo'] as $column) {
    $pdo->exec("ALTER TABLE tenants DROP COLUMN {$column}");
}
$pdo->exec("DELETE FROM schema_migrations WHERE version = '20261005-tenant-management'");
check($runner->pending() === ['20261005-tenant-management'], 'テナントの管理のmigrationだけがpending');
check($runner->migrate() === 1, '既存DBへテナントの管理の列を追加できる');
$tenantCols = array_column($pdo->query('PRAGMA table_info(tenants)')->fetchAll(), 'name');
foreach (['deleted_at', 'contact_name', 'contact_email', 'contract_end_date', 'target_limit', 'memo'] as $column) {
    check(in_array($column, $tenantCols, true), "既存tenantsへ{$column}を追加する");
}
check((string) $pdo->query('SELECT status FROM tenants WHERE id = 2')->fetchColumn() === 'suspended',
    '既存のテナントの状態を変えない');
check((int) $pdo->query('SELECT COUNT(*) FROM tenants')->fetchColumn() === 2, '既存のテナントの行数を変えない');
check($runner->migrate() === 0, 'テナントの管理のmigrationも冪等');
$pdo->exec("UPDATE tenants SET status = 'active' WHERE id = 2");

// 招待メールとパスワード再設定(A1、A2)。既存のユーザのパスワードとログインの記録を変えず、列と表を足すだけであることを確かめる。
$pdo->exec("INSERT INTO users (tenant_id, email, password_hash, name, role, status, last_login_at)
    VALUES (1, 'migration-user@example.test', 'existing-hash', '既存', 'operator', 'active', '2026-09-01 09:00:00')");
$pdo->exec('DROP TABLE user_password_tokens');
$pdo->exec('ALTER TABLE users DROP COLUMN password_pending');
$pdo->exec('ALTER TABLE users DROP COLUMN session_epoch');
$pdo->exec("DELETE FROM schema_migrations WHERE version = '20261008-user-password-tokens'");
check($runner->pending() === ['20261008-user-password-tokens'], 'パスワード設定のmigrationだけがpending');
check($runner->migrate() === 1, '既存DBへパスワード設定の列と表を追加できる');
$userCols = array_column($pdo->query('PRAGMA table_info(users)')->fetchAll(), 'name');
check(in_array('password_pending', $userCols, true) && in_array('session_epoch', $userCols, true),
    '既存usersへpassword_pendingとsession_epochを追加する');
$existingUser = $pdo->query("SELECT password_hash, password_pending, session_epoch, last_login_at FROM users WHERE email = 'migration-user@example.test'")->fetch(PDO::FETCH_ASSOC);
check($existingUser['password_hash'] === 'existing-hash' && (int) $existingUser['password_pending'] === 0
    && (int) $existingUser['session_epoch'] === 0 && $existingUser['last_login_at'] === '2026-09-01 09:00:00',
    '既存のユーザのパスワードとログインの記録を変えない(未設定にもしない)');
check((int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='user_password_tokens'")->fetchColumn() === 1,
    '既存DBへパスワード設定のトークンの表を作成する');
check($runner->migrate() === 0, 'パスワード設定のmigrationも冪等');
$pdo->exec("DELETE FROM users WHERE email = 'migration-user@example.test'");

$after = (int) $pdo->query('SELECT COUNT(*) FROM targets')->fetchColumn();
check($after === $before, 'migrationで業務data件数が変わらない');
check((int) $pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn() === 22,
    'schema_migrationsへ22件だけ記録される');
check(in_array('credential_capture_approval_ref', array_column(
    $pdo->query('PRAGMA table_info(campaigns)')->fetchAll(), 'name'
), true), 'campaignsへ顧客承認参照を追加する');
check((int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='credential_captures'")->fetchColumn() === 1,
    '暗号化本文の専用tableを作成する');
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
    '20260923-template-snapshots',
    '20260923-credential-captures',
    '20260923-campaign-close',
    '20260925-surveys',
    '20260927-edu-rich-content',
    '20261001-edu-delivery-features',
    '20261005-tenant-management',
    '20261008-user-password-tokens',
], '現行DBは21件の後続migrationがpending');
check($currentRunner->migrate() === 21, '現行DBへ残りのmigrationを適用する');
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
check($rotationRunner->migrate() === 20, '既存automation DBへ20件の後続migrationを適用する');
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
check($unversionedRunner->migrate() === 22, 'version tableなしDBへ全migrationを適用する');
check((int) $unversionedPdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn() === 22,
    'version tableを作成して22件記録する');

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
check($legacyRunner->migrate() === 22, '旧constraint DBへ全migrationを適用する');
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
