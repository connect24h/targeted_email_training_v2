<?php
declare(strict_types=1);

require_once __DIR__ . '/MigrationRunner.php';

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
        echo "Usage: php migrate.php --db=/absolute/path.sqlite [--apply] [--allow-production]\n";
        echo "--applyなしはdry-runです。本番DBは--allow-productionも必要です。\n";
        exit(0);
    } else {
        fwrite(STDERR, "不明な引数です: {$argument}\n");
        exit(1);
    }
}

if ($dbPath === null || $dbPath === '') {
    fwrite(STDERR, "--db=/absolute/path.sqlite を指定してください\n");
    exit(1);
}

$productionPath = realpath('/opt/training/tet2-db/tet2.sqlite');
$requestedPath = realpath($dbPath);
if ($apply && $productionPath !== false && $requestedPath === $productionPath && !$allowProduction) {
    fwrite(STDERR, "本番DBへの適用には--allow-productionが必要です\n");
    exit(1);
}

try {
    $runner = new MigrationRunner($dbPath);
    $pending = $runner->pending();
    if ($pending === []) {
        echo "適用待ちmigrationはありません\n";
        exit(0);
    }
    echo '適用待ち: ' . implode(', ', $pending) . "\n";
    if (!$apply) {
        echo "[DRY-RUN] DBは変更していません\n";
        exit(0);
    }
    $applied = $runner->migrate();
    echo "適用完了: {$applied}件\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'migration失敗: ' . $error->getMessage() . "\n");
    exit(1);
}
