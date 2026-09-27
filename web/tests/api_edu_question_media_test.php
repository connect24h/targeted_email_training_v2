<?php
declare(strict_types=1);

// 設問の画像と選択肢ごとの解説(08 設計書の G17)。作成、更新、画像のアップロード、Excel と ZIP の取込、出力。
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../lib/OfficeDocumentReader.php';
require_once __DIR__ . '/../lib/SimpleXlsx.php';
require_once __DIR__ . '/../lib/EduMedia.php';

$root = sys_get_temp_dir() . '/tet2-edu-qmedia-' . getmypid();
putenv("TET2_EDU_MEDIA_DIR={$root}");
register_shutdown_function(static function () use ($root) {
    exec('rm -rf ' . escapeshellarg($root));
});

tet2_test_boot();
load_api('edu_questions');
$tenantId = (int) current_user()['tenant_id'];
Db::run("INSERT INTO edu_categories (tenant_id, name, slug, is_active, is_shared) VALUES (?, '送金', 'aw-bec', 1, 0)", [$tenantId]);
$categoryId = (int) Db::one("SELECT id FROM edu_categories WHERE slug = 'aw-bec'")['id'];
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAAFklEQVR4nGP8z8DAwMDAxMDAwMDAAAANHQEDasKb6QAAAABJRU5ErkJggg==');

// --- 作成と更新 ---
$r = call_handler('edu_q_handle_create', [
    'category_id' => $categoryId, 'title' => '口座変更の依頼を確かめる方法は', 'question_type' => 'single_choice',
    'options' => ['以前からの連絡先で確かめる', '署名の番号に電話する', 'メールに返信する'],
    'correct_answer' => [0], 'difficulty' => 2, 'explanation' => 'ポイント',
    'option_explanations' => ['別の経路なら偽物を見抜ける', '署名は書き換えられる', '返信は攻撃者に届く'],
]);
check($r['code'] === 201, 'QM-1: 選択肢ごとの解説つきで設問を作る');
$qid = (int) $r['payload']['question']['id'];
check(json_decode((string) $r['payload']['question']['option_explanations'], true)[1] === '署名は書き換えられる',
    'QM-1: 選択肢と同じ順に解説を保存する');
$r = call_handler('edu_q_handle_create', [
    'category_id' => $categoryId, 'title' => '数が合わない', 'question_type' => 'single_choice',
    'options' => ['A', 'B'], 'correct_answer' => [0], 'difficulty' => 1, 'option_explanations' => ['一つだけ'],
]);
check($r['code'] === 400, 'QM-2: 選択肢と解説の数が合わなければ拒否する');
$r = call_handler('edu_q_handle_update', ['id' => $qid, 'options' => ['A', 'B']]);
$row = Db::one('SELECT option_explanations FROM edu_questions WHERE id = ?', [$qid]);
check($r['code'] === 200 && $row['option_explanations'] === null, 'QM-3: 選択肢の数が変わったら古い解説を消す');
$r = call_handler('edu_q_handle_update', ['id' => $qid, 'option_explanations' => ['Aの解説', 'Bの解説']]);
check($r['code'] === 200 && json_decode((string) Db::one('SELECT option_explanations FROM edu_questions WHERE id = ?', [$qid])['option_explanations'], true)[0] === 'Aの解説',
    'QM-4: 解説だけを更新できる');

// --- 画像のアップロード、取得、差し替え、削除 ---
$r = call_handler('edu_q_handle_upload_image', ['id' => $qid, 'file_base64' => base64_encode($png)]);
check($r['code'] === 200 && preg_match('/^q-\d+-[0-9a-f]{8}\.png$/', (string) $r['payload']['question']['image_name']),
    'QM-5: 設問に画像を付ける');
$first = (string) $r['payload']['question']['image_name'];
check(is_file(EduMedia::questionDir($tenantId) . '/' . $first), 'QM-5: 画像を保存する');
$file = edu_q_image_file(current_user(), $qid);
check($file['mime'] === 'image/png' && is_file($file['path']), 'QM-6: 設問の画像の場所を決める');
$r = call_handler('edu_q_handle_upload_image', ['id' => $qid, 'file_base64' => base64_encode($png)]);
check(!is_file(EduMedia::questionDir($tenantId) . '/' . $first), 'QM-7: 差し替えたら古い画像を消す');
$r = call_handler('edu_q_handle_upload_image', ['id' => $qid, 'file_base64' => base64_encode('GIF89a....')]);
check($r['code'] === 400, 'QM-8: PNG と JPEG 以外は拒否する');
$r = call_handler('edu_q_handle_remove_image', ['id' => $qid]);
check($r['code'] === 200 && Db::one('SELECT image_name FROM edu_questions WHERE id = ?', [$qid])['image_name'] === null,
    'QM-9: 画像を外せる');

