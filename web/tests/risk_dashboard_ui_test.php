<?php
declare(strict_types=1);

function risk_ui_check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException("FAIL: {$message}");
    }
    echo "PASS: {$message}\n";
}

$index = (string) file_get_contents(__DIR__ . '/../index.html');
risk_ui_check(str_contains($index, 'data-view="riskDashboard"'), 'リスク画面へのナビゲーションがある');
risk_ui_check(str_contains($index, 'data-panel="riskDashboard"'), 'リスク画面パネルがある');
risk_ui_check(str_contains($index, 'id="riskDashboardRoot"'), '描画先がある');
risk_ui_check(str_contains($index, 'assets/risk-dashboard.js'), 'リスク画面アセットを読み込む');

$command = 'node ' . escapeshellarg(__DIR__ . '/risk_dashboard_frontend.mjs') . ' 2>&1';
exec($command, $output, $code);
risk_ui_check($code === 0, "frontend behavior test\n" . implode("\n", $output));

echo "ALL TESTS PASSED\n";
