<?php
/**
 * 教材バンクのタブと教育レポートのタブの E2E(edu_report_tabs_e2e.mjs)用の合成 DB を作る。本番の DB とファイルには触れない。
 *
 * 使い方: php edu_report_e2e_db.php <新しい DB の絶対パス> <管理者のパスワード(パスワードの決まりに合うもの)>
 * 作るもの(アドレスは .test だけ。組織は Example Tenant(id=1)):
 *   - 組織管理者 e2e-admin@example.test
 *   - 部署つきの対象者 8人(営業部、総務部、開発部、部署なし)
 *   - 教材3本: PDF の本の版(縦長)、スライド版(横長)、文字のスライド
 *   - 教育の配信3件: eラーニング(期限あり、未完了と期限後の合格を含む)、eラーニング(期限なし)、アウェアネス
 *   - 受講の回(edu_attempts)、組織2の配信(分離の確認用)、訓練のキャンペーンと失敗のイベント(訓練と教育のタブ用)
 */
declare(strict_types=1);

require_once __DIR__ . '/TestDatabase.php';
require_once __DIR__ . '/../../lib/PasswordPolicy.php';

[$script, $dbPath, $password] = $argv + [null, '', ''];
if ($dbPath === '' || PasswordPolicy::violation($password) !== null) {
    fwrite(STDERR, "usage: php edu_report_e2e_db.php <db> <password(" . PasswordPolicy::DESCRIPTION . ")>\n");
    exit(2);
}
TestDatabase::create($dbPath);
$pdo = new PDO('sqlite:' . $dbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA foreign_keys = ON');
$run = static function (string $sql, array $params = []) use ($pdo): int {
    $pdo->prepare($sql)->execute($params);
    return (int) $pdo->lastInsertId();
};

$run('INSERT INTO users (tenant_id, email, password_hash, name, role, status) VALUES (?, ?, ?, ?, ?, ?)',
    [1, 'e2e-admin@example.test', password_hash($password, PASSWORD_DEFAULT), 'E2E 管理者', 'tenant_admin', 'active']);

$people = [];
foreach ([
    ['営業 一郎', 'sales1', '営業部'], ['営業 二郎', 'sales2', '営業部'], ['営業 三郎', 'sales3', '営業部'],
    ['総務 花子', 'ga1', '総務部'], ['総務 次郎', 'ga2', '総務部'],
    ['開発 太郎', 'dev1', '開発部'], ['開発 桜', 'dev2', '開発部'], ['所属なし 健', 'nodept', null],
] as $i => [$name, $local, $dept]) {
    $people[$local] = $run('INSERT INTO targets (tenant_id, tenant_no, email, name, company, department) VALUES (1, ?, ?, ?, ?, ?)',
        [10 + $i, "$local@example.test", $name, 'Example Co', $dept]);
}

// 教材: 本の版(縦長の PDF)、スライド版(横長の PDF)、文字のスライド
$material = static function (string $title, string $format, int $pages, int $width, int $height) use ($run): int {
    $id = $run("INSERT INTO edu_materials (tenant_id, title, description, slides, format, page_count, is_active) VALUES (1, ?, 'E2E の教材', ?, ?, ?, 1)",
        [$title, json_encode([['title' => '表紙', 'body' => '本文']], JSON_UNESCAPED_UNICODE), $format, $pages]);
    for ($p = 1; $p <= ($format === 'page_images' ? $pages : 0); $p++) {
        $run("INSERT INTO edu_material_pages (material_id, page_no, image_name, width, height) VALUES (?, ?, ?, ?, ?)",
            [$id, $p, sprintf('page-%03d.jpg', $p), $width, $height]);
    }
    return $id;
};
$book = $material('フィッシングの見分け方（本）', 'page_images', 3, 1240, 1748);
$slide = $material('フィッシングの見分け方（従業員向け）', 'page_images', 2, 1920, 1080);
$text = $material('パスワードの基本', 'text_slides', 0, 0, 0);

$run("INSERT INTO edu_categories (id, tenant_id, name, slug) VALUES (1, 1, 'フィッシング', 'e2e-phishing')");
$run("INSERT INTO edu_questions (id, tenant_id, category_id, title, options, correct_answer, explanation, difficulty) VALUES
    (1, 1, 1, '不審なメールのリンクはどうしますか', '[\"すぐ開く\",\"開かずに報告する\"]', '[1]', '開かずに報告します。', 1),
    (2, 1, 1, 'パスワードの使い回しは', '[\"しない\",\"してよい\"]', '[0]', '使い回しはしません。', 2)");

$delivery = static function (string $title, string $type, ?int $pass, ?string $deadline, ?int $materialId) use ($run): int {
    $id = $run("INSERT INTO edu_deliveries (tenant_id, title, status, delivery_type, pass_score, deadline, material_id, scheduled_at)
                VALUES (1, ?, 'running', ?, ?, ?, ?, '2026-09-01 09:00:00')", [$title, $type, $pass, $deadline, $materialId]);
    $run('INSERT INTO edu_delivery_questions (delivery_id, question_id, sort_order) VALUES (?, 1, 0), (?, 2, 1)', [$id, $id]);
    return $id;
};
$assign = static function (int $deliveryId, int $targetId, string $status, ?int $pct, ?string $completedAt, array $scores = [], int $tenantId = 1) use ($run): void {
    $aid = $run('INSERT INTO edu_assignments (tenant_id, delivery_id, target_id, access_token, status, completed_at) VALUES (?, ?, ?, ?, ?, ?)',
        [$tenantId, $deliveryId, $targetId, bin2hex(random_bytes(16)), $status, $completedAt]);
    if ($pct !== null) {
        $rid = $run('INSERT INTO edu_responses (tenant_id, assignment_id, percentage, total_score, max_score, completed_at) VALUES (?, ?, ?, ?, 100, ?)',
            [$tenantId, $aid, $pct, $pct, $completedAt]);
        $run('INSERT INTO edu_response_answers (response_id, question_id, is_correct, score_earned) VALUES (?, 1, ?, 0), (?, 2, 1, 0)',
            [$rid, $pct >= 80 ? 1 : 0, $rid]);
    }
    foreach ($scores as $n => $score) {
        $run('INSERT INTO edu_attempts (tenant_id, assignment_id, attempt_no, started_at, completed_at, percentage, passed) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$tenantId, $aid, $n + 1, $completedAt, $completedAt, $score, $score >= 80 ? 1 : 0]);
    }
};

// 配信1: 期限あり。完了4人(うち期限後の合格1人、2回目で合格1人)、受講中1人、未受講3人
$d1 = $delivery('E2E 情報セキュリティ基礎', 'elearning', 80, '2026-09-15', $book);
$assign($d1, $people['sales1'], 'completed', 90, '2026-09-10 10:00:00', [60, 90]);
$assign($d1, $people['sales2'], 'completed', 85, '2026-09-20 10:00:00', [85]);
$assign($d1, $people['sales3'], 'assigned', null, null);
$assign($d1, $people['ga1'], 'completed', 100, '2026-09-05 10:00:00', [100]);
$assign($d1, $people['ga2'], 'started', 40, '2026-09-06 10:00:00', [40]);
$assign($d1, $people['dev1'], 'completed', 80, '2026-09-12 10:00:00', [80]);
$assign($d1, $people['dev2'], 'assigned', null, null);
$assign($d1, $people['nodept'], 'assigned', null, null);
// 配信2: 期限なし
$d2 = $delivery('E2E 期限なしの配信', 'elearning', 80, null, $book);
$assign($d2, $people['sales1'], 'completed', 95, '2026-10-01 10:00:00', [95]);
$assign($d2, $people['dev1'], 'assigned', null, null);
// 配信3: アウェアネス(合格点なし)
$d3 = $delivery('E2E アウェアネス 小問', 'awareness_quiz', null, '2026-09-30', $slide);
$assign($d3, $people['sales1'], 'completed', 50, '2026-09-21 10:00:00', [50]);
$assign($d3, $people['ga1'], 'completed', 100, '2026-09-22 10:00:00', [100]);

// 組織2の配信(組織1には出ない)
$run("INSERT INTO edu_deliveries (tenant_id, title, status, delivery_type, pass_score) VALUES (2, '他組織の配信', 'running', 'elearning', 80)");

// 訓練と教育: キャンペーン2の失敗者(sales1 がクリック、ga2 が入力)
$run("UPDATE campaigns SET name = 'E2E 訓練キャンペーン' WHERE id = 2");
foreach ([['sales1', 'click', '0000000101'], ['ga2', 'auth', '0000000102']] as [$local, $event, $tracking]) {
    $run("INSERT INTO campaign_targets (campaign_id, target_id, tracking_id, content_no, send_status) VALUES (2, ?, ?, 1, 'sent')",
        [$people[$local], $tracking]);
    $run("INSERT INTO events (tenant_id, campaign_id, tracking_id, event_type, occurred_at, source) VALUES (1, 2, ?, ?, '2026-08-20 10:00:00', 'fixture')",
        [$tracking, $event]);
}
echo "ok\n";
