<?php
/**
 * 分野のタグ(C1)の E2E(edu_tags_e2e.mjs)用の合成 DB を作る。本番の DB とファイルには触れない。
 *
 * 使い方: php edu_tags_e2e_db.php <新しい DB の絶対パス> <パスワード(パスワードの決まりに合うもの。管理者と受講者で共通)>
 * 作るもの(アドレスは .test だけ。組織は Example Tenant(id=1)):
 *   - 組織管理者 e2e-admin@example.test、受講者 sales1@example.test(マイページのアカウント)
 *   - 対象者: 営業 一郎(営業部)、総務 花子(総務部)、テスト用(数えない)
 *   - 分野のタグ: 自組織の親「フィッシング」、共有の親「パスワード」(組織管理者は変更できない)
 *   - 設問3問: Q1=フィッシング、Q2=パスワード、Q3=タグなし(E2E で子のタグを付ける)
 *   - 今月の提出: 一郎 Q1○ Q2×、花子 Q1× Q2○、テスト用 Q1× Q2×
 */
declare(strict_types=1);

require_once __DIR__ . '/TestDatabase.php';
require_once __DIR__ . '/../../lib/PasswordPolicy.php';

[$script, $dbPath, $password] = $argv + [null, '', ''];
if ($dbPath === '' || PasswordPolicy::violation($password) !== null) {
    fwrite(STDERR, "usage: php edu_tags_e2e_db.php <db> <password(" . PasswordPolicy::DESCRIPTION . ")>\n");
    exit(2);
}
TestDatabase::create($dbPath);
$pdo = new PDO('sqlite:' . $dbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA foreign_keys = ON');
$run = static function (string $sql, array $params = []) use ($pdo): int {
    $pdo->prepare($sql)->execute($params);
    return (int) $pdo->lastInsertId();
};
$hash = password_hash($password, PASSWORD_DEFAULT);
$run('INSERT INTO users (tenant_id, email, password_hash, name, role, status) VALUES (1, ?, ?, ?, ?, ?)',
    ['e2e-admin@example.test', $hash, 'E2E 管理者', 'tenant_admin', 'active']);

$sales = $run("INSERT INTO targets (tenant_id, tenant_no, email, name, company, department) VALUES (1, 11, 'sales1@example.test', '営業 一郎', 'Example Co', '営業部')");
$ga = $run("INSERT INTO targets (tenant_id, tenant_no, email, name, company, department) VALUES (1, 12, 'ga1@example.test', '総務 花子', 'Example Co', '総務部')");
$tester = $run("INSERT INTO targets (tenant_id, tenant_no, email, name, company, department, is_test) VALUES (1, 13, 'tester@example.test', 'テスト用', 'Example Co', '営業部', 1)");
$run("INSERT INTO users (tenant_id, email, password_hash, name, role, status, target_id) VALUES (1, 'sales1@example.test', ?, '営業 一郎', 'learner', 'active', ?)",
    [$hash, $sales]);

$phish = $run("INSERT INTO edu_tags (tenant_id, name, description, sort_order) VALUES (1, 'フィッシング', 'メールとリンクの見分け方', 0)");
$pwTag = $run("INSERT INTO edu_tags (tenant_id, name, description, sort_order) VALUES (NULL, 'パスワード', '共有の分野', 1)");

$run("INSERT INTO edu_categories (id, tenant_id, name, slug) VALUES (1, 1, '基礎', 'e2e-basic')");
$q1 = $run("INSERT INTO edu_questions (tenant_id, category_id, title, options, correct_answer) VALUES (1, 1, '不審なリンクはどうしますか', '[\"開く\",\"報告する\"]', '[1]')");
$q2 = $run("INSERT INTO edu_questions (tenant_id, category_id, title, options, correct_answer) VALUES (1, 1, 'パスワードの使い回しは', '[\"しない\",\"する\"]', '[0]')");
$q3 = $run("INSERT INTO edu_questions (tenant_id, category_id, title, options, correct_answer) VALUES (1, 1, '添付ファイルを開く前に', '[\"確認する\",\"すぐ開く\"]', '[0]')");
$run('INSERT INTO edu_question_tags (question_id, tag_id) VALUES (?, ?), (?, ?)', [$q1, $phish, $q2, $pwTag]);

$now = date('Y-m-d H:i:s');
$delivery = $run("INSERT INTO edu_deliveries (tenant_id, title, status, delivery_type, pass_score, feedback_mode) VALUES (1, 'E2E 分野の配信', 'running', 'elearning', 50, 'after_submit')");
foreach ([[$sales, 1, 0], [$ga, 0, 1], [$tester, 0, 0]] as [$target, $mark1, $mark2]) {
    $aid = $run("INSERT INTO edu_assignments (tenant_id, delivery_id, target_id, access_token, status, completed_at) VALUES (1, ?, ?, ?, 'completed', ?)",
        [$delivery, $target, bin2hex(random_bytes(16)), $now]);
    $pct = ($mark1 + $mark2) * 50;
    $rid = $run('INSERT INTO edu_responses (tenant_id, assignment_id, total_score, max_score, percentage, completed_at) VALUES (1, ?, ?, 2, ?, ?)',
        [$aid, $mark1 + $mark2, $pct, $now]);
    $run('INSERT INTO edu_response_answers (response_id, question_id, is_correct) VALUES (?, ?, ?), (?, ?, ?)', [$rid, $q1, $mark1, $rid, $q2, $mark2]);
    $run("INSERT INTO edu_attempts (tenant_id, assignment_id, attempt_no, started_at, completed_at, percentage, passed) VALUES (1, ?, 1, ?, ?, ?, ?)",
        [$aid, $now, $now, $pct, $pct >= 50 ? 1 : 0]);
}
echo "ok\n";
