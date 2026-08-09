<?php
declare(strict_types=1);

function campaign_editor_ui_check(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    echo "PASS: {$message}\n";
}

$index = (string) file_get_contents(__DIR__ . '/../index.html');
$script = (string) file_get_contents(__DIR__ . '/../app.js');
$css = (string) file_get_contents(__DIR__ . '/../assets/app.css');

echo "=== campaign editor UI ===\n";

campaign_editor_ui_check(str_contains($script, 'contentImportCampaigns'), '過去campaign取込選択がある');
campaign_editor_ui_check(str_contains($script, 'importSelectedCampaigns'), '複数campaign取込処理がある');
campaign_editor_ui_check(str_contains($script, 'contentCountLabel'), 'content件数を表示する');
campaign_editor_ui_check(str_contains($script, 'collapseAllContents'), '全contentを折りたためる');
campaign_editor_ui_check(str_contains($script, 'content-row-summary'), '折りたたみ時のsummaryがある');
campaign_editor_ui_check(str_contains($script, "size: 'xl'"), 'campaign modalをXL表示する');
campaign_editor_ui_check(str_contains($index, 'modal-dialog-scrollable'), 'modal bodyをviewport内でscrollする');
campaign_editor_ui_check(str_contains($css, '.campaign-content-toolbar'), '大量content向けstyleがある');
campaign_editor_ui_check(!str_contains($script, 'onclick="content'), 'inline content handlerを使わない');

echo "ALL TESTS PASSED\n";
