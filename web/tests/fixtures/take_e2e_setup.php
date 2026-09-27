<?php
declare(strict_types=1);

/**
 * 受講画面の E2E 用に、合成 DB と画像の置き場を用意する(本番のデータには触れない)。
 *
 *   php take_e2e_setup.php <新しい DB のパス> <画像の置き場> <教材の PDF> [設問の画像...]
 *
 * PDF は本物の取込処理(EduMedia::convertPdf)でページ画像にする。
 * 1問ごとの答え合わせの配信と、提出後にまとめて答え合わせの配信を作り、受講のトークンを JSON で出力する。
 */
require_once __DIR__ . '/TestDatabase.php';
require_once __DIR__ . '/../../lib/EduMedia.php';

[$self, $dbPath, $mediaDir, $pdfPath] = array_pad($argv, 4, '');
$images = array_slice($argv, 4);
if ($dbPath === '' || $mediaDir === '' || !is_file($pdfPath)) {
    fwrite(STDERR, "使い方: php take_e2e_setup.php <DB> <画像の置き場> <PDF> [設問の画像...]\n");
    exit(2);
}
putenv("TET2_EDU_MEDIA_DIR={$mediaDir}");
TestDatabase::create($dbPath);
$pdo = new PDO('sqlite:' . $dbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA foreign_keys = ON');
$tenantId = (int) $pdo->query('SELECT id FROM tenants ORDER BY id LIMIT 1')->fetchColumn();
$targetId = (int) $pdo->query("SELECT id FROM targets WHERE tenant_id = {$tenantId} ORDER BY id LIMIT 1")->fetchColumn();

$pdo->prepare("INSERT INTO edu_materials (tenant_id, title, slides, format, page_count, source_name) VALUES (?, ?, '[]', 'page_images', 0, ?)")
    ->execute([$tenantId, 'インシデントの発見と初動報告', basename($pdfPath)]);
$materialId = (int) $pdo->lastInsertId();
$pages = EduMedia::convertPdf($pdfPath, EduMedia::materialDir($tenantId, $materialId));
$insert = $pdo->prepare('INSERT INTO edu_material_pages (material_id, page_no, image_name, page_text, width, height) VALUES (?, ?, ?, ?, ?, ?)');
foreach ($pages as $p) {
    $insert->execute([$materialId, $p['page_no'], $p['image_name'], $p['page_text'], $p['width'], $p['height']]);
}
$pdo->prepare('UPDATE edu_materials SET page_count = ? WHERE id = ?')->execute([count($pages), $materialId]);

$pdo->prepare("INSERT INTO edu_categories (tenant_id, name, slug) VALUES (?, 'インシデントの報告', 'aw-report')")->execute([$tenantId]);
$categoryId = (int) $pdo->lastInsertId();
$questions = [
    ['不審なメールのリンクを開いたが、画面には何も起きなかった。どうするか。',
        ['翌朝、担当者に余裕がありそうなときに伝える', '何も起きていないので、報告は不要と考える', '自分でウイルス検査をし、問題があれば報告する', '何も起きていなくても、すぐ窓口に報告する'], 3,
        ['翌朝までの間に、攻撃者が別の端末へ広がることがある。', '画面に何も出ない攻撃のほうが多い。', '検査で見つからない不正なプログラムもある。', '早いほど、窓口が被害を小さくできる。'],
        '何も起きていなくても、開いた時点で窓口に伝える。'],
    ['不審なメールの添付ファイルを開いたと窓口に報告する。伝え方として最も適切なものはどれか。',
        ['いつ、どの端末で、何をしたかを具体的に伝える', '「感染したかもしれない」とだけ短く伝える', '詳しいことが分かってから、まとめて伝える', '叱られないよう、開いたことは伏せて伝える'], 0,
        ['窓口が影響の範囲をすぐに判断できる。', '何を調べればよいかが分からない。', '待つ間に被害が広がる。', '伏せると調べる場所を誤る。'],
        'いつ、どの端末で、何をしたかを伝える。'],
];
$qIds = [];
foreach ($questions as $i => [$title, $options, $correct, $exps, $point]) {
    $pdo->prepare("INSERT INTO edu_questions (tenant_id, category_id, title, question_type, options, correct_answer, explanation, option_explanations, difficulty)
                   VALUES (?, ?, ?, 'single_choice', ?, ?, ?, ?, 2)")
        ->execute([$tenantId, $categoryId, $title, json_encode($options, JSON_UNESCAPED_UNICODE), json_encode([$correct]),
            $point, json_encode($exps, JSON_UNESCAPED_UNICODE)]);
    $qid = (int) $pdo->lastInsertId();
    if (isset($images[$i]) && is_file($images[$i])) {
        $name = EduMedia::saveQuestionImage($tenantId, $qid, (string) file_get_contents($images[$i]));
        $pdo->prepare('UPDATE edu_questions SET image_name = ? WHERE id = ?')->execute([$name, $qid]);
    }
    $qIds[] = $qid;
}

$tokens = [];
foreach (['immediate' => 'elearning', 'after_submit' => 'elearning'] as $mode => $type) {
    $pdo->prepare("INSERT INTO edu_deliveries (tenant_id, title, status, delivery_type, pass_score, target_type, material_id, feedback_mode)
                   VALUES (?, ?, 'running', ?, 80, 'individual', ?, ?)")
        ->execute([$tenantId, $mode === 'immediate' ? 'インシデントの発見と初動報告（答え合わせ1問ごと）' : 'インシデントの発見と初動報告（提出後）',
            $type, $materialId, $mode]);
    $deliveryId = (int) $pdo->lastInsertId();
    foreach ($qIds as $sort => $qid) {
        $pdo->prepare('INSERT INTO edu_delivery_questions (delivery_id, question_id, sort_order) VALUES (?, ?, ?)')->execute([$deliveryId, $qid, $sort]);
    }
    $token = bin2hex(random_bytes(16));
    $pdo->prepare("INSERT INTO edu_assignments (tenant_id, delivery_id, target_id, access_token, status) VALUES (?, ?, ?, ?, 'assigned')")
        ->execute([$tenantId, $deliveryId, $targetId, $token]);
    $tokens[$mode] = $token;
}
echo json_encode(['pages' => count($pages), 'tokens' => $tokens, 'question_ids' => $qIds], JSON_UNESCAPED_UNICODE), "\n";
