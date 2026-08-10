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
$script = file_get_contents(__DIR__ . '/../app.js');
$educationApi = file_get_contents(__DIR__ . '/../api/edu_deliveries.php');

$usersStart = strpos($index, '<section data-panel="users"');
$tenantsStart = strpos($index, '<section data-panel="tenants"');
$usersPanel = $usersStart !== false && $tenantsStart !== false
    ? substr($index, $usersStart, $tenantsStart - $usersStart)
    : '';

echo "=== unified user management UI ===\n";

ui_check(substr_count($index, 'data-view="users"') === 1, 'ユーザ管理メニューが一つだけある');
ui_check(!str_contains($index, 'data-view="targets"'), '重複する対象者メニューがない');
ui_check(!str_contains($index, 'data-panel="targets"'), '重複する対象者パネルがない');
ui_check($usersPanel !== '', 'ユーザ管理パネルがある');
ui_check(str_contains($usersPanel, '受講者・訓練対象者'), '共通人物マスターのタブがある');
ui_check(str_contains($usersPanel, '管理画面ユーザ'), '管理画面アカウントのタブがある');
ui_check(str_contains($usersPanel, 'id="targetsBody"'), '対象者一覧がユーザ管理内にある');
ui_check(str_contains($usersPanel, 'id="newTargetBtn"'), '対象者登録がユーザ管理内にある');
ui_check(str_contains($usersPanel, 'id="adminUsersPane"') && str_contains($usersPanel, 'data-role="tenant_admin"'), '管理画面アカウントを組織管理者以上に制限する');
ui_check(!str_contains($script, 'targets: renderTargets'), '対象者を独立viewとして公開しない');
ui_check(str_contains($script, 'await renderTargets();'), 'ユーザ管理表示時に共通人物マスターを取得する');
ui_check(str_contains($script, "roleAtLeast(State.user.role, 'tenant_admin')"), '管理画面アカウントAPIを権限で制限する');
ui_check(str_contains($educationApi, 'function edu_d_resolve_targets'), '教育配信の対象者解決処理がある');
ui_check(str_contains($educationApi, 'FROM targets'), '教育配信が標的型訓練と同じtargetsを使う');

echo "ALL TESTS PASSED\n";
