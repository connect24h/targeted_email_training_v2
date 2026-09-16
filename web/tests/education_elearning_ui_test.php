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
education_ui_check(str_contains($app, 'previewEduMaterial'), 'スライド教材を試行できる');
education_ui_check(str_contains($app, '教材を試行'), '教材一覧に試行操作がある');
education_ui_check(str_contains($app, 'eduMaterialPreviewTitle')
    && str_contains($app, 'eduMaterialPreviewBody'), '試行画面にスライド本文を表示する');
education_ui_check(str_contains($app, 'previewEduQuestion'), '確認テスト設問を試行できる');
education_ui_check(str_contains($app, '回答を確認'), '試行中の回答をその場で確認できる');
education_ui_check(str_contains($app, '受講履歴や採点結果は保存されません'), '試行が履歴を保存しないことを明示する');
education_ui_check(str_contains($app, "eduMaterialPreviewTitle').textContent")
    && str_contains($app, "eduMaterialPreviewBody').textContent"), '教材本文をtextContentで安全に表示する');
education_ui_check(str_contains($app, 'importEduMaterialPptx') && str_contains($index, 'PowerPoint読込'),
    'PowerPoint教材をインポートできる');
education_ui_check(str_contains($app, 'setEduCat(0)') && str_contains($app, '全カテゴリ'),
    '全カテゴリの設問を一覧できる');
education_ui_check(str_contains($app, 'previewEduQuestionsWithAnswers') && str_contains($index, '回答付き一覧'),
    '確認テストを回答付きで一覧プレビューできる');
education_ui_check(str_contains($app, 'exportEduQuestionsXlsx') && str_contains($index, 'Excel出力'),
    '全設問をExcel出力できる');
education_ui_check(str_contains($app, 'importEduQuestionsXlsx') && str_contains($index, 'Excel読込'),
    'テンプレートExcelから設問を追加できる');

// 訓練→教育の自動連携(EduAutoEnroll)を画面から有効化できること。
// triggered_by を送れないと、tet2-edu-enroll.timer は永久に対象0件のままになる。
education_ui_check(str_contains($app, 'eduAutoEnrollField'), '訓練失敗者の自動追加を設定する項目がある');
education_ui_check(str_contains($app, "triggered_by = 'phishing_failure'")
    || str_contains($app, "body.triggered_by = 'phishing_failure'"), '自動追加を選ぶとtriggered_byを送る');
education_ui_check(str_contains($app, "f.auto_enroll.checked = true"), '訓練失敗者向けプリセットで自動追加を既定にする');
education_ui_check(str_contains($app, "if (!risk) f.auto_enroll.checked = false"), '訓練失敗者以外では自動追加を外す');

// 報告率(report rate)の可視化。失敗率だけでなく正しい行動を見るための指標。
// 報告データの取得経路自体は未実装(本文リンク方式は却下)だが、report イベントが
// 入れば動くところまでは用意してある。
education_ui_check(str_contains($app, 'goodRateClass'), '報告率は高いほど良い指標として色分けする');
education_ui_check(str_contains($app, 'report_rate'), 'レポート画面が報告率を描画する');
education_ui_check(str_contains($index, '報告率') && str_contains($index, '報告'), 'レポート表に報告の列がある');
education_ui_check(!str_contains($app, "label: '報告URL'"),
    '訓練メール本文に報告URLを差し込ませない(本文にあると訓練だと判明するため)');

echo "ALL TESTS PASSED\n";
