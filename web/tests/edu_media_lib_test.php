<?php
declare(strict_types=1);

// 教材の PDF 取込(ページ画像と文字)、分割アップロード、設問の画像の保存(08 設計書の G16、G17)。
require_once __DIR__ . '/../lib/EduMedia.php';
require_once __DIR__ . '/fixtures/pdf_fixture.php';

function eml_check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException("FAIL: {$message}");
    }
    echo "PASS: {$message}\n";
}


$root = sys_get_temp_dir() . '/tet2-edu-media-' . getmypid();
putenv("TET2_EDU_MEDIA_DIR={$root}");
register_shutdown_function(static function () use ($root) {
    exec('rm -rf ' . escapeshellarg($root));
});

// --- 分割アップロード ---
$pdf = tet2_test_make_pdf(['Page one', 'Page two']);
$uploadId = EduMedia::beginUpload(1, 7);
eml_check((bool) preg_match('/^[0-9a-f]{32}$/', $uploadId), 'EML-1: アップロードの ID は32桁の16進');
$half = intdiv(strlen($pdf), 2);
EduMedia::appendChunk($uploadId, 1, 7, 0, substr($pdf, 0, $half));
$total = EduMedia::appendChunk($uploadId, 1, 7, 1, substr($pdf, $half));
eml_check($total === strlen($pdf), 'EML-2: 分割して送ったバイト数が元の PDF と一致する');
$rejected = false;
try {
    EduMedia::appendChunk($uploadId, 2, 7, 2, 'x');
} catch (RuntimeException) {
    $rejected = true;
}
eml_check($rejected, 'EML-3: 別のテナントからは同じアップロードに追記できない');
$rejected = false;
try {
    EduMedia::appendChunk($uploadId, 1, 7, 5, 'x');
} catch (RuntimeException) {
    $rejected = true;
}
eml_check($rejected, 'EML-4: 順番を飛ばした追記を拒否する');
$rejected = false;
try {
    EduMedia::uploadPath('../../etc/passwd', 1, 7);
} catch (RuntimeException) {
    $rejected = true;
}
eml_check($rejected, 'EML-5: アップロードの ID の形が不正なら拒否する');

// --- PDF の変換 ---
$outDir = EduMedia::materialDir(1, 42);
$pages = EduMedia::convertPdf(EduMedia::uploadPath($uploadId, 1, 7), $outDir);
eml_check(count($pages) === 2, 'EML-6: 2ページの PDF から2枚のページ画像を作る');
eml_check($pages[0]['page_no'] === 1 && $pages[0]['image_name'] === 'page-001.jpg'
    && $pages[1]['image_name'] === 'page-002.jpg', 'EML-7: ページ画像の名前はサーバーが連番で決める');
eml_check(is_file($outDir . '/page-001.jpg') && str_starts_with((string) file_get_contents($outDir . '/page-001.jpg', false, null, 0, 3), "\xFF\xD8\xFF"),
    'EML-8: ページ画像は JPEG として保存される');
eml_check(str_contains($pages[0]['page_text'], 'Page one') && str_contains($pages[1]['page_text'], 'Page two'),
    'EML-9: ページごとの文字を抜き出す');
eml_check($pages[0]['width'] > 0 && $pages[0]['height'] > $pages[0]['width'], 'EML-10: ページ画像の幅と高さを記録する');
EduMedia::discardUpload($uploadId, 1, 7);
eml_check(!is_dir($root . '/tmp/' . $uploadId), 'EML-11: 取込後に一時ファイルを消す');

$badPath = $root . '/bad.pdf';
file_put_contents($badPath, 'not a pdf');
$rejected = false;
try {
    EduMedia::convertPdf($badPath, EduMedia::materialDir(1, 43));
} catch (RuntimeException) {
    $rejected = true;
}
eml_check($rejected && !is_dir(EduMedia::materialDir(1, 43)), 'EML-12: PDF でないファイルを拒否し、何も残さない');

