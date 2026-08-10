<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
tet2_test_boot();
require_once __DIR__ . '/../lib/CampaignLauncher.php';

/**
 * is_test=1 のとき本番アドレスへ絶対に送らないことを検証する。
 *
 * 送信script(send_email.py)は is_test を見ずCSVの「送信先情報」列をそのまま使う。
 * 生成時のリダイレクトだけでは、生成後にCSVが差し替わった場合や古い本番CSVが
 * 残っていた場合を止められないため、schedule公開前の最終関門をここで確認する。
 */

$dataDir = sys_get_temp_dir() . '/tet2-guard-' . getmypid();
@mkdir($dataDir, 0777, true);
register_shutdown_function(static function () use ($dataDir): void {
    foreach (glob($dataDir . '/*') ?: [] as $path) {
        if (is_file($path)) {
            @unlink($path);
        }
    }
    @rmdir($dataDir);
});

/** list.csv を「送信先情報」列付きで書く。 */
$writeCsv = static function (string $dir, array $recipients): void {
    $fh = fopen($dir . '/list.csv', 'w');
    fputcsv($fh, ['項番', '送信先情報', '乱数列']);
    foreach ($recipients as $i => $to) {
        fputcsv($fh, [$i + 1, $to, sprintf('%010d', $i + 1)]);
    }
    fclose($fh);
};

$allowed = 'tester1@example.test,tester2@example.test';

// --- ケース1: 全宛先がテスト宛先 → 通す ---
Db::run(
    'UPDATE campaigns SET is_test=1, test_redirect_emails=? WHERE id=2',
    [$allowed]
);
$writeCsv($dataDir, ['tester1@example.test', 'tester2@example.test', 'tester1@example.test']);
[$ok, $err] = CampaignLauncher::assertTestRecipientsSafe(2, $dataDir);
check($ok === true, 'テスト宛先だけなら通過する');

// --- ケース2: 本番アドレスが1件混入 → 止める ---
$writeCsv($dataDir, ['tester1@example.test', 'honban@example.co.jp', 'tester2@example.test']);
[$ok, $err] = CampaignLauncher::assertTestRecipientsSafe(2, $dataDir);
check($ok === false, '本番アドレスが1件でも混ざれば中止する');
check(str_contains($err, '3行目'), '混入した行番号を返す: ' . $err);
check(!str_contains($err, 'honban@example.co.jp'), 'エラーに実アドレスを晒さない');

// --- ケース3: 大文字小文字・前後空白は同一視 ---
$writeCsv($dataDir, [' TESTER1@Example.Test ', 'tester2@example.test']);
[$ok, $err] = CampaignLauncher::assertTestRecipientsSafe(2, $dataDir);
check($ok === true, '大文字小文字と前後空白を正規化して比較する');

// --- ケース4: テスト宛先が未設定 → 止める ---
Db::run("UPDATE campaigns SET is_test=1, test_redirect_emails='' WHERE id=2");
$writeCsv($dataDir, ['tester1@example.test']);
[$ok, $err] = CampaignLauncher::assertTestRecipientsSafe(2, $dataDir);
check($ok === false, 'テスト宛先が未設定なら中止する');

// --- ケース5: CSV が存在しない → 止める ---
Db::run('UPDATE campaigns SET is_test=1, test_redirect_emails=? WHERE id=2', [$allowed]);
@unlink($dataDir . '/list.csv');
[$ok, $err] = CampaignLauncher::assertTestRecipientsSafe(2, $dataDir);
check($ok === false, 'list.csv が無ければ中止する');

// --- ケース6: is_test=0 は対象外（本番送信を妨げない） ---
Db::run('UPDATE campaigns SET is_test=0 WHERE id=2');
$writeCsv($dataDir, ['honban@example.co.jp']);
[$ok, $err] = CampaignLauncher::assertTestRecipientsSafe(2, $dataDir);
check($ok === true, 'is_test=0 の本番送信はこの関門では止めない');

echo "ALL TESTS PASSED\n";
