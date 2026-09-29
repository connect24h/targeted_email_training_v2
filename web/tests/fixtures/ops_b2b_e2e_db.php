<?php
/**
 * 運用の B2B(B2-5..B2-7)の E2E(ops_b2b_e2e.mjs)用の合成 DB を作る。本番の DB とファイルには触れない。
 *
 * 使い方: php ops_b2b_e2e_db.php <新しい DB の絶対パス> <管理者のパスワード> <テナントの data_dir(書き込める一時ディレクトリ)>
 * 作るもの(アドレスは .test だけ。組織は Example Tenant(id=1)):
 *   - 組織管理者 e2e-admin@example.test
 *   - 不審メール4件(当月。訓練2/実2、状況は open/in_progress/resolved を含み、初動・対応済の時刻を持つ) … 報告ダッシュボード(G11/G39)
 *   - 種明かしページ1件(名前つき、本文をファイルに保存) … 複数の種明かしページ(G29)
 *   - 教材(版2)と教育の配信・受講の回(material_version=2) … 教材の版(G20)
 */
declare(strict_types=1);

require_once __DIR__ . '/TestDatabase.php';
require_once __DIR__ . '/../../lib/PasswordPolicy.php';

[$script, $dbPath, $password, $dataDir] = $argv + [null, '', '', ''];
if ($dbPath === '' || $dataDir === '' || PasswordPolicy::violation($password) !== null) {
    fwrite(STDERR, "usage: php ops_b2b_e2e_db.php <db> <password(" . PasswordPolicy::DESCRIPTION . ")> <data_dir>\n");
    exit(2);
}
TestDatabase::create($dbPath);
$pdo = new PDO('sqlite:' . $dbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA foreign_keys = ON');
$run = static function (string $sql, array $params = []) use ($pdo): int {
    $pdo->prepare($sql)->execute($params);
    return (int) $pdo->lastInsertId();
};

$pdo->prepare('UPDATE tenants SET data_dir = ? WHERE id = 1')->execute([$dataDir]);
@mkdir($dataDir . '/reveal-pages', 0775, true);

$run('INSERT INTO users (tenant_id, email, password_hash, name, role, status) VALUES (?, ?, ?, ?, ?, ?)',
    [1, 'e2e-admin@example.test', password_hash($password, PASSWORD_DEFAULT), 'E2E 管理者', 'tenant_admin', 'active']);

$s1 = $run("INSERT INTO targets (tenant_id, tenant_no, email, name, company, department) VALUES (1, 21, 'sales1@example.test', '営業 一郎', 'Example Co', '営業部')");

// --- 報告ダッシュボード用の不審メール(当月) ---
$m = date('Y-m');
$smSeed = static function (string $day, int $isTraining, string $status, ?string $firstAction, ?string $resolvedAt) use ($run, $m): void {
    static $n = 0;
    $n++;
    $created = "$m-$day 09:00:00";
    $run("INSERT INTO suspicious_mails (tenant_id, source, raw_path, sha256, raw_bytes, received_at, is_training,
             analysis_json, findings_json, status, first_action_at, resolved_at, created_at)
          VALUES (1, 'upload', '/x.eml', ?, 10, ?, ?, '{}', '[]', ?, ?, ?, ?)",
        [hash('sha256', 'e2e-sm' . $n), $created, $isTraining, $status, $firstAction, $resolvedAt, $created]);
};
$smSeed('05', 1, 'open', null, null);                                  // 訓練・未確認
$smSeed('06', 1, 'in_progress', "$m-06 11:00:00", null);               // 訓練・確認中(初動2h)
$smSeed('07', 0, 'resolved', "$m-07 10:00:00", "$m-07 15:00:00");      // 実・対応済(初動1h、対応済6h)
$smSeed('08', 0, 'resolved', "$m-08 12:00:00", "$m-09 12:00:00");      // 実・対応済(初動3h、対応済27h)

// --- 種明かしページ(複数管理 G29) ---
$rp = $run("INSERT INTO reveal_pages (tenant_id, name, storage_name, created_by) VALUES (1, '営業部向けの種明かし', 'pending', 'e2e-admin@example.test')");
$pdo->prepare('UPDATE reveal_pages SET storage_name = ? WHERE id = ?')->execute(["reveal-$rp.html", $rp]);
file_put_contents($dataDir . "/reveal-pages/reveal-$rp.html",
    '<!DOCTYPE html><html><body><h1>これは訓練でした</h1></body></html>');

// --- 教材の版(G20): 版2の教材と、版2で受けた受講の回 ---
$material = $run("INSERT INTO edu_materials (tenant_id, title, description, slides, format, page_count, version, is_active)
                  VALUES (1, 'フィッシングの見分け方', '', ?, 'page_images', 2, 2, 1)",
    [json_encode([['title' => '表紙', 'body' => '本文']], JSON_UNESCAPED_UNICODE)]);
$run("INSERT INTO edu_material_pages (material_id, page_no, image_name, width, height) VALUES (?, 1, 'page-001.jpg', 1240, 1748)", [$material]);
$run("INSERT INTO edu_material_versions (material_id, version, source_name, page_count) VALUES (?, 1, 'v1.pdf', 1), (?, 2, 'v2.pdf', 2)", [$material, $material]);
$run("INSERT INTO edu_categories (id, tenant_id, name, slug) VALUES (1, 1, 'フィッシング', 'e2e-phishing')");
$run("INSERT INTO edu_questions (id, tenant_id, category_id, title, options, correct_answer, explanation, difficulty)
      VALUES (1, 1, 1, '不審なリンクは', '[\"開く\",\"報告する\"]', '[1]', '報告します。', 1)");
$delivery = $run("INSERT INTO edu_deliveries (tenant_id, title, status, delivery_type, pass_score, material_id, scheduled_at)
                  VALUES (1, 'E2E 教育配信', 'running', 'elearning', 60, ?, '2026-09-01 09:00:00')", [$material]);
$run('INSERT INTO edu_delivery_questions (delivery_id, question_id, sort_order) VALUES (?, 1, 0)', [$delivery]);
$aid = $run("INSERT INTO edu_assignments (tenant_id, delivery_id, target_id, access_token, status, completed_at)
             VALUES (1, ?, ?, 'e2e-tok1', 'completed', '2026-09-02 10:00:00')", [$delivery, $s1]);
$run("INSERT INTO edu_responses (tenant_id, assignment_id, percentage) VALUES (1, ?, 80)", [$aid]);
$run("INSERT INTO edu_attempts (tenant_id, assignment_id, attempt_no, completed_at, percentage, passed, material_version)
      VALUES (1, ?, 1, '2026-09-02 10:00:00', 80, 1, 2)", [$aid]);

fwrite(STDERR, "ops_b2b fixture ready: reveal_page_id=$rp delivery_id=$delivery\n");
echo "OK\n";
