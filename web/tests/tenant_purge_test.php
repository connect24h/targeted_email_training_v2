<?php
declare(strict_types=1);

/**
 * T3 テナントの完全削除。合成 DB と一時ディレクトリだけを使う(本番の /opt/training には触れない)。
 *
 * 確かめること:
 *   - 全テーブルに行を持つテナントを完全削除すると、DB は「そのテナントを作る前」と1行も違わない
 *     (ほかのテナントの行は1行も減らず、そのテナントの行は0になる)。audit_log は残る。
 *   - シードが DB の全テーブルを覆っていること(新しいテーブルを足したら、このテストと TenantPurge の両方を直す)。
 *   - ファイルは消さずに _deleted/<slug>-<日時>/ へ移り、DB のバックアップは 0600 で同じ場所に置かれる。
 *   - 条件を満たさない時と途中で失敗した時は、DB もファイルも元のまま。
 */

require_once __DIR__ . '/helpers.php';
$dbPath = tet2_test_boot();
require_once __DIR__ . '/../lib/TenantPurge.php';
load_api('tenants');

$root = sys_get_temp_dir() . '/tet2-purge-root-' . getmypid() . '-' . bin2hex(random_bytes(4));
mkdir($root, 0700, true);
register_shutdown_function(static function () use ($root): void {
    exec('rm -rf ' . escapeshellarg($root));
});

