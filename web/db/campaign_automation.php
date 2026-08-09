<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI専用です\n");
    exit(1);
}

$dbPath = null;
$apply = false;
$allowProduction = false;
foreach (array_slice($_SERVER['argv'], 1) as $argument) {
    if (str_starts_with($argument, '--db=')) {
        $dbPath = substr($argument, 5);
    } elseif ($argument === '--apply') {
        $apply = true;
    } elseif ($argument === '--allow-production') {
        $allowProduction = true;
    } elseif ($argument === '--help') {
        echo "Usage: php campaign_automation.php [--db=/absolute/path.sqlite] [--apply] [--allow-production]\n";
        echo "--applyなしはdry-runです。本番DBへの実行は--allow-productionも必要です。\n";
        exit(0);
    } else {
        fwrite(STDERR, "不明な引数です: {$argument}\n");
        exit(1);
    }
}

if ($dbPath !== null) {
    if (!str_starts_with($dbPath, DIRECTORY_SEPARATOR) || realpath($dbPath) === false) {
        fwrite(STDERR, "--dbには存在する絶対pathを指定してください\n");
        exit(1);
    }
    putenv('TET2_DB_PATH=' . realpath($dbPath));
}

$productionPath = realpath('/opt/training/tet2-db/tet2.sqlite');
$requestedPath = realpath($dbPath ?? '/opt/training/tet2-db/tet2.sqlite');
if ($apply && $productionPath !== false && $requestedPath === $productionPath && !$allowProduction) {
    fwrite(STDERR, "本番DBへの実行には--allow-productionが必要です\n");
    exit(1);
}

require_once __DIR__ . '/../lib/CampaignAutomationRunner.php';

try {
    $runner = new CampaignAutomationRunner();
    $due = $runner->dueCount();
    if (!$apply) {
        echo "[DRY-RUN] due rules={$due} / DBは変更していません\n";
        exit(0);
    }
    $result = $runner->runDue();
    echo sprintf(
        "automation: examined=%d generated=%d failed=%d duplicate=%d\n",
        $result['examined'], $result['generated'], $result['failed'], $result['duplicate']
    );
    exit($result['failed'] > 0 ? 1 : 0);
} catch (Throwable $error) {
    fwrite(STDERR, 'automation runner failed: ' . get_class($error) . "\n");
    exit(1);
}
