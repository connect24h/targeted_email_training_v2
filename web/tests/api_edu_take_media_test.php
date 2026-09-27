<?php
declare(strict_types=1);

// 受講画面の画像と、答えた直後の答え合わせ(08 設計書の G16〜G18)。
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../lib/EduMedia.php';

$root = sys_get_temp_dir() . '/tet2-edu-take-media-' . getmypid();
putenv("TET2_EDU_MEDIA_DIR={$root}");
register_shutdown_function(static function () use ($root) {
    exec('rm -rf ' . escapeshellarg($root));
});

tet2_test_boot();
load_api('edu_take');
$tenantId = (int) current_user()['tenant_id'];
$targetId = (int) Db::one('SELECT id FROM targets WHERE tenant_id = ? ORDER BY id LIMIT 1', [$tenantId])['id'];
Db::run("INSERT INTO edu_categories (tenant_id, name, slug) VALUES (?, '報告', 'aw-report')", [$tenantId]);
$categoryId = (int) Db::one("SELECT id FROM edu_categories WHERE slug = 'aw-report'")['id'];

// 設問2問(1問目に画像と選択肢ごとの解説)
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAAFklEQVR4nGP8z8DAwMDAxMDAwMDAAAANHQEDasKb6QAAAABJRU5ErkJggg==');
$q1 = Db::insert(
    "INSERT INTO edu_questions (tenant_id, category_id, title, question_type, options, correct_answer, explanation, option_explanations, difficulty)
     VALUES (?, ?, '気付いたらまず', 'single_choice', '[\"すぐ報告する\",\"様子を見る\"]', '[0]', 'まとめ', '[\"早いほど被害が小さい\",\"その間に広がる\"]', 1)",
    [$tenantId, $categoryId]
);
$imageName = EduMedia::saveQuestionImage($tenantId, $q1, $png);
Db::run('UPDATE edu_questions SET image_name = ? WHERE id = ?', [$imageName, $q1]);
$q2 = Db::insert(
    "INSERT INTO edu_questions (tenant_id, category_id, title, question_type, options, correct_answer, explanation, difficulty)
     VALUES (?, ?, '伝えること', 'single_choice', '[\"いつ何をしたか\",\"何も伝えない\"]', '[0]', '具体的に', 1)",
    [$tenantId, $categoryId]
);

// ページ画像の教材
$materialId = Db::insert(
    "INSERT INTO edu_materials (tenant_id, title, slides, format, page_count) VALUES (?, '初動報告', '[]', 'page_images', 2)",
    [$tenantId]
);
$dir = EduMedia::materialDir($tenantId, $materialId);
mkdir($dir, 0770, true);
foreach ([1, 2] as $no) {
    file_put_contents(sprintf('%s/page-%03d.jpg', $dir, $no), "\xFF\xD8\xFF\xE0fake");
    Db::run('INSERT INTO edu_material_pages (material_id, page_no, image_name, page_text, width, height) VALUES (?, ?, ?, ?, 100, 140)',
        [$materialId, $no, sprintf('page-%03d.jpg', $no), "ページ{$no}の文字"]);
}

function tm_delivery(int $tenantId, int $materialId, string $mode, string $type, int $targetId, string $token, array $questionIds): int
{
    $d = Db::insert(
        "INSERT INTO edu_deliveries (tenant_id, title, status, delivery_type, pass_score, target_type, material_id, feedback_mode)
         VALUES (?, '配信', 'running', ?, 100, 'individual', ?, ?)",
        [$tenantId, $type, $materialId, $mode]
    );
    foreach ($questionIds as $i => $qid) {
        Db::run('INSERT INTO edu_delivery_questions (delivery_id, question_id, sort_order) VALUES (?, ?, ?)', [$d, $qid, $i]);
    }
    Db::run("INSERT INTO edu_assignments (tenant_id, delivery_id, target_id, access_token, status) VALUES (?, ?, ?, ?, 'assigned')",
        [$tenantId, $d, $targetId, $token]);
    return $d;
}

$tokenA = str_repeat('a', 32);
$tokenB = str_repeat('b', 32);
tm_delivery($tenantId, $materialId, 'immediate', 'awareness_quiz', $targetId, $tokenA, [$q1, $q2]);
tm_delivery($tenantId, $materialId, 'after_submit', 'elearning', $targetId, $tokenB, [$q1, $q2]);

// --- start: ページ、画像の有無、答え合わせの時機 ---
$_GET = ['token' => $tokenA];
$r = call_handler('take_handle_start', [], 'operator', []);
check($r['code'] === 200, 'TM-1: 受講を始められる');
$p = $r['payload'];
check($p['delivery']['feedback_mode'] === 'immediate', 'TM-2: 答え合わせの時機を返す');
check($p['material']['format'] === 'page_images' && count($p['material']['pages']) === 2
    && $p['material']['pages'][1]['page_text'] === 'ページ2の文字', 'TM-3: 教材のページ一覧と文字を返す');
check($p['questions'][0]['has_image'] === true && $p['questions'][1]['has_image'] === false, 'TM-4: 設問に画像があるかを返す');
check(!isset($p['questions'][0]['correct_answer']) && !isset($p['questions'][0]['option_explanations']),
    'TM-5: 開始の時点では正解と解説を返さない');

