<?php
/**
 * 受講者のマイページの E2E(learner_portal_e2e.mjs)用の合成 DB を作る。本番の DB とファイルには触れない。
 *
 * 使い方: php learner_portal_e2e_db.php <新しい DB の絶対パス> <管理者のパスワード(パスワードの決まりに合うもの)>
 * 作るもの(アドレスは .test だけ。対象者は synthetic.sql の target1@example.test):
 *   - Example Tenant(id=1)の組織管理者 e2e-admin@example.test
 *   - 教育の配信2件(eラーニング 合格点80、期限7日後 / アウェアネス、期限3日後)。どちらも設問2問、教材なし、提出後に答え合わせ
 *   - 記名のアンケート1件(期限5日後、選択肢の設問1問)
 *   - それぞれ target1 への割当(トークンは受講者のマイページから開く)
 */
declare(strict_types=1);

require_once __DIR__ . '/TestDatabase.php';
require_once __DIR__ . '/../../lib/PasswordPolicy.php';

[$script, $dbPath, $password] = $argv + [null, '', ''];
if ($dbPath === '' || PasswordPolicy::violation($password) !== null) {
    fwrite(STDERR, "usage: php learner_portal_e2e_db.php <db> <password(" . PasswordPolicy::DESCRIPTION . ")>\n");
    exit(2);
}
TestDatabase::create($dbPath);
$pdo = new PDO('sqlite:' . $dbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA foreign_keys = ON');
$run = static function (string $sql, array $params = []) use ($pdo): void {
    $pdo->prepare($sql)->execute($params);
};
$at = static fn(string $modify): string => date('Y-m-d H:i:s', strtotime($modify));

$run('INSERT INTO users (tenant_id, email, password_hash, name, role, status) VALUES (?, ?, ?, ?, ?, ?)',
    [1, 'e2e-admin@example.test', password_hash($password, PASSWORD_DEFAULT), 'E2E 管理者', 'tenant_admin', 'active']);

$run("INSERT INTO edu_categories (id, tenant_id, name, slug) VALUES (1, 1, 'フィッシング', 'e2e-phishing')");
$run("INSERT INTO edu_questions (id, tenant_id, category_id, title, options, correct_answer, explanation, option_explanations, difficulty) VALUES
    (1, 1, 1, '不審なメールのリンクはどうしますか', '[\"すぐ開く\",\"開かずに報告する\"]', '[1]', '開かずに報告します。', '[\"開くと危険です\",\"正しい対応です\"]', 1),
    (2, 1, 1, 'パスワードの使い回しは', '[\"しない\",\"してよい\"]', '[0]', '使い回しはしません。', NULL, 1)");
$deliveries = [
    [1, '情報セキュリティ基礎', 'elearning', 80, $at('+7 days')],
    [2, 'アウェアネス 小問', 'awareness_quiz', null, $at('+3 days')],
];
foreach ($deliveries as [$id, $title, $type, $pass, $deadline]) {
    $run("INSERT INTO edu_deliveries (id, tenant_id, title, status, delivery_type, pass_score, deadline, feedback_mode, allow_retake_after_pass)
          VALUES (?, 1, ?, 'running', ?, ?, ?, 'after_submit', 1)", [$id, $title, $type, $pass, $deadline]);
    $run('INSERT INTO edu_delivery_questions (delivery_id, question_id, sort_order) VALUES (?, 1, 0), (?, 2, 1)', [$id, $id]);
    $run("INSERT INTO edu_assignments (tenant_id, delivery_id, target_id, access_token, status, token_expiry) VALUES (1, ?, 1, ?, 'assigned', ?)",
        [$id, bin2hex(random_bytes(16)), $deadline]);
}

$run("INSERT INTO surveys (id, tenant_id, title, status, is_anonymous) VALUES (1, 1, 'ふりかえり', 'published', 0)");
$run("INSERT INTO survey_questions (id, survey_id, sort_order, question_type, title, options, is_required) VALUES
    (1, 1, 0, 'single', '教育は役に立ちましたか', '[\"役に立った\",\"役に立たなかった\"]', 1)");
$run("INSERT INTO survey_deliveries (id, tenant_id, survey_id, title, status, deadline) VALUES (1, 1, 1, '教育のふりかえり', 'open', ?)", [$at('+5 days')]);
$run("INSERT INTO survey_assignments (tenant_id, delivery_id, target_id, access_token, status) VALUES (1, 1, 1, ?, 'assigned')", [bin2hex(random_bytes(16))]);
echo "ok\n";