/** そのテナントの行を、DB の全テーブルに1行以上入れる。$t = tenant_id、$tag = 一意にする文字列。 */
function seedTenant(int $t, string $tag): void
{
    $id = static fn(string $sql, array $params = []): int => Db::insert($sql, $params);
    $userId = $id("INSERT INTO users (tenant_id, email, password_hash, name, role, status, last_login_at)
        VALUES (?, ?, 'x', ?, 'tenant_admin', 'active', '2026-09-01 10:00:00')", [$t, "admin-{$tag}@purge.test", "Admin {$tag}"]);
    $target = $id("INSERT INTO targets (tenant_id, tenant_no, email, name, status) VALUES (?, 99, ?, 'T', 'active')", [$t, "t-{$tag}@purge.test"]);
    $group = $id("INSERT INTO groups (tenant_id, name, kind) VALUES (?, ?, 'custom')", [$t, "G {$tag}"]);
    Db::run('INSERT INTO target_group (target_id, group_id) VALUES (?, ?)', [$target, $group]);
    $subject = $id("INSERT INTO templates (tenant_id, kind, name, content) VALUES (?, 'subject', ?, 's')", [$t, "S {$tag}"]);
    $campaign = $id("INSERT INTO campaigns (tenant_id, name, status, created_by, subject_template_id) VALUES (?, ?, 'done', ?, ?)",
        [$t, "C {$tag}", $userId, $subject]);
    Db::run('INSERT INTO campaign_contents (campaign_id, content_no, subject_template_id) VALUES (?, 1, ?)', [$campaign, $subject]);
    $tracking = substr(str_pad((string) crc32($tag), 10, '0', STR_PAD_LEFT), 0, 10);
    Db::run("INSERT INTO campaign_targets (campaign_id, target_id, tracking_id, content_no, send_status, sent_at)
        VALUES (?, ?, ?, 1, 'sent', '2026-09-02 10:00:00')", [$campaign, $target, $tracking]);
    Db::run("INSERT INTO send_schedule (campaign_id, scheduled_at, status) VALUES (?, '2026-09-02 10:00:00', 'done')", [$campaign]);
    Db::run("INSERT INTO delivery_log (campaign_id, tracking_id, to_email, result) VALUES (?, ?, ?, 'sent')", [$campaign, $tracking, "t-{$tag}@purge.test"]);
    Db::run("INSERT INTO events (tenant_id, campaign_id, tracking_id, event_type, occurred_at, source) VALUES (?, ?, ?, 'click', '2026-09-02 11:00:00', 'fixture')",
        [$t, $campaign, $tracking]);
    Db::run("INSERT INTO campaign_report_snapshots (campaign_id, tenant_id, payload) VALUES (?, ?, '{}')", [$campaign, $t]);
    Db::run("INSERT INTO campaign_template_snapshots (campaign_id, tenant_id, launch_sequence, content_no, role, template_id, name, format, content)
        VALUES (?, ?, 1, 1, 'subject', ?, 'S', 'text', 's')", [$campaign, $t, $subject]);
    Db::run("INSERT INTO credential_captures (tenant_id, campaign_id, tracking_id, auth_type, nonce, ciphertext) VALUES (?, ?, ?, 'basic', 'n', 'c')",
        [$t, $campaign, $tracking]);
    $automation = $id("INSERT INTO campaign_automations (tenant_id, name, source_campaign_id, frequency, day_of_month, time_mode, send_window_start, next_due_at, status)
        VALUES (?, ?, ?, 'monthly', 1, 'fixed', '09:00', '2026-11-01 09:00:00', 'paused')", [$t, "A {$tag}", $campaign]);
    Db::run('INSERT INTO campaign_automation_groups (automation_id, group_id) VALUES (?, ?)', [$automation, $group]);
    Db::run("INSERT INTO campaign_automation_runs (automation_id, occurrence_key, selected_send_at, status, generated_campaign_id)
        VALUES (?, ?, '2026-10-01 09:00:00', 'generated', ?)", [$automation, "k-{$tag}", $campaign]);
    $reportMail = $id("INSERT INTO report_mails (message_id_hash, content_hash, maildir_file, received_at, parse_status, ingest_mode)
        VALUES (?, ?, ?, '2026-09-03 09:00:00', 'parsed', 'normal')", ["mh-{$tag}", "ch-{$tag}", "file-{$tag}"]);
    Db::run("INSERT INTO report_mail_matches (report_mail_id, tracking_id, tenant_id, campaign_id, method, status) VALUES (?, ?, ?, ?, 'msgid', 'confirmed')",
        [$reportMail, $tracking, $t, $campaign]);
    $suspicious = $id("INSERT INTO suspicious_mails (tenant_id, source, report_mail_id, raw_path, sha256, raw_bytes, received_at, analysis_json, findings_json)
        VALUES (?, 'upload', ?, '/nonexistent/raw.eml', ?, 10, '2026-09-03 09:00:00', '{}', '[]')", [$t, $reportMail, hash('sha256', $tag)]);
    Db::run("INSERT INTO suspicious_mail_history (suspicious_mail_id, actor_email, field) VALUES (?, 'x@purge.test', 'status')", [$suspicious]);
    Db::run("INSERT INTO human_risk_scores (tenant_id, target_id, score, band, computed_date) VALUES (?, ?, 50, 'medium', '2026-09-01')", [$t, $target]);
    Db::run("INSERT INTO position_masters (tenant_id, title, category) VALUES (?, ?, '管理職')", [$t, "部長 {$tag}"]);
    Db::run("INSERT INTO integration_idempotency_keys (tenant_id, idempotency_key, action, request_hash, expires_at) VALUES (?, ?, 'a', 'h', '2026-12-01')",
        [$t, "idem-{$tag}"]);
    // 教育
    $category = $id("INSERT INTO edu_categories (tenant_id, name, slug) VALUES (?, ?, ?)", [$t, "Cat {$tag}", "cat-{$tag}"]);
    $question = $id("INSERT INTO edu_questions (tenant_id, category_id, title, options, correct_answer) VALUES (?, ?, 'Q', '[\"a\",\"b\"]', '[0]')",
        [$t, $category]);
    $material = $id("INSERT INTO edu_materials (tenant_id, title, slides) VALUES (?, ?, '[]')", [$t, "M {$tag}"]);
    Db::run("INSERT INTO edu_material_pages (material_id, page_no, image_name) VALUES (?, 1, 'p1.png')", [$material]);
    $series = $id("INSERT INTO edu_delivery_series (tenant_id, title, settings, day_of_month, time_of_day, next_run_at, is_active, created_by)
        VALUES (?, ?, '{}', 1, '09:00', '2026-11-01 09:00:00', 0, ?)", [$t, "Ser {$tag}", $userId]);
    $delivery = $id("INSERT INTO edu_deliveries (tenant_id, title, status, material_id, series_id, phish_campaign_id, target_group_id, created_by)
        VALUES (?, ?, 'closed', ?, ?, ?, ?, ?)", [$t, "D {$tag}", $material, $series, $campaign, $group, $userId]);
    Db::run('INSERT INTO edu_delivery_questions (delivery_id, question_id) VALUES (?, ?)', [$delivery, $question]);
    Db::run('INSERT INTO edu_delivery_targets (delivery_id, target_id) VALUES (?, ?)', [$delivery, $target]);
    $assignment = $id("INSERT INTO edu_assignments (tenant_id, delivery_id, target_id, access_token, status) VALUES (?, ?, ?, ?, 'completed')",
        [$t, $delivery, $target, "tok-{$tag}"]);
    $response = $id('INSERT INTO edu_responses (tenant_id, assignment_id) VALUES (?, ?)', [$t, $assignment]);
    Db::run('INSERT INTO edu_response_answers (response_id, question_id) VALUES (?, ?)', [$response, $question]);
    Db::run("INSERT INTO edu_answer_locks (assignment_id, question_id, answer, is_correct) VALUES (?, ?, '[0]', 1)", [$assignment, $question]);
    Db::run("INSERT INTO edu_score_snapshots (tenant_id, snapshot_type, snapshot_date, target_id, group_id) VALUES (?, 'target', '2026-09-01', ?, ?)",
        [$t, $target, $group]);
    // アンケート
    $survey = $id('INSERT INTO surveys (tenant_id, title) VALUES (?, ?)', [$t, "Sv {$tag}"]);
    $surveyQuestion = $id("INSERT INTO survey_questions (survey_id, question_type, title) VALUES (?, 'text', 'q')", [$survey]);
    $surveyDelivery = $id('INSERT INTO survey_deliveries (tenant_id, survey_id, title) VALUES (?, ?, ?)', [$t, $survey, "SD {$tag}"]);
    $surveyAssignment = $id('INSERT INTO survey_assignments (tenant_id, delivery_id, target_id, access_token) VALUES (?, ?, ?, ?)',
        [$t, $surveyDelivery, $target, "stok-{$tag}"]);
    $surveyResponse = $id("INSERT INTO survey_responses (tenant_id, delivery_id, assignment_id, submitted_at) VALUES (?, ?, ?, '2026-09-04 09:00:00')",
        [$t, $surveyDelivery, $surveyAssignment]);
    Db::run("INSERT INTO survey_answers (response_id, question_id, value) VALUES (?, ?, 'ok')", [$surveyResponse, $surveyQuestion]);
}

/** audit_log を除く全テーブルの全行(rowid の順)。比べるための写し。 */
function snapshotDb(): array
{
    $out = [];
    foreach (Db::all("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT IN ('audit_log', 'sqlite_sequence') ORDER BY name") as $row) {
        $table = (string) $row['name'];
        $out[$table] = Db::all("SELECT * FROM {$table} ORDER BY rowid");
    }
    return $out;
}

function tableCounts(): array
{
    $out = [];
    foreach (Db::all("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name") as $row) {
        $out[(string) $row['name']] = (int) Db::one('SELECT COUNT(*) AS c FROM ' . $row['name'])['c'];
    }
    return $out;
}

function purgeCode(callable $fn): array
{
    try {
        $fn();
    } catch (TenantPurgeError $e) {
        return [$e->getCode(), $e->getMessage()];
    }
    return [0, ''];
}

// ---------- 準備: ほかのテナント(tenant 2)にも全テーブルの行を入れる ----------
seedTenant(2, 'other');
seedTenant(1, 'example');
Db::run("INSERT INTO reputation_cache (provider, kind, lookup_key) VALUES ('vt', 'url', 'https://example.test/')");
$before = snapshotDb();
$countsBefore = tableCounts();

// ---------- 完全削除するテナント(tenant 3)を作る ----------
$dataDir = $root . '/victim-co';
mkdir($dataDir . '/campaign_1', 0775, true);
file_put_contents($dataDir . '/campaign_1/list.csv', "email\nt-victim@purge.test\n");
file_put_contents($dataDir . '/note.txt', 'keep me');
$deletedAt = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->modify('-100 days')->format('Y-m-d H:i:s');
Db::run("INSERT INTO tenants (id, name, slug, data_dir, status, deleted_at) VALUES (3, '消す社', 'victim-co', ?, 'deleted', ?)", [$dataDir, $deletedAt]);
seedTenant(3, 'victim');
Db::run("INSERT INTO users (tenant_id, email, password_hash, name, role, status) VALUES (3, 'super-home@purge.test', 'x', 'Super', 'superadmin', 'active')");
Db::run("INSERT INTO audit_log (tenant_id, user_id, action, detail) VALUES (3, NULL, 'target.create', 'victim-history')");

// シードが全テーブルを覆っていること(覆っていない = 新しいテーブル。このテストと TenantPurge を直す)
$countsSeeded = tableCounts();
$skip = ['audit_log', 'tenants', 'reputation_cache', 'schema_migrations', 'sqlite_sequence'];
$uncovered = [];
foreach ($countsSeeded as $table => $n) {
    if (!in_array($table, $skip, true) && $n <= ($countsBefore[$table] ?? 0)) {
        $uncovered[] = $table;
    }
}
check($uncovered === [], 'T3-0: シードが全テーブルに消すテナントの行を入れる(' . count($countsSeeded) . 'テーブル)' . ($uncovered ? ' 不足: ' . implode(',', $uncovered) : ''));
$unhandled = array_diff(array_keys($countsSeeded), TenantPurge::handledTables());
check($unhandled === [], 'T3-0: DB の全テーブルを完全削除の手順が扱う' . ($unhandled ? ' 不足: ' . implode(',', $unhandled) : ''));

$purger = new TenantPurge($root);
$now = new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo'));

// ---------- 条件を満たさない時は何もしない ----------
$snapshotVictim = snapshotDb();
Db::run("UPDATE tenants SET deleted_at = ? WHERE id = 3", [$now->modify('-89 days')->format('Y-m-d H:i:s')]);
[$code, $msg] = purgeCode(fn() => $purger->purge(3, 'victim-co', $now));
check($code === 409 && str_contains($msg, 'あと 1 日'), 'T3-1: 保持期間(90日)の前は 409 と残り日数');
Db::run("UPDATE tenants SET deleted_at = ? WHERE id = 3", [$deletedAt]);

Db::run("UPDATE tenants SET status = 'suspended' WHERE id = 3");
[$code] = purgeCode(fn() => $purger->purge(3, 'victim-co', $now));
check($code === 409, 'T3-2: 削除済みでなければ 409');
Db::run("UPDATE tenants SET status = 'deleted' WHERE id = 3");

[$code] = purgeCode(fn() => $purger->purge(3, 'victim-c0', $now));
check($code === 400, 'T3-3: 確認の slug が違えば 400');

$running = Db::insert("INSERT INTO edu_deliveries (tenant_id, title, status) VALUES (3, '走行中', 'running')");
[$code, $msg] = purgeCode(fn() => $purger->purge(3, 'victim-co', $now));
check($code === 409 && str_contains($msg, '教育の配信'), 'T3-4: 実行中の教育の配信があれば 409');
Db::run('DELETE FROM edu_deliveries WHERE id = ?', [$running]);
$queued = Db::insert("INSERT INTO send_schedule (campaign_id, scheduled_at, status) VALUES ((SELECT MIN(id) FROM campaigns WHERE tenant_id = 3), '2026-10-01', 'queued')");
[$code, $msg] = purgeCode(fn() => $purger->purge(3, 'victim-co', $now));
check($code === 409 && str_contains($msg, 'バッチ'), 'T3-5: 送信待ちのバッチがあれば 409');
Db::run('DELETE FROM send_schedule WHERE id = ?', [$queued]);

// 事前の確かめの後(退避とバックアップの間)に配信が始まっても、行を消すトランザクションの中で止める
$deleteRows = new ReflectionMethod(TenantPurge::class, 'deleteRows');
$race = Db::insert("INSERT INTO campaigns (tenant_id, name, status) VALUES (3, '割り込み', 'running')");
$countsBeforeRace = tableCounts();
[$code, $msg] = purgeCode(fn() => $deleteRows->invoke($purger, 3));
check($code === 409 && str_contains($msg, 'キャンペーン') && tableCounts() === $countsBeforeRace,
    'T3-5b: 行を消す直前にも実行中の仕事を確かめ、あれば 409 で1行も消さない');
Db::run('DELETE FROM campaigns WHERE id = ?', [$race]);
Db::run("UPDATE tenants SET status = 'suspended' WHERE id = 3");
$countsBeforeRace = tableCounts();
[$code] = purgeCode(fn() => $deleteRows->invoke($purger, 3));
check($code === 409 && tableCounts() === $countsBeforeRace, 'T3-5c: 行を消す直前に削除済みでなくなっていれば 409 で1行も消さない');
Db::run("UPDATE tenants SET status = 'deleted' WHERE id = 3");

Db::run('CREATE TABLE extra_tenant_things (id INTEGER PRIMARY KEY, tenant_id INTEGER)');
[$code, $msg] = purgeCode(fn() => $purger->purge(3, 'victim-co', $now));
check($code === 409 && str_contains($msg, 'extra_tenant_things'), 'T3-6: 手順が知らないテーブルがあれば中止する');
Db::run('DROP TABLE extra_tenant_things');

Db::run('UPDATE tenants SET data_dir = ? WHERE id = 3', [sys_get_temp_dir()]);
[$code, $msg] = purgeCode(fn() => $purger->purge(3, 'victim-co', $now));
check($code === 409 && str_contains($msg, '直下にない'), 'T3-7: ファイルの置き場がデータの置き場の直下になければ中止する');
Db::run('UPDATE tenants SET data_dir = ? WHERE id = 3', [$root . '/../' . basename($root) . '/victim-co/..']);
[$code] = purgeCode(fn() => $purger->purge(3, 'victim-co', $now));
check($code === 409, 'T3-7: .. を含むパスでデータの置き場そのものを指しても中止する');
Db::run('UPDATE tenants SET data_dir = ? WHERE id = 3', [$dataDir]);

Db::run("UPDATE tenants SET slug = '../evil' WHERE id = 3");
[$code] = purgeCode(fn() => $purger->purge(3, '../evil', $now));
check($code === 409 && is_dir($dataDir), 'T3-8: slug が不正(ディレクトリトラバーサル)なら中止し、ファイルも動かさない');
Db::run("UPDATE tenants SET slug = 'victim-co' WHERE id = 3");

// 退避のフォルダを作れない(_deleted が普通のファイル) → DB には触れない
file_put_contents($root . '/_deleted', 'not a dir');
[$code] = purgeCode(fn() => $purger->purge(3, 'victim-co', $now));
check($code === 500 && is_file($dataDir . '/note.txt'), 'T3-9: 退避できなければ中止し、ファイルは元の場所のまま');
unlink($root . '/_deleted');
check(snapshotDb() === $snapshotVictim, 'T3-1〜9: 中止した時は DB が1行も変わらない');

// ほかのテナントの行が消すテナントの行を参照している → 行の削除を取り消し、ファイルも戻す
$victimQuestion = (int) Db::one('SELECT id FROM edu_questions WHERE tenant_id = 3')['id'];
$otherDelivery = (int) Db::one('SELECT id FROM edu_deliveries WHERE tenant_id = 2')['id'];
Db::run('INSERT INTO edu_delivery_questions (delivery_id, question_id) VALUES (?, ?)', [$otherDelivery, $victimQuestion]);
$snapshotCross = snapshotDb();
[$code, $msg] = purgeCode(fn() => $purger->purge(3, 'victim-co', $now));
check($code === 409 && str_contains($msg, 'edu_delivery_questions'), 'T3-10: ほかのテナントから参照されていれば取り消し、理由のテーブルを示す');
check(snapshotDb() === $snapshotCross, 'T3-10: 取り消した時は DB が1行も変わらない');
check(is_file($dataDir . '/note.txt') && is_file($dataDir . '/campaign_1/list.csv'), 'T3-10: 退避したファイルを元の場所へ戻す');
check(glob($root . '/_deleted/*') === [], 'T3-10: バックアップと退避のフォルダを残さない');
check((int) Db::one('PRAGMA foreign_keys')['foreign_keys'] === 1, 'T3-10: 外部キーの確認を元に戻す');
Db::run('DELETE FROM edu_delivery_questions WHERE delivery_id = ? AND question_id = ?', [$otherDelivery, $victimQuestion]);

// ---------- 完全削除(API から) ----------
$GLOBALS['__TET2_TEST_BODY'] = ['id' => 3, 'confirm_slug' => 'victim-co'];
$GLOBALS['__TET2_TEST_ROLE'] = 'superadmin';
$GLOBALS['__TET2_TEST_AUDIT'] = [];
$GLOBALS['__TET2_TEST_CSRF_CALLS'] = 0;
try {
    tenants_handle_purge($purger);
    throw new RuntimeException('FAIL: json_out されない');
} catch (Tet2TestExit $e) {
    $response = $e->payload;
    $code = $e->httpCode;
}
check($code === 200 && $response['success'] === true, 'T3-11: 完全削除できる');
check($GLOBALS['__TET2_TEST_CSRF_CALLS'] === 1, 'T3-11: CSRF を確かめる');

// ほかのテナントの行は1行も減らず、消したテナントの行は0(全テーブル)。所属を外した superadmin は残す。
$super = Db::one("SELECT * FROM users WHERE email = 'super-home@purge.test'");
check($super !== null && $super['tenant_id'] === null && $super['role'] === 'superadmin', 'T3-12: 所属していた superadmin は消さずに所属を外す');
Db::run("DELETE FROM users WHERE email = 'super-home@purge.test'");
$after = snapshotDb();
$diffTables = [];
foreach ($before as $table => $rows) {
    if (($after[$table] ?? null) !== $rows) {
        $diffTables[] = $table;
    }
}
check($diffTables === [] && array_keys($after) === array_keys($before),
    'T3-13: 全テーブルで DB がテナントを作る前と1行も違わない(ほかのテナントの行は不変、消したテナントの行は0)'
    . ($diffTables ? ' 違い: ' . implode(',', $diffTables) : ''));
foreach (Db::all("SELECT name FROM sqlite_master WHERE type = 'table'") as $row) {
    $table = (string) $row['name'];
    $cols = array_column(Db::all('SELECT name FROM pragma_table_info(?)', [$table]), 'name');
    if ($table !== 'audit_log' && in_array('tenant_id', $cols, true)) {
        $n = (int) Db::one("SELECT COUNT(*) AS c FROM {$table} WHERE tenant_id = 3")['c'];
        if ($n !== 0) {
            throw new RuntimeException("FAIL: {$table} に tenant 3 の行が {$n} 行残る");
        }
    }
}
pass('T3-14: tenant_id を持つ全テーブルで消したテナントの行が0');
check(Db::one('SELECT 1 FROM tenants WHERE id = 3') === null, 'T3-14: tenants の行を消す');
check(Db::one("SELECT 1 FROM audit_log WHERE tenant_id = 3 AND detail = 'victim-history'") !== null, 'T3-15: 監査ログは消さない');
check((int) Db::one('PRAGMA foreign_keys')['foreign_keys'] === 1, 'T3-15: 外部キーの確認を元に戻す');
check(Db::all('PRAGMA foreign_key_check') === [], 'T3-15: 参照切れが残らない');

// 行数を返し、監査ログに tenant.purge(名前、slug、行数)を残す
check($response['total'] > 40 && $response['rows']['targets'] === 1 && $response['rows']['report_mails'] === 1,
    'T3-16: 消した行の数をテーブルごとに返す');
$purgeAudit = array_values(array_filter($GLOBALS['__TET2_TEST_AUDIT'], static fn(array $a): bool => $a['action'] === 'tenant.purge'));
check(count($purgeAudit) === 1, 'T3-16: 監査ログに tenant.purge を1件残す');
$detail = $purgeAudit[0]['detail'];
check(str_contains($detail, 'tenant_id=3,') && str_contains($detail, 'name=消す社') && str_contains($detail, 'slug=victim-co')
    && str_contains($detail, 'rows=' . $response['total']), 'T3-16: 監査ログに名前、slug、消した行数を書く');

// ファイルは消さずに退避し、バックアップは 0600 で同じ場所に置く
$evacuated = $response['evacuated_to'];
check(str_starts_with($evacuated, $root . '/_deleted/victim-co-') && !file_exists($dataDir), 'T3-17: ファイルを _deleted/<slug>-<日時> へ移す');
check(file_get_contents($evacuated . '/note.txt') === 'keep me' && is_file($evacuated . '/campaign_1/list.csv'), 'T3-17: ファイルの中身はそのまま');
$backups = glob($evacuated . '/tet2-before-purge-victim-co-*.sqlite');
check(count($backups) === 1, 'T3-18: DB のバックアップを退避のフォルダに置く');
check((fileperms($backups[0]) & 0777) === 0600, 'T3-18: バックアップの権限は 0600');
$backupPdo = new PDO('sqlite:' . $backups[0]);
check((int) $backupPdo->query('SELECT COUNT(*) FROM targets WHERE tenant_id = 3')->fetchColumn() === 1
    && (int) $backupPdo->query('SELECT COUNT(*) FROM tenants WHERE id = 3')->fetchColumn() === 1,
    'T3-18: バックアップには削除の前の行が入っている');

// ファイルの置き場がない(作ったが送信しなかった)テナントも完全削除できる
Db::run("INSERT INTO tenants (id, name, slug, data_dir, status, deleted_at) VALUES (4, '空', 'empty-co', ?, 'deleted', ?)",
    [$root . '/empty-co', $deletedAt]);
$result = $purger->purge(4, 'empty-co', $now->modify('+1 second'));
check($result['files_moved'] === false && is_dir($result['evacuated_to']) && Db::one('SELECT 1 FROM tenants WHERE id = 4') === null,
    'T3-19: ファイルがなくても、バックアップを退避のフォルダに作って完全削除する');

echo "ALL TESTS PASSED\n";
