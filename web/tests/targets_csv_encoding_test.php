<?php
declare(strict_types=1);

/**
 * ユーザ管理 CSV 取込の文字コード対応(2026-11-05)。
 * Excel で保存した CSV は日本語 Windows だと既定で Shift_JIS(CP932) になるため、
 * UTF-8 前提のまま取り込むと氏名・会社名・役職名が文字化けする。
 *  - Shift_JIS(CP932) の CSV を取り込んでも日本語が化けない
 *  - UTF-8(BOM あり/なし)はこれまでどおり化けずに通る(後方互換)
 *  - CP932 固有文字(㈱・① など)も正しく変換される
 */
require_once __DIR__ . '/helpers.php';
tet2_test_boot();
load_api('targets');

$tenantId = (int) current_user()['tenant_id'];
$row = static fn (string $email): ?array => Db::one('SELECT * FROM targets WHERE tenant_id = ? AND email = ?', [$tenantId, $email]);
$import = static fn (array $body, string $role = 'operator'): array => call_handler('targets_handle_import_csv', $body, $role);

$header = "社員番号,表示氏名（姓名）,メールアドレス（会社）,会社名,本務役職名称";

// ===== Shift_JIS(CP932) の CSV =====
$utf8 = $header . "\n" . "E-500,山田 太郎,sjis-taro@corp.test,㈱コープ商事,部長\n";
$sjis = mb_convert_encoding($utf8, 'CP932', 'UTF-8');
check($sjis !== $utf8, '前提: CP932 変換で UTF-8 とバイト列が変わる');
$r = $import(['csv' => $sjis]);
check($r['code'] === 200 && $r['payload']['imported'] === 1, 'SJIS: 1件取り込める');
$taro = $row('sjis-taro@corp.test');
check($taro !== null && $taro['name'] === '山田 太郎', 'SJIS: 氏名が化けずに UTF-8 で入る');
check($taro['company'] === '㈱コープ商事', 'SJIS: CP932 固有文字(㈱)も正しく変換される');
check($taro['title'] === '部長', 'SJIS: 役職名が化けない');

// ===== UTF-8(BOM なし) 後方互換 =====
$u1 = $header . "\n" . "E-501,鈴木 花子,utf8-hanako@corp.test,コープ商事,課長\n";
$r = $import(['csv' => $u1]);
check($r['code'] === 200 && $r['payload']['imported'] === 1, 'UTF-8: 1件取り込める');
$hanako = $row('utf8-hanako@corp.test');
check($hanako !== null && $hanako['name'] === '鈴木 花子' && $hanako['company'] === 'コープ商事',
    'UTF-8(BOM なし): これまでどおり化けない');

// ===== UTF-8(BOM あり) 後方互換 =====
$bom = "\xEF\xBB\xBF";
$u2 = $bom . $header . "\n" . "E-502,佐藤 次郎,utf8bom-jiro@corp.test,コープ商事,一般\n";
$r = $import(['csv' => $u2]);
check($r['code'] === 200 && $r['payload']['imported'] === 1, 'UTF-8+BOM: 1件取り込める');
$jiro = $row('utf8bom-jiro@corp.test');
check($jiro !== null && $jiro['name'] === '佐藤 次郎', 'UTF-8+BOM: BOM 除去され化けない');

echo "\n✅ targets_csv_encoding_test 完了\n";