// --- ページ画像と設問の画像 ---
$file = take_page_image_file($tokenA, 2);
check(str_ends_with($file['path'], '/page-002.jpg') && is_file($file['path']), 'TM-6: 配信の教材のページ画像を返す');
$denied = false;
try {
    take_page_image_file($tokenA, 3);
} catch (Tet2TestExit $e) {
    $denied = $e->httpCode === 404;
}
check($denied, 'TM-7: ないページは404');
$file = take_question_image_file($tokenA, $q1);
check($file['mime'] === 'image/png' && is_file($file['path']), 'TM-8: 配信の設問の画像を返す');
$other = Db::insert(
    "INSERT INTO edu_questions (tenant_id, category_id, title, question_type, options, correct_answer, difficulty, image_name)
     VALUES (?, ?, '配信外', 'single_choice', '[\"A\",\"B\"]', '[0]', 1, ?)",
    [$tenantId, $categoryId, $imageName]
);
$denied = false;
try {
    take_question_image_file($tokenA, $other);
} catch (Tet2TestExit $e) {
    $denied = $e->httpCode === 404;
}
check($denied, 'TM-9: 配信に含まれない設問の画像は返さない');
$denied = false;
try {
    take_page_image_file(str_repeat('c', 32), 1);
} catch (Tet2TestExit $e) {
    $denied = $e->httpCode === 404;
}
check($denied, 'TM-10: 無効なトークンでは画像を返さない');

// --- 1問ずつの答え合わせ ---
$r = call_handler('take_handle_answer', ['token' => $tokenA, 'question_id' => $q1, 'answer' => [1]], 'operator', []);
check($r['code'] === 200 && $r['payload']['feedback']['is_correct'] === false, 'TM-11: 答え合わせで不正解を返す');
check($r['payload']['feedback']['correct_answer'] === [0]
    && $r['payload']['feedback']['option_explanations'][1] === 'その間に広がる', 'TM-12: 正解と選択肢ごとの解説を返す');
$r = call_handler('take_handle_answer', ['token' => $tokenA, 'question_id' => $q1, 'answer' => [0]], 'operator', []);
check($r['code'] === 200 && $r['payload']['locked'] === true && $r['payload']['feedback']['your_answer'] === [1],
    'TM-13: 答え合わせの後は選び直せない(最初の解答を返す)');
$r = call_handler('take_handle_answer', ['token' => $tokenA, 'question_id' => $other, 'answer' => [0]], 'operator', []);
check($r['code'] === 404, 'TM-14: 配信に含まれない設問には答えられない');
$r = call_handler('take_handle_answer', ['token' => $tokenB, 'question_id' => $q1, 'answer' => [0]], 'operator', []);
check($r['code'] === 409, 'TM-15: 提出後に答え合わせをする配信では1問ずつの答え合わせをしない');

// 再開したとき、答え合わせ済みの結果を返す
$_GET = ['token' => $tokenA];
$r = call_handler('take_handle_start', [], 'operator', []);
check(count($r['payload']['answered']) === 1 && $r['payload']['answered'][0]['question_id'] === $q1,
    'TM-16: 再開時に答え合わせ済みの設問を返す');

// 提出: 固定した解答で採点する(提出の中身で正解に書き換えられない)
$r = call_handler('take_handle_answer', ['token' => $tokenA, 'question_id' => $q2, 'answer' => [0]], 'operator', []);
$r = call_handler('take_handle_submit', ['token' => $tokenA, 'answers' => [
    ['question_id' => $q1, 'answer' => [0]], ['question_id' => $q2, 'answer' => [0]],
]], 'operator', []);
check($r['code'] === 200 && $r['payload']['result']['total_score'] === 1, 'TM-17: 固定した解答で採点する(1問目は不正解のまま)');
check($r['payload']['feedback'][0]['option_explanations'][0] === '早いほど被害が小さい', 'TM-18: 提出後の結果にも選択肢ごとの解説を含める');

// eラーニングで不合格なら、固定を解いて受け直せる
$tokenC = str_repeat('d', 32);
tm_delivery($tenantId, $materialId, 'immediate', 'elearning', $targetId, $tokenC, [$q1]);
call_handler('take_handle_answer', ['token' => $tokenC, 'question_id' => $q1, 'answer' => [1]], 'operator', []);
$r = call_handler('take_handle_submit', ['token' => $tokenC, 'answers' => [['question_id' => $q1, 'answer' => [1]]]], 'operator', []);
$assignmentC = (int) Db::one('SELECT id FROM edu_assignments WHERE access_token = ?', [$tokenC])['id'];
check($r['payload']['result']['completed'] === false
    && (int) Db::one('SELECT COUNT(*) AS n FROM edu_answer_locks WHERE assignment_id = ?', [$assignmentC])['n'] === 0,
    'TM-19: 不合格なら答え合わせの記録を消して受け直せる');

// 提出後に答え合わせをする配信は、従来どおり提出の中身で採点する
$r = call_handler('take_handle_submit', ['token' => $tokenB, 'answers' => [
    ['question_id' => $q1, 'answer' => [0]], ['question_id' => $q2, 'answer' => [0]],
]], 'operator', []);
check($r['code'] === 200 && $r['payload']['result']['percentage'] === 100, 'TM-20: 提出後の答え合わせの配信は従来どおり');

echo "ALL TESTS PASSED\n";
