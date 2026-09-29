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
check(count($runner->pending()) === 31, '未適用migrationが31件ある');
check($runner->migrate() === 31, '初回はmigrationを31件適用する');
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

// 受講者のマイページ(L1、L5)。列と表を足し、既存の回答を1回目の回として写す。既存の割当と回答の行は変えない。
$pdo->exec("INSERT INTO edu_deliveries (id, tenant_id, title, status, delivery_type, pass_score) VALUES (901, 1, '回の写しの確認', 'running', 'elearning', 80)");
$pdo->exec("INSERT INTO edu_categories (id, tenant_id, name, slug) VALUES (901, 1, '回の確認', 'mig-attempt')");
$pdo->exec("INSERT INTO edu_questions (id, tenant_id, category_id, title, options, correct_answer, difficulty) VALUES (901, 1, 901, 'Q', '[\"a\",\"b\"]', '[1]', 2)");
$pdo->exec("INSERT INTO edu_assignments (id, tenant_id, delivery_id, target_id, access_token, status, started_at, completed_at, score)
    VALUES (901, 1, 901, 1, '" . str_repeat('9', 32) . "', 'completed', '2026-09-01 10:00:00', '2026-09-01 10:05:00', 100)");
$pdo->exec("INSERT INTO edu_responses (id, tenant_id, assignment_id, total_score, max_score, percentage, started_at, completed_at)
    VALUES (901, 1, 901, 2, 2, 100, '2026-09-01 10:00:00', '2026-09-01 10:05:00')");
$pdo->exec("INSERT INTO edu_response_answers (response_id, question_id, answer, is_correct, score_earned) VALUES (901, 901, '[1]', 1, 2)");
$pdo->exec('DROP TABLE edu_attempts');
$pdo->exec('DROP INDEX idx_users_target');
$pdo->exec('ALTER TABLE users DROP COLUMN target_id');
$pdo->exec('ALTER TABLE edu_deliveries DROP COLUMN allow_retake_after_pass');
$pdo->exec("DELETE FROM schema_migrations WHERE version = '20261012-learner-portal'");
check($runner->pending() === ['20261012-learner-portal'], 'マイページのmigrationだけがpending');
check($runner->migrate() === 1, '既存DBへマイページの列と表を追加できる');
check(in_array('target_id', array_column($pdo->query('PRAGMA table_info(users)')->fetchAll(), 'name'), true), '既存usersへtarget_idを追加する');
check((int) $pdo->query('SELECT allow_retake_after_pass FROM edu_deliveries WHERE id = 901')->fetchColumn() === 1,
    '既存の配信は合格の後の受け直しを許す(既定)');
$attempt = $pdo->query('SELECT * FROM edu_attempts WHERE assignment_id = 901')->fetchAll(PDO::FETCH_ASSOC);
check(count($attempt) === 1 && (int) $attempt[0]['attempt_no'] === 1 && (int) $attempt[0]['percentage'] === 100
    && (int) $attempt[0]['passed'] === 1 && $attempt[0]['completed_at'] === '2026-09-01 10:05:00',
    '既存の回答を1回目の回(点数、合否、日時)として写す');
check(json_decode((string) $attempt[0]['answers'], true) === [['question_id' => 901, 'answer' => [1], 'is_correct' => true, 'score_earned' => 2]],
    '既存の回答の設問ごとの解答を回に写す');
check($pdo->query("SELECT status || '/' || score FROM edu_assignments WHERE id = 901")->fetchColumn() === 'completed/100',
    '既存の割当の状態と点数を変えない');
$pdo->exec("DELETE FROM schema_migrations WHERE version = '20261012-learner-portal'");
check($runner->migrate() === 1 && (int) $pdo->query('SELECT COUNT(*) FROM edu_attempts WHERE assignment_id = 901')->fetchColumn() === 1,
    'マイページのmigrationを流し直しても回を二重に写さない');
check($runner->migrate() === 0, 'マイページのmigrationも冪等');
$pdo->exec('DELETE FROM edu_attempts WHERE assignment_id = 901');
$pdo->exec('DELETE FROM edu_response_answers WHERE response_id = 901');
$pdo->exec('DELETE FROM edu_responses WHERE id = 901');
$pdo->exec('DELETE FROM edu_assignments WHERE id = 901');
$pdo->exec('DELETE FROM edu_questions WHERE id = 901');
$pdo->exec('DELETE FROM edu_categories WHERE id = 901');
$pdo->exec('DELETE FROM edu_deliveries WHERE id = 901');

// 管理画面の多要素認証とパスワードの方針(段階1)。既存のユーザは多要素認証なしのまま、方針の行も作らない。
$pdo->exec("INSERT INTO users (tenant_id, email, password_hash, name, role, status, last_login_at)
    VALUES (1, 'mfa-migration@example.test', 'existing-hash', '既存', 'tenant_admin', 'active', '2026-09-01 09:00:00')");
$pdo->exec('DROP TABLE user_mfa_recovery_codes');
$pdo->exec('DROP TABLE tenant_security_policies');
$pdo->exec('ALTER TABLE users DROP COLUMN mfa_secret');
$pdo->exec('ALTER TABLE users DROP COLUMN mfa_enabled_at');
$pdo->exec('ALTER TABLE users DROP COLUMN mfa_last_step');
$pdo->exec("DELETE FROM schema_migrations WHERE version = '20261015-admin-mfa'");
check($runner->pending() === ['20261015-admin-mfa'], '多要素認証のmigrationだけがpending');
check($runner->migrate() === 1, '既存DBへ多要素認証の列と表を追加できる');
$mfaCols = array_column($pdo->query('PRAGMA table_info(users)')->fetchAll(), 'name');
check(count(array_intersect(['mfa_secret', 'mfa_enabled_at', 'mfa_last_step'], $mfaCols)) === 3, '既存usersへ多要素認証の列を追加する');
$mfaUser = $pdo->query("SELECT password_hash, mfa_secret, mfa_enabled_at, last_login_at FROM users WHERE email = 'mfa-migration@example.test'")->fetch(PDO::FETCH_ASSOC);
check($mfaUser['password_hash'] === 'existing-hash' && $mfaUser['mfa_secret'] === null && $mfaUser['mfa_enabled_at'] === null
    && $mfaUser['last_login_at'] === '2026-09-01 09:00:00', '既存のユーザは多要素認証なしのまま(パスワードとログインの記録も変えない)');
check((int) $pdo->query('SELECT COUNT(*) FROM tenant_security_policies')->fetchColumn() === 0, '方針の行は作らない(従来どおりの決まり)');
$weak = false;
try {
    $pdo->exec('INSERT INTO tenant_security_policies (tenant_id, min_length) VALUES (1, 8)');
} catch (PDOException) {
    $weak = true;
}
check($weak, '方針の表は12文字より弱い最小の文字数を拒む');
$pdo->exec('INSERT INTO tenant_security_policies (tenant_id) VALUES (NULL)');
$dupGlobal = false;
try {
    $pdo->exec('INSERT INTO tenant_security_policies (tenant_id) VALUES (NULL)');
} catch (PDOException) {
    $dupGlobal = true;
}
check($dupGlobal, '全体の方針(tenant_id NULL)は1行だけ');
$pdo->exec('DELETE FROM tenant_security_policies');
$pdo->exec("DELETE FROM schema_migrations WHERE version = '20261015-admin-mfa'");
check($runner->migrate() === 1 && $runner->migrate() === 0, '多要素認証のmigrationも冪等');
$pdo->exec("DELETE FROM users WHERE email = 'mfa-migration@example.test'");

// 配信ごとの受講の設定と、テナントの社内の問い合わせ先。既存の配信は今と同じ動き(どれも 0)のまま。
$pdo->exec("INSERT INTO edu_deliveries (id, tenant_id, title) VALUES (902, 1, '既存の配信')");
$optionColumns = ['shuffle_options', 'lock_material_during_test', 'allow_after_deadline', 'retake_from_test'];
foreach ($optionColumns as $column) {
    $pdo->exec("ALTER TABLE edu_deliveries DROP COLUMN {$column}");
}
$pdo->exec('ALTER TABLE edu_attempts DROP COLUMN test_started_at');
$pdo->exec('ALTER TABLE tenants DROP COLUMN edu_contact');
$pdo->exec("DELETE FROM schema_migrations WHERE version = '20261020-edu-delivery-options'");
check($runner->pending() === ['20261020-edu-delivery-options'], '配信の受講の設定のmigrationだけがpending');
check($runner->migrate() === 1, '既存DBへ配信の受講の設定の列を追加できる');
$option = $pdo->query('SELECT ' . implode(', ', $optionColumns) . ' FROM edu_deliveries WHERE id = 902')->fetch(PDO::FETCH_ASSOC);
check(array_map('intval', $option) === array_fill_keys($optionColumns, 0), '既存の配信の設定はどれも 0(今と同じ動き)');
check(in_array('test_started_at', array_column($pdo->query('PRAGMA table_info(edu_attempts)')->fetchAll(), 'name'), true),
    '受講の回へテストを始めた日時の列を追加する');
check($pdo->query('SELECT edu_contact FROM tenants WHERE id = 1')->fetchColumn() === null, 'テナントの問い合わせ先は空のまま');
$pdo->exec("DELETE FROM schema_migrations WHERE version = '20261020-edu-delivery-options'");
check($runner->migrate() === 1 && $runner->migrate() === 0, '配信の受講の設定のmigrationも冪等');
$pdo->exec('DELETE FROM edu_deliveries WHERE id = 902');
// 訓練と報告と設定(A-6〜A-10)。シナリオの概要、パスワードの禁止語、不審メールの登録した条件。
// 既存のテンプレートと方針の行を変えず、列と表を足すだけであることを確かめる。
$pdo->exec("INSERT INTO templates (tenant_id, kind, name, content) VALUES (1, 'body', '既存の本文(migration)', '本文')");
$pdo->exec('INSERT INTO tenant_security_policies (tenant_id, min_length, min_classes, require_mfa) VALUES (1, 14, 3, 0)');
$pdo->exec('DROP TABLE suspicious_mail_rules');
$pdo->exec('ALTER TABLE templates DROP COLUMN description');
$pdo->exec('ALTER TABLE tenant_security_policies DROP COLUMN banned_words');
$pdo->exec("DELETE FROM schema_migrations WHERE version = '20261021-training-report-options'");
check($runner->pending() === ['20261021-training-report-options'], '訓練と報告の設定のmigrationだけがpending');
check($runner->migrate() === 1, '既存DBへ概要と禁止語の列と条件の表を追加できる');
check(in_array('description', array_column($pdo->query('PRAGMA table_info(templates)')->fetchAll(), 'name'), true), '既存templatesへdescriptionを追加する');
check(in_array('banned_words', array_column($pdo->query('PRAGMA table_info(tenant_security_policies)')->fetchAll(), 'name'), true),
    '既存tenant_security_policiesへbanned_wordsを追加する');
check($pdo->query("SELECT description FROM templates WHERE name = '既存の本文(migration)'")->fetchColumn() === null, '既存のテンプレートは概要なしのまま');
check($pdo->query('SELECT min_length || \'/\' || COALESCE(banned_words, \'-\') FROM tenant_security_policies WHERE tenant_id = 1')->fetchColumn() === '14/-',
    '既存の方針の値を変えず、禁止語は空のまま');
check((int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='suspicious_mail_rules'")->fetchColumn() === 1,
    '既存DBへ不審メールの登録した条件の表を作成する');
$pdo->exec("DELETE FROM schema_migrations WHERE version = '20261021-training-report-options'");
check($runner->migrate() === 1 && $runner->migrate() === 0, '訓練と報告の設定のmigrationも冪等');
$pdo->exec("DELETE FROM templates WHERE name = '既存の本文(migration)'");
$pdo->exec('DELETE FROM tenant_security_policies');

// 測定の正しさ(段B1)。events の判定の列、宛先の送達の列、返信の取込の台帳を足すだけ。
// 既存の行動は verdict='user'(今と同じく数える)、既存の宛先は delivery_state NULL(率の分母は今のまま)。
$pdo->exec("INSERT INTO campaign_targets (id, campaign_id, target_id, tracking_id, content_no, send_status) VALUES (903, 2, 2, '9030000001', 1, 'sent')");
$pdo->exec("INSERT INTO events (tenant_id, campaign_id, tracking_id, event_type, occurred_at, source) VALUES (1, 2, '9030000001', 'click', '2026-09-01 10:00:00', 'apache_access')");
$pdo->exec('DROP TABLE reply_mails');
foreach (['verdict', 'verdict_reason', 'verdict_source', 'verdict_by', 'verdict_at'] as $column) {
    $pdo->exec("ALTER TABLE events DROP COLUMN {$column}");
}
$pdo->exec('DROP INDEX IF EXISTS idx_ct_delivery_state');
foreach (['delivery_state', 'delivery_state_at', 'delivery_detail'] as $column) {
    $pdo->exec("ALTER TABLE campaign_targets DROP COLUMN {$column}");
}
$pdo->exec("DELETE FROM schema_migrations WHERE version = '20261025-measurement-b1'");
check($runner->pending() === ['20261025-measurement-b1'], '測定の正しさのmigrationだけがpending');
check($runner->migrate() === 1, '既存DBへ判定の列、送達の列、返信の台帳を追加できる');
check($pdo->query("SELECT verdict || '/' || verdict_source FROM events WHERE tracking_id = '9030000001'")->fetchColumn() === 'user/auto',
    '既存の行動は利用者の行動(user)のまま');
check($pdo->query('SELECT delivery_state FROM campaign_targets WHERE id = 903')->fetchColumn() === null, '既存の宛先の送達の状態は不明(NULL)のまま');
check((int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='reply_mails'")->fetchColumn() === 1,
    '既存DBへ返信の取込の台帳を作成する');
$rejected = false;
try { $pdo->exec("UPDATE events SET verdict = 'bot' WHERE tracking_id = '9030000001'"); } catch (PDOException) { $rejected = true; }
check($rejected, '判定は user か scanner だけ(CHECK)');
$pdo->exec("DELETE FROM schema_migrations WHERE version = '20261025-measurement-b1'");
check($runner->migrate() === 1 && $runner->migrate() === 0, '測定の正しさのmigrationも冪等');
$pdo->exec("DELETE FROM events WHERE tracking_id = '9030000001'");
$pdo->exec('DELETE FROM campaign_targets WHERE id = 903');
// 段B2 の運用(B2-1 と B2-4)。対象者の従業員番号とメモ、自動の教育配信の実行履歴。
// 既存の対象者は空のまま、空の人は何人いても一意の索引にかからないこと。
$pdo->exec('DROP INDEX idx_targets_tenant_employee_no');
$pdo->exec('DROP TABLE edu_auto_enroll_runs');
$pdo->exec('ALTER TABLE targets DROP COLUMN employee_no');
$pdo->exec('ALTER TABLE targets DROP COLUMN memo');
$pdo->exec("DELETE FROM schema_migrations WHERE version = '20261027-ops-b2a'");
check($runner->pending() === ['20261027-ops-b2a'], '段B2 の運用のmigrationだけがpending');
check($runner->migrate() === 1, '既存DBへ従業員番号とメモの列、実行履歴の表を追加できる');
$targetCols = array_column($pdo->query('PRAGMA table_info(targets)')->fetchAll(), 'name');
check(in_array('employee_no', $targetCols, true) && in_array('memo', $targetCols, true), '既存targetsへemployee_noとmemoを追加する');
check((int) $pdo->query('SELECT COUNT(*) FROM targets WHERE employee_no IS NOT NULL OR memo IS NOT NULL')->fetchColumn() === 0,
    '既存の対象者は従業員番号もメモも空のまま');
check((int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='edu_auto_enroll_runs'")->fetchColumn() === 1,
    '既存DBへ自動の教育配信の実行履歴の表を作成する');
$pdo->exec("UPDATE targets SET employee_no = 'MIG-001' WHERE id = (SELECT MIN(id) FROM targets WHERE tenant_id = 1)");
$dupEmployeeNo = false;
try {
    $pdo->exec("UPDATE targets SET employee_no = 'MIG-001' WHERE id = (SELECT MAX(id) FROM targets WHERE tenant_id = 1)");
} catch (PDOException) {
    $dupEmployeeNo = true;
}
check($dupEmployeeNo, '同じテナントの従業員番号の重複は索引で拒む');
$pdo->exec('UPDATE targets SET employee_no = NULL');
$pdo->exec("DELETE FROM schema_migrations WHERE version = '20261027-ops-b2a'");
check($runner->migrate() === 1 && $runner->migrate() === 0, '段B2 の運用のmigrationも冪等');

// 通知の文面(C2): 表を足すだけ。既存DBへ単独で適用でき、上書きの行は作らない(既定の文面のまま)。
$pdo->exec('DROP TABLE notification_templates');
$pdo->exec("DELETE FROM schema_migrations WHERE version = '20261102-notification-templates'");
check($runner->migrate() === 1, '既存DBへ通知の文面の表を追加できる');
check((int) $pdo->query('SELECT COUNT(*) FROM notification_templates')->fetchColumn() === 0,
    '通知の文面の上書きの行は作らない(既定の文面のまま)');
$pdo->exec("INSERT INTO notification_templates (tenant_id, kind, subject, body) VALUES (1, 'edu_invite', 's', 'b')");
$dupTemplate = false;
try {
    $pdo->exec("INSERT INTO notification_templates (tenant_id, kind, subject, body) VALUES (1, 'edu_invite', 's2', 'b2')");
} catch (PDOException) {
    $dupTemplate = true;
}
check($dupTemplate, '同じテナントと種類の文面は1行だけ');
$pdo->exec('DELETE FROM notification_templates');
$pdo->exec("DELETE FROM schema_migrations WHERE version = '20261102-notification-templates'");
check($runner->migrate() === 1 && $runner->migrate() === 0, '通知の文面のmigrationも冪等');

// 段D の送信(D-a): 催促の設定の列と、種明かしメール・報告の通知の設定と送った記録の表を足すだけ。設定の行は作らない(送らない)。
$pdo->exec('DROP TABLE notification_sends');
$pdo->exec('DROP TABLE tenant_report_notify');
$pdo->exec('DROP TABLE campaign_reveal_settings');
$pdo->exec("DELETE FROM schema_migrations WHERE version = '20261105-sending-da'");
check($runner->migrate() === 1, '既存DBへ段D の送信(D-a)の表を追加できる');
foreach (['campaign_reveal_settings', 'tenant_report_notify', 'notification_sends'] as $table) {
    check((int) $pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn() === 0, "{$table} の行は作らない(既定で送らない)");
}
$deliveryCols = $pdo->query('PRAGMA table_info(edu_deliveries)')->fetchAll(PDO::FETCH_COLUMN, 1);
check(count(array_intersect(['remind_start_days', 'remind_interval_days', 'remind_after_deadline', 'remind_max_count'], $deliveryCols)) === 4,
    '配信へ催促の設定の列を足す');
check((int) $pdo->query('SELECT COUNT(*) FROM edu_deliveries WHERE remind_start_days IS NOT NULL OR remind_interval_days IS NOT NULL
    OR remind_after_deadline <> 0 OR remind_max_count IS NOT NULL')->fetchColumn() === 0, '既存の配信の催促は今と同じ(設定は空)');
check(in_array('remind_count', $pdo->query('PRAGMA table_info(edu_assignments)')->fetchAll(PDO::FETCH_COLUMN, 1), true),
    '割当へ催促の回数の列を足す');
$pdo->exec("DELETE FROM schema_migrations WHERE version = '20261105-sending-da'");
check($runner->migrate() === 1 && $runner->migrate() === 0, '段D の送信(D-a)のmigrationも冪等');

$after = (int) $pdo->query('SELECT COUNT(*) FROM targets')->fetchColumn();
check($after === $before, 'migrationで業務data件数が変わらない');
check((int) $pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn() === 31,
    'schema_migrationsへ31件だけ記録される');
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
    '20261012-learner-portal',
    '20261015-admin-mfa',
    '20261020-edu-delivery-options',
    '20261021-training-report-options',
    '20261025-measurement-b1',
    '20261027-ops-b2a',
    '20261028-ops-b2b',
    '20261102-notification-templates',
    '20261105-sending-da',
], '現行DBは30件の後続migrationがpending');
check($currentRunner->migrate() === 30, '現行DBへ残りのmigrationを適用する');
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
check($rotationRunner->migrate() === 29, '既存automation DBへ29件の後続migrationを適用する');
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
check($unversionedRunner->migrate() === 31, 'version tableなしDBへ全migrationを適用する');
check((int) $unversionedPdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn() === 31,
    'version tableを作成して31件記録する');

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
check($legacyRunner->migrate() === 31, '旧constraint DBへ全migrationを適用する');
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
