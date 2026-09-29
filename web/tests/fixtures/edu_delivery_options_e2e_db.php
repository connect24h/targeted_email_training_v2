<?php
/**
 * 配信ごとの受講の設定とマイページの改善の E2E(edu_delivery_options_e2e.mjs)用の合成 DB を作る。本番の DB とファイルには触れない。
 *
 * 使い方: php edu_delivery_options_e2e_db.php <新しい DB の絶対パス> <パスワード(パスワードの決まりに合うもの)>
 * 作るもの(アドレスは .test だけ。組織は Example Tenant(id=1)):
 *   - 組織管理者 e2e-admin@example.test と、target1@example.test のマイページのアカウント(learner、同じパスワード)
 *   - 配信「受講の設定つき」: eラーニング 合格点100、文字のスライドの教材、設問2問、選択肢の並べ替え、テスト中は教材を閉じる、
 *     不合格の後はテストから受け直す。target1(アカウントあり)と target2(アカウントなし)に割当
 * 標準出力に JSON(トークンと、target1 の表示の順の正解の選択肢の文)を出す。
 */
declare(strict_types=1);

require_once __DIR__ . '/TestDatabase.php';
require_once __DIR__ . '/../../lib/PasswordPolicy.php';
require_once __DIR__ . '/../../lib/EduOptionOrder.php';

[$script, $dbPath, $password] = $argv + [null, '', ''];
if ($dbPath === '' || PasswordPolicy::violation($password) !== null) {
    fwrite(STDERR, "usage: php edu_delivery_options_e2e_db.php <db> <password(" . PasswordPolicy::DESCRIPTION . ")>\n");
    exit(2);
}
TestDatabase::create($dbPath);
$pdo = new PDO('sqlite:' . $dbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA foreign_keys = ON');
$run = static function (string $sql, array $params = []) use ($pdo): void {
    $pdo->prepare($sql)->execute($params);
};
$hash = password_hash($password, PASSWORD_DEFAULT);

$run("INSERT INTO users (tenant_id, email, password_hash, name, role, status) VALUES (1, 'e2e-admin@example.test', ?, 'E2E 管理者', 'tenant_admin', 'active')", [$hash]);
$run("INSERT INTO users (tenant_id, email, password_hash, name, role, status, target_id) VALUES (1, 'target1@example.test', ?, 'Target One', 'learner', 'active', 1)", [$hash]);

$options = [1 => ['すぐ開く', '開かずに報告する', '転送して確かめる', '返信して確かめる'], 2 => ['使い回さない', '使い回す', '付箋に書く', '同僚と共有する']];
$correct = [1 => [1], 2 => [0]];
$run("INSERT INTO edu_categories (id, tenant_id, name, slug) VALUES (1, 1, 'フィッシング', 'e2e-opt')");
foreach ($options as $id => $list) {
    $run("INSERT INTO edu_questions (id, tenant_id, category_id, title, options, correct_answer, explanation, difficulty) VALUES (?, 1, 1, ?, ?, ?, '解説', 1)",
        [$id, $id === 1 ? '不審なメールのリンクはどうしますか' : 'パスワードはどうしますか',
            json_encode($list, JSON_UNESCAPED_UNICODE), json_encode($correct[$id])]);
}
$run("INSERT INTO edu_materials (id, tenant_id, title, description, slides, is_active)
      VALUES (1, 1, '基礎の教材', '説明', '[{\"title\":\"教材の1枚目\",\"body\":\"本文\"}]', 1)");
$run("INSERT INTO edu_deliveries (id, tenant_id, title, status, delivery_type, pass_score, feedback_mode, material_id, target_type,
        shuffle_options, lock_material_during_test, retake_from_test)
      VALUES (1, 1, '受講の設定つき', 'running', 'elearning', 100, 'after_submit', 1, 'individual', 1, 1, 1)");
$run('INSERT INTO edu_delivery_questions (delivery_id, question_id, sort_order) VALUES (1, 1, 0), (1, 2, 1)');

// target1 のトークンは、どちらの設問も元の順ではない並びになるものを選ぶ(画面の順が元と違うことを確かめるため)
$token1 = '';
for ($i = 0; $token1 === ''; $i++) {
    $candidate = sprintf('e2%030x', $i);
    $a = ['shuffle_options' => 1, 'access_token' => $candidate];
    if (EduOptionOrder::permutation($a, 1, 4) !== [0, 1, 2, 3] && EduOptionOrder::permutation($a, 2, 4) !== [0, 1, 2, 3]) {
        $token1 = $candidate;
    }
}
$token2 = bin2hex(random_bytes(16));
$run("INSERT INTO edu_assignments (tenant_id, delivery_id, target_id, access_token, status) VALUES (1, 1, 1, ?, 'assigned'), (1, 1, 2, ?, 'assigned')",
    [$token1, $token2]);
$a1 = ['shuffle_options' => 1, 'access_token' => $token1];
echo json_encode([
    'with_account' => $token1,
    'without_account' => $token2,
    'displayed' => [
        1 => EduOptionOrder::arrange(EduOptionOrder::permutation($a1, 1, 4), $options[1]),
        2 => EduOptionOrder::arrange(EduOptionOrder::permutation($a1, 2, 4), $options[2]),
    ],
    'correct_text' => [1 => $options[1][1], 2 => $options[2][0]],
    'original' => $options,
], JSON_UNESCAPED_UNICODE) . "\n";