// --- 設問の画像 ---
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAAFklEQVR4nGP8z8DAwMDAxMDAwMDAAAANHQEDasKb6QAAAABJRU5ErkJggg==');
$name = EduMedia::saveQuestionImage(1, 99, $png);
eml_check((bool) preg_match('/^q-99-[0-9a-f]{8}\.png$/', $name), 'EML-13: 設問の画像の名前はサーバーが決める');
eml_check(is_file(EduMedia::questionDir(1) . '/' . $name), 'EML-14: 設問の画像を保存する');
$rejected = false;
try {
    EduMedia::saveQuestionImage(1, 99, '<?php echo 1;');
} catch (RuntimeException) {
    $rejected = true;
}
eml_check($rejected, 'EML-15: PNG と JPEG 以外の中身を拒否する');
eml_check(EduMedia::safeName('page-001.jpg') && EduMedia::safeName('q-99-0a1b2c3d.png')
    && !EduMedia::safeName('../x.jpg') && !EduMedia::safeName('page-001.jpg/..'), 'EML-16: パスの改ざんを含む名前を拒否する');
eml_check(EduMedia::materialDir(null, 5) !== EduMedia::materialDir(1, 5), 'EML-17: 共有の教材とテナントの教材で置き場を分ける');

// --- 放置された一時ファイルの掃除 ---
$old = EduMedia::beginUpload(1, 7);
touch($root . '/tmp/' . $old, time() - 90000);
$fresh = EduMedia::beginUpload(1, 7);
$work = EduMedia::materialDir(1, 77) . '.work-abcd1234';
mkdir($work, 0770, true);
touch($work, time() - 7200);
EduMedia::collectGarbage();
eml_check(!is_dir($root . '/tmp/' . $old) && is_dir($root . '/tmp/' . $fresh), 'EML-18: 24時間を過ぎた一時ファイルだけを消す');
eml_check(!is_dir($work), 'EML-19: 1時間を過ぎた変換途中の作業ディレクトリを消す');

// --- PDF の差し替えの置き場の入れ替えと、途中で止まった残りの掃除 ---
$live = EduMedia::materialDir(1, 79);
mkdir($live, 0770, true);
file_put_contents($live . '/page-001.jpg', 'old');
$next = $live . '.new-abcd1234';
mkdir($next, 0770, true);
file_put_contents($next . '/page-001.jpg', 'new');
EduMedia::swapDir($live, $next);
eml_check(file_get_contents($live . '/page-001.jpg') === 'new' && glob($live . '.*') === [],
    'EML-21: 差し替えで置き場を入れ替え、古い置き場と作業用の置き場を残さない');
$staleNew = $live . '.new-00000001';
$staleOld = $live . '.old-00000002';
$freshNew = $live . '.new-00000003';
foreach ([$staleNew, $staleOld, $freshNew] as $d) {
    mkdir($d, 0770, true);
}
touch($staleNew, time() - 7200);
touch($staleOld, time() - 7200);
EduMedia::collectGarbage();
eml_check(!is_dir($staleNew) && !is_dir($staleOld) && is_dir($freshNew) && is_dir($live),
    'EML-22: 1時間を過ぎた差し替えの残りだけを消し、使用中の置き場は消さない');

// --- 失敗の詳細をログ用に残す ---
EduMedia::$lastError = '';
file_put_contents($root . '/broken.pdf', "%PDF-1.4\nbroken");
try {
    EduMedia::convertPdf($root . '/broken.pdf', EduMedia::materialDir(1, 78));
} catch (RuntimeException) {
}
eml_check(str_contains(EduMedia::$lastError, 'exit=') && str_contains(EduMedia::$lastError, 'cmd=pdf'),
    'EML-20: 変換に失敗したとき、コマンドと終了コードをログ用に残す');

echo "ALL TESTS PASSED\n";
