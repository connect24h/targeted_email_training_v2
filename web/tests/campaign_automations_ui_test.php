<?php
declare(strict_types=1);

function ui_check(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    echo "PASS: {$message}\n";
}

$index = file_get_contents(__DIR__ . '/../index.html');
$scriptPath = __DIR__ . '/../assets/campaign-automations.js';
$script = is_file($scriptPath) ? file_get_contents($scriptPath) : '';

echo "=== campaign automations UI ===\n";

ui_check(str_contains($index, 'data-view="campaignAutomations"'), '自動化ナビゲーションがある');
ui_check(str_contains($index, 'data-panel="campaignAutomations"'), '自動化パネルがある');
ui_check(str_contains($index, '自動送信しません'), 'draft-only安全表示がある');
ui_check(str_contains($index, 'assets/campaign-automations.js'), '独立したUI scriptを読み込む');
ui_check(!str_contains($index, 'onclick="campaignAutomation'), '自動化UIにinline handlerを使わない');
ui_check($script !== '', '自動化UI scriptが存在する');
ui_check(str_contains($script, "action: 'list'"), '一覧APIを利用する');
ui_check(str_contains($script, "action: 'preview'"), 'preview APIを利用する');
ui_check(str_contains($script, "action: 'generate_now'"), 'draft生成APIを利用する');
ui_check(str_contains($script, "roleAtLeast(State.user.role, 'operator')"), '動的操作をroleで制限する');
ui_check(str_contains($script, 'last_run_status'), '最終run結果を一覧表示する');
ui_check(str_contains($script, 'assignment_mode'), '従業員別rotation modeを設定する');
ui_check(str_contains($script, 'max_occurrences'), '実施回数を設定する');
ui_check(str_contains($script, 'completed_occurrences'), '完了回数を表示する');
ui_check(str_contains($script, 'syncAutomationAssignment'), 'rotation回数をcontent数へ制限する');
ui_check(str_contains($script, 'data-content-count'), '元campaignのcontent数をformへ渡す');
ui_check(str_contains($script, 'esc('), 'API由来文字列をescapeする');
ui_check(str_contains($script, 'confirm('), 'draft生成前に確認する');

$output = [];
$code = 0;
exec('node ' . escapeshellarg(__DIR__ . '/campaign_automations_frontend.mjs') . ' 2>&1', $output, $code);
ui_check($code === 0, '削除UIの動作検証: ' . implode("\n", $output));

echo "ALL TESTS PASSED\n";
