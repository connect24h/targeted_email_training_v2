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
check(count($businessTables) === 46, 'fresh DBに46個の業務tableがある');

$expectedTables = [
    'campaign_contents',
    'campaign_report_snapshots',
    'campaign_template_snapshots',
    'credential_captures',
    'edu_score_snapshots',
    'edu_material_pages',
    'edu_answer_locks',
    'campaign_automations',
    'campaign_automation_groups',
    'campaign_automation_runs',
    'integration_idempotency_keys',
    'position_masters',
    'edu_materials',
    'edu_delivery_targets',
    'human_risk_scores',
    'report_mails',
    'report_mail_matches',
    'suspicious_mails',
    'suspicious_mail_history',
    'reputation_cache',
    'surveys',
    'survey_questions',
    'survey_deliveries',
    'survey_assignments',
    'survey_responses',
    'survey_answers',
    'edu_delivery_series',
];
foreach ($expectedTables as $table) {
    check(in_array($table, $businessTables, true), "{$table}が作成される");
}

$expectedColumns = [
    'tenants' => ['deleted_at', 'contact_name', 'contact_email', 'contract_end_date', 'target_limit', 'memo'],
    'campaigns' => ['beacon_base', 'content_delivery', 'deleted_at', 'closed_at', 'closed_by', 'credential_capture_approval_ref', 'test_redirect_emails'],
    'campaign_targets' => ['content_no'],
    'targets' => ['position_category', 'tenant_no', 'archived_at', 'is_test'],
    'groups' => ['status', 'archived_at'],
    'templates' => ['scenario_key'],
    'edu_categories' => ['is_shared'],
    'edu_questions' => ['is_shared'],
    'edu_assignments' => ['last_reminded_at'],
    'edu_deliveries' => ['material_id', 'send_invites', 'series_id', 'target_positions', 'risk_results', 'new_target_days'],
];
foreach ($expectedColumns as $table => $columns) {
    $actual = $pdo->query("PRAGMA table_info({$table})")->fetchAll(PDO::FETCH_COLUMN, 1);
    foreach ($columns as $column) {
        check(in_array($column, $actual, true), "{$table}.{$column}が存在する");
    }
}

$foreignKeyErrors = $pdo->query('PRAGMA foreign_key_check')->fetchAll();
check($foreignKeyErrors === [], 'foreign key違反がない');

$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec("INSERT INTO report_mails
    (message_id_hash, content_hash, maildir_file, received_at, parse_status, ingest_mode)
    VALUES ('hash', 'content', 'fixture', '2026-09-06 12:00:00', 'parsed', 'normal')");
$reportId = (int) $pdo->lastInsertId();
$insert = $pdo->prepare("INSERT INTO report_mail_matches (report_mail_id, tracking_id, method, status)
    VALUES (?, '0987654321', 'msgid', 'confirmed')");
$insert->execute([$reportId]);
check($pdo->query('SELECT tracking_id FROM report_mail_matches')->fetchColumn() === '0987654321',
    'tracking_idの先頭ゼロを保持する');
foreach ([
    "INSERT INTO report_mails (message_id_hash, content_hash, maildir_file, received_at, parse_status, ingest_mode)
        VALUES ('hash', 'other', 'fixture2', '2026-09-06 12:01:00', 'parsed', 'normal')",
    "INSERT INTO report_mail_matches (report_mail_id, tracking_id, method, status)
        VALUES ($reportId, '0987654321', 'body_url', 'pending')",
    "INSERT INTO report_mail_matches (report_mail_id, tracking_id, method, status)
        VALUES (999999, '0000000001', 'msgid', 'pending')",
] as $sql) {
    $rejected = false;
    try {
        $pdo->exec($sql);
    } catch (PDOException) {
        $rejected = true;
    }
    check($rejected, '報告メールのUNIQUE/FK制約で不整合を拒否する');
}
$pdo->exec("DELETE FROM report_mails WHERE id = $reportId");
check((int) $pdo->query('SELECT COUNT(*) FROM report_mail_matches')->fetchColumn() === 0,
    '報告メール削除時に候補をCASCADE削除する');
foreach (['idx_report_mails_received_at', 'idx_report_mail_matches_tenant_status',
    'idx_report_mail_matches_tracking_id'] as $index) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type = 'index' AND name = ?");
    $stmt->execute([$index]);
    check((int) $stmt->fetchColumn() === 1, $index . 'が存在する');
}

echo "ALL TESTS PASSED\n";
