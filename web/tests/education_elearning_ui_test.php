<?php
declare(strict_types=1);

function education_ui_check(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    echo "PASS: {$message}\n";
}

$app = (string) file_get_contents(__DIR__ . '/../app.js');
$take = (string) file_get_contents(__DIR__ . '/../take.php');
$index = (string) file_get_contents(__DIR__ . '/../index.html');

education_ui_check(str_contains($app, 'value="individual"'), '個別対象者を選択できる');
education_ui_check(str_contains($app, 'is_test') && str_contains($app, 'テスト'), 'テスト宛先を識別して選択できる');
education_ui_check(str_contains($app, 'edu_materials.php'), '配信と教材バンクがスライド教材APIを使う');
education_ui_check(str_contains($app, 'eduMaterialSlides'), 'スライドを差し替える編集UIがある');
education_ui_check(str_contains($take, 'lessonView'), '受講画面に教材スライド表示がある');
education_ui_check(str_contains($take, 'lessonNextBtn'), '教材を順番に閲覧できる');
education_ui_check(str_contains($take, 'もう一度受講'), '不合格時に再受講を案内する');
education_ui_check(str_contains($index, 'eduMaterialsBody'), '教材バンクにスライド教材一覧がある');

echo "ALL TESTS PASSED\n";
