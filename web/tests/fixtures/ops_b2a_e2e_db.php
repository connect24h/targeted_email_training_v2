<?php
/**
 * 段B2 の運用(従業員番号とメモ、アウェアネスの成績、解答の CSV、自動の投入の履歴)の E2E(ops_b2a_e2e.mjs)用の合成 DB を作る。
 * 本番の DB とファイルには触れない。
 *
 * 使い方: php ops_b2a_e2e_db.php <新しい DB の絶対パス> <管理者のパスワード(パスワードの決まりに合うもの)>
 * 作るもの(アドレスは .test だけ。組織は Example Tenant(id=1)):
 *   - 組織管理者 e2e-admin@example.test、閲覧者 e2e-viewer@example.test(同じパスワード)
 *   - 対象者4人(従業員番号とメモつき2人、番号なし1人、テスト用1人)
 *   - アウェアネスの配信2件(設問2つ)と解答、訓練の失敗で自動に投入する配信1件と実行の記録2回
 */
declare(strict_types=1);

require_once __DIR__ . '/TestDatabase.php';
require_once __DIR__ . '/../../lib/PasswordPolicy.php';

[$script, $dbPath, $password] = $argv + [null, '', ''];
if ($dbPath === '' || PasswordPolicy::violation($password) !== null) {
    fwrite(STDERR, "usage: php ops_b2a_e2e_db.php <db> <password(" . PasswordPolicy::DESCRIPTION . ")>\n");
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
$run("INSERT INTO users (tenant_id, email, password_hash, name, role, status) VALUES (1, 'e2e-admin@example.test', ?, 'E2E 管理者', 'tenant_admin', 'active')", [$hash]);
$run("INSERT INTO users (tenant_id, email, password_hash, name, role, status) VALUES (1, 'e2e-viewer@example.test', ?, 'E2E 閲覧者', 'viewer', 'active')", [$hash]);

// 合成の既定の対象者(target1、target2)は、この画面の確認に使わないのでテスト用にしておく
$pdo->exec("UPDATE targets SET is_test = 1 WHERE tenant_id = 1");
$people = [];
foreach ([
    ['営業 一郎', 'e2e-sales1', '営業部', 'E2E-001', '東京の窓口'],
    ['総務 花子', 'e2e-ga1', '総務部', 'E2E-002', '夜勤あり'],
    ['番号なし 健', 'e2e-nonum', '開発部', null, null],
] as $i => [$name, $local, $dept, $no, $memo]) {
    $people[$local] = $run('INSERT INTO targets (tenant_id, tenant_no, email, name, company, department, employee_no, memo) VALUES (1, ?, ?, ?, ?, ?, ?, ?)',
        [10 + $i, "$local@example.test", $name, 'Example Co', $dept, $no, $memo]);
}
$people['e2e-test'] = $run("INSERT INTO targets (tenant_id, tenant_no, email, name, department, is_test) VALUES (1, 20, 'e2e-test@example.test', 'テスト 用', '営業部', 1)");

$run("INSERT INTO edu_categories (id, tenant_id, name, slug) VALUES (1, 1, 'フィッシング', 'e2e-b2a-phish')");
$run("INSERT INTO edu_questions (id, tenant_id, category_id, title, options, correct_answer) VALUES
    (1, 1, 1, 'リンクを開く前に確かめること', '[\"何もしない\",\"送信者とアドレス\"]', '[1]'),
    (2, 1, 1, '不審な添付を受け取ったら', '[\"開く\",\"報告する\"]', '[1]')");

$delivery = static function (string $title, string $scheduledAt, ?string $trigger = null) use ($run): int {
    $id = $run("INSERT INTO edu_deliveries (tenant_id, title, status, delivery_type, scheduled_at, triggered_by, target_type, created_at)
        VALUES (1, ?, 'running', 'awareness_quiz', ?, ?, ?, ?)", [$title, $scheduledAt, $trigger, $trigger ? 'risk' : 'all', $scheduledAt]);
    $run('INSERT INTO edu_delivery_questions (delivery_id, question_id, sort_order) VALUES (?, 1, 0), (?, 2, 1)', [$id, $id]);
    return $id;
};
$seq = 0;
$take = static function (int $deliveryId, int $targetId, ?string $completedAt, array $answers, ?string $assignedAt = null) use ($run, &$seq): int {
    $seq++;
    $aid = $run('INSERT INTO edu_assignments (tenant_id, delivery_id, target_id, access_token, status, completed_at, created_at) VALUES (1, ?, ?, ?, ?, ?, ?)',
        [$deliveryId, $targetId, str_pad((string) $seq, 32, 'e', STR_PAD_LEFT), $completedAt ? 'completed' : 'assigned', $completedAt, $assignedAt ?? '2026-09-01 09:00:00']);
    if ($completedAt !== null) {
        $rid = $run('INSERT INTO edu_responses (tenant_id, assignment_id, percentage, completed_at) VALUES (1, ?, 50, ?)', [$aid, $completedAt]);
        foreach ($answers as $qid => $ok) {
            $run('INSERT INTO edu_response_answers (response_id, question_id, answer, is_correct) VALUES (?, ?, ?, ?)', [$rid, $qid, $ok ? '[1]' : '[0]', $ok ? 1 : 0]);
        }
    }
    return $aid;
};
$sep = $delivery('E2E アウェアネス 9月', '2026-09-01 09:00:00');
$oct = $delivery('E2E アウェアネス 10月', '2026-10-01 09:00:00');
$take($sep, $people['e2e-sales1'], '2026-09-02 10:00:00', [1 => true, 2 => false]);
$take($sep, $people['e2e-ga1'], null, []);
$take($sep, $people['e2e-test'], '2026-09-02 11:00:00', [1 => true, 2 => true]);
$take($oct, $people['e2e-sales1'], '2026-10-02 10:00:00', [1 => true, 2 => true]);
$take($oct, $people['e2e-ga1'], '2026-10-03 10:00:00', [1 => false, 2 => true]);

// 訓練の失敗で自動に投入する配信と、その実行の記録(1回目で1人を入れ、2回目は0人)
$auto = $delivery('E2E 訓練の後の小問', '2026-09-05 09:00:00', 'phishing_failure');
$take($auto, $people['e2e-nonum'], null, [], '2026-09-06 09:00:03');
$run("INSERT INTO edu_auto_enroll_runs (tenant_id, delivery_id, source, started_at, finished_at, matched_count, enrolled_count) VALUES
    (1, ?, 'phishing_failure', '2026-09-06 09:00:00', '2026-09-06 09:00:04', 1, 1),
    (1, ?, 'phishing_failure', '2026-09-06 09:05:00', '2026-09-06 09:05:01', 1, 0)", [$auto, $auto]);

fwrite(STDOUT, "ok\n");
