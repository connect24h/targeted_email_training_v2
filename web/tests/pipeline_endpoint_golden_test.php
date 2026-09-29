<?php
declare(strict_types=1);

/**
 * golden: 複数指定を使わないキャンペーンの list.csv / URL.csv が、
 * マルチエンドポイント導入の前後で1バイト一致であることを固定する。
 * 期待値は変更前コードで捕捉した出力(コンテンツ別の単数 beacon/from 上書きあり)。
 */

require_once __DIR__ . '/helpers.php';
tet2_test_boot();
require_once __DIR__ . '/../lib/PipelineRunner.php';

$dataDir = sys_get_temp_dir() . '/tet2-golden-' . getmypid();
@mkdir($dataDir, 0775, true);
register_shutdown_function(static function () use ($dataDir): void {
    foreach (glob($dataDir . '/*') ?: [] as $p) { @unlink($p); }
    @rmdir($dataDir);
});

Db::run("UPDATE campaigns SET data_dir=?, from_address='sender@example.test', beacon_base='https://beacon.example.test' WHERE id=2", [$dataDir]);
Db::run('DELETE FROM campaign_contents WHERE campaign_id=2');
Db::run('DELETE FROM campaign_targets WHERE campaign_id=2');
Db::run("INSERT INTO campaign_contents (campaign_id,content_no,subject_template_id,body_template_id,link_mode) VALUES (2,1,1,2,'link')");
Db::run("INSERT INTO campaign_contents (campaign_id,content_no,subject_template_id,body_template_id,link_mode,from_address,beacon_base) VALUES (2,2,1,2,'link','c2@example.test','https://c2.example.test')");
for ($i = 1; $i <= 6; $i++) {
    $tid = $i + 40;
    Db::run('INSERT OR IGNORE INTO targets (id,tenant_id,email,name,company,status) VALUES (?,1,?,?,?,?)',
        [$tid, "g{$i}@example.test", "氏名 {$i}", "会社{$i}", 'active']);
    $cno = ($i % 2) + 1;
    Db::run('INSERT INTO campaign_targets (campaign_id,target_id,tracking_id,koban,content_no,send_status) VALUES (2,?,?,?,?,?)',
        [$tid, sprintf('%010d', 1000000 + $i), $i, $cno, 'pending']);
}

PipelineRunner::generateCsv(2);

$expectedList = <<<CSV
項番,送信先情報,件名定型文No,本文定型文No,"本文差し込み1 #\$1\$#","本文差し込み2 #\$2\$#","本文差し込み3 #\$3\$#",認証フラグ,添付ファイル番号,送信元メールアドレス,苗字,表示氏名（姓名）,メールアドレス（会社）,会社名,略称,本務役職名称,役職カテゴリ,乱数列,送信フラグ,添付ファイル,メール空欄
1,g1@example.test,2,2,https://c2.example.test/link-0001000001.html,氏名,,0,,c2@example.test,氏名,"氏名 1",g1@example.test,会社1,,,,0001000001,,,0
2,g2@example.test,1,1,https://beacon.example.test/link-0001000002.html,氏名,,0,,sender@example.test,氏名,"氏名 2",g2@example.test,会社2,,,,0001000002,,,0
3,g3@example.test,2,2,https://c2.example.test/link-0001000003.html,氏名,,0,,c2@example.test,氏名,"氏名 3",g3@example.test,会社3,,,,0001000003,,,0
4,g4@example.test,1,1,https://beacon.example.test/link-0001000004.html,氏名,,0,,sender@example.test,氏名,"氏名 4",g4@example.test,会社4,,,,0001000004,,,0
5,g5@example.test,2,2,https://c2.example.test/link-0001000005.html,氏名,,0,,c2@example.test,氏名,"氏名 5",g5@example.test,会社5,,,,0001000005,,,0
6,g6@example.test,1,1,https://beacon.example.test/link-0001000006.html,氏名,,0,,sender@example.test,氏名,"氏名 6",g6@example.test,会社6,,,,0001000006,,,0

CSV;
$expectedUrl = "件名定型文No,URL\n1,https://beacon.example.test/\n2,https://c2.example.test/\n";

check(file_get_contents($dataDir . '/list.csv') === $expectedList, '複数未指定の list.csv が golden と1バイト一致');
check(file_get_contents($dataDir . '/URL.csv') === $expectedUrl, '複数未指定の URL.csv が golden と1バイト一致');

echo "ALL TESTS PASSED\n";
