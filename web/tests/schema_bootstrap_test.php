<?php
declare(strict_types=1);

require_once __DIR__ . '/fixtures/TestDatabase.php';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException("FAIL: {$message}");
    }
    echo "PASS: {$message}\n";
}

$dbPath = sys_get_temp_dir() . '/tet2-schema-' . getmypid() . '.sqlite';
@unlink($dbPath);
register_shutdown_function(static fn() => @unlink($dbPath));

TestDatabase::create($dbPath);
$pdo = new PDO('sqlite:' . $dbPath, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$tables = $pdo->query(
    "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name"
)->fetchAll(PDO::FETCH_COLUMN);

$businessTables = array_values(array_filter(
    $tables,
    static fn(string $table): bool => $table !== 'schema_migrations'
));
check(count($businessTables) === 25, 'fresh DBに25個の業務tableがある');

$expectedTables = [
    'campaign_contents',
    'campaign_report_snapshots',
    'edu_score_snapshots',
    'campaign_automations',
    'campaign_automation_groups',
    'campaign_automation_runs',
];
foreach ($expectedTables as $table) {
    check(in_array($table, $businessTables, true), "{$table}が作成される");
}

$expectedColumns = [
    'campaigns' => ['beacon_base', 'content_delivery', 'deleted_at', 'test_redirect_emails'],
    'campaign_targets' => ['content_no'],
    'targets' => ['position_category', 'tenant_no'],
    'templates' => ['scenario_key'],
    'edu_categories' => ['is_shared'],
    'edu_questions' => ['is_shared'],
    'edu_assignments' => ['last_reminded_at'],
];
foreach ($expectedColumns as $table => $columns) {
    $actual = $pdo->query("PRAGMA table_info({$table})")->fetchAll(PDO::FETCH_COLUMN, 1);
    foreach ($columns as $column) {
        check(in_array($column, $actual, true), "{$table}.{$column}が存在する");
    }
}

$foreignKeyErrors = $pdo->query('PRAGMA foreign_key_check')->fetchAll();
check($foreignKeyErrors === [], 'foreign key違反がない');

echo "ALL TESTS PASSED\n";
