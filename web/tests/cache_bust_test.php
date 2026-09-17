<?php
declare(strict_types=1);

// キャッシュバスティングの整合性を検証する。
// index.html のローカルアセット参照が ?v=<content-hash> 付きで、かつその値が
// 現在のファイル内容の sha256 先頭8桁と一致することを確認する。
//
// これが落ちたら: アセット(app.js 等)を変更したのに deploy/tet2-cache-bust.sh を
// 実行し忘れている。実行すれば直る。ブラウザキャッシュ由来の初期化崩れ
// (2026-08-16 の bootstrap is not defined 事故)の再発防止ガード。

function cache_bust_check(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    echo "PASS: {$message}\n";
}

$webDir = __DIR__ . '/..';
$index = (string) file_get_contents($webDir . '/index.html');

echo "=== cache-bust integrity ===\n";

// tet2-cache-bust.sh の ASSETS と一致させること。
$assets = ['app.js', 'assets/app.css', 'assets/campaign-automations.js', 'assets/positions.js', 'assets/risk-dashboard.js', 'assets/suspicious-mails.js'];

foreach ($assets as $rel) {
    $path = $webDir . '/' . $rel;
    cache_bust_check(is_file($path), "アセットが存在する: {$rel}");
    $expected = substr(hash('sha256', (string) file_get_contents($path)), 0, 8);

    // index.html がそのアセットを参照しているなら、?v=<hash> 付きであること。
    if (!preg_match('#(?:src|href)="' . preg_quote($rel, '#') . '(\?v=([0-9a-f]+))?"#', $index, $m)) {
        // 参照していないアセットは対象外(take.php 等が持つ場合がある)
        echo "SKIP: index.html は {$rel} を参照していない\n";
        continue;
    }
    cache_bust_check(isset($m[2]) && $m[2] !== '', "?v= が付いている: {$rel}");
    cache_bust_check(
        $m[2] === $expected,
        "?v= が現在の内容と一致する: {$rel} (期待 {$expected}, 実際 " . ($m[2] ?? '(なし)') . ") — 不一致なら deploy/tet2-cache-bust.sh を実行"
    );
}

echo "ALL TESTS PASSED\n";