// --- Excel と画像の ZIP の取込 ---
$xlsxPath = tempnam(sys_get_temp_dir(), 'tet2-qm-');
$book = new SimpleXlsx();
$book->addSheet('設問', [
    ['カテゴリスラッグ', '設問文', '種別', '選択肢（改行区切り）', '正答番号（1始まり）', '難易度', '解説', '有効',
        '選択肢ごとの解説（改行区切り）', '画像ファイル名'],
    ['aw-bec', '画像つきの設問', 'single_choice', "正しい行動\n誤った行動", '1', 2, 'まとめ', 1, "正しい理由\n誤りの理由", 'scene-1.png'],
    ['aw-bec', '画像なしの設問', 'single_choice', "はい\nいいえ", '2', 1, '', 1, '', ''],
]);
$book->saveToFile($xlsxPath);
$xlsx = (string) file_get_contents($xlsxPath);
unlink($xlsxPath);
$zipPath = tempnam(sys_get_temp_dir(), 'tet2-qm-zip-');
$zip = new ZipArchive();
$zip->open($zipPath, ZipArchive::OVERWRITE);
$zip->addFromString('scene-1.png', $png);
$zip->close();
$zipBytes = (string) file_get_contents($zipPath);
unlink($zipPath);
$uploadId = EduMedia::beginUpload($tenantId, 1);
EduMedia::appendChunk($uploadId, $tenantId, 1, 0, $zipBytes);

$before = (int) Db::one('SELECT COUNT(*) AS n FROM edu_questions')['n'];
$r = call_handler('edu_q_handle_import_xlsx', ['filename' => 'q.xlsx', 'file_base64' => base64_encode($xlsx), 'images_upload_id' => $uploadId]);
check($r['code'] === 201 && ($r['payload']['imported'] ?? 0) === 2, 'QM-10: 新しい列つきの Excel と画像の ZIP を取り込む');
$imported = Db::one("SELECT * FROM edu_questions WHERE title = '画像つきの設問'");
check(json_decode((string) $imported['option_explanations'], true) === ['正しい理由', '誤りの理由'], 'QM-11: 選択肢ごとの解説を取り込む');
check(EduMedia::safeName((string) $imported['image_name']) && is_file(EduMedia::questionDir($tenantId) . '/' . $imported['image_name']),
    'QM-12: ZIP の画像を設問に付ける');
check(Db::one("SELECT image_name FROM edu_questions WHERE title = '画像なしの設問'")['image_name'] === null,
    'QM-13: 画像の列が空なら画像なし');

// ZIP にない画像名は、1件も追加しない
$book = new SimpleXlsx();
$book->addSheet('設問', [
    ['カテゴリスラッグ', '設問文', '種別', '選択肢（改行区切り）', '正答番号（1始まり）', '難易度', '画像ファイル名'],
    ['aw-bec', '追加されない', 'single_choice', "A\nB", '1', 1, 'missing.png'],
]);
$p = tempnam(sys_get_temp_dir(), 'tet2-qm-');
$book->saveToFile($p);
$bad = (string) file_get_contents($p);
unlink($p);
$count = (int) Db::one('SELECT COUNT(*) AS n FROM edu_questions')['n'];
$r = call_handler('edu_q_handle_import_xlsx', ['filename' => 'q.xlsx', 'file_base64' => base64_encode($bad)]);
check($r['code'] === 400 && (int) Db::one('SELECT COUNT(*) AS n FROM edu_questions')['n'] === $count,
    'QM-14: 画像が見つからなければ1件も追加しない');

// 旧い列だけの Excel は従来どおり入る
$book = new SimpleXlsx();
$book->addSheet('設問', [
    ['カテゴリスラッグ', '設問文', '種別', '選択肢（改行区切り）', '正答番号（1始まり）', '難易度', '解説', '有効'],
    ['aw-bec', '旧い形式', 'single_choice', "A\nB", '1', 1, '解説', 1],
]);
$p = tempnam(sys_get_temp_dir(), 'tet2-qm-');
$book->saveToFile($p);
$old = (string) file_get_contents($p);
unlink($p);
$r = call_handler('edu_q_handle_import_xlsx', ['filename' => 'q.xlsx', 'file_base64' => base64_encode($old)]);
check($r['code'] === 201, 'QM-15: 旧い列だけの Excel も取り込める');

$rows = edu_q_export_rows($tenantId);
check(in_array('選択肢ごとの解説（改行区切り）', $rows[0], true) && in_array('画像ファイル名', $rows[0], true),
    'QM-16: 出力に新しい列を含める');

echo "ALL TESTS PASSED\n";
