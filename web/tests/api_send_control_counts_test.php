<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

tet2_test_boot();
load_api('send_control');

/**
 * send_control_counts は送信件数を DB の campaign_targets から集計する。
 * send_status.json のプロセス単位カウンタではなくキャンペーン全体の累計を
 * 返すことを保証する(2026-08-20 の 945 vs 1546 表示食い違いの回帰防止)。
 */
function seedCampaignWithTargets(int $sentCount, int $pendingCount): int
{
    $id = Db::insert(
        'INSERT INTO campaigns (tenant_id, name, status, from_address, link_mode, send_mode, content_delivery, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        [1, 'Counts Source', 'paused', 'counts@example.test', 'link', 'normal', 'distribute', 1]
    );
    // target_id は boot が用意する実在行(1..3)を循環使用し FK を満たす。
    // (campaign_id, target_id, content_no) の UNIQUE 制約は content_no を
    // 行ごとにユニークにして回避する(koban を流用)。集計対象は send_status のみ。
    $koban = 1;
    for ($i = 0; $i < $sentCount; $i++, $koban++) {
        Db::run(
            'INSERT INTO campaign_targets (campaign_id, target_id, tracking_id, koban, send_status, content_no)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$id, ($koban % 3) + 1, 'S' . $id . '_' . $koban, $koban, 'sent', $koban]
        );
    }
    for ($i = 0; $i < $pendingCount; $i++, $koban++) {
        Db::run(
            'INSERT INTO campaign_targets (campaign_id, target_id, tracking_id, koban, send_status, content_no)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$id, ($koban % 3) + 1, 'P' . $id . '_' . $koban, $koban, 'pending', $koban]
        );
    }
    return $id;
}

echo "=== send_control_counts (DB集計) ===\n";

// 分割送信の状況(sent 1546 相当を圧縮: sent 6 / pending 4)を模す。
$id = seedCampaignWithTargets(6, 4);
$counts = send_control_counts($id);
check($counts['total'] === 10, 'total は全 campaign_targets 件数(sent+pending)');
check($counts['sent'] === 6, 'sent は send_status=sent の件数(累計)');

// 全件送信済み。
$doneId = seedCampaignWithTargets(5, 0);
$counts = send_control_counts($doneId);
check($counts['total'] === 5 && $counts['sent'] === 5, '全送信済みは total=sent');

// 未送信のみ。
$freshId = seedCampaignWithTargets(0, 3);
$counts = send_control_counts($freshId);
check($counts['total'] === 3 && $counts['sent'] === 0, '未送信のみは sent=0');

// 対象者ゼロ(SUMがNULLになるケースの安全性)。
$emptyId = seedCampaignWithTargets(0, 0);
$counts = send_control_counts($emptyId);
check($counts['total'] === 0 && $counts['sent'] === 0, '対象ゼロでも 0/0 を返す(NULL安全)');

echo "ALL TESTS PASSED\n";

// ---- list.csv 起点のリアルタイム送信カウント(2026-08-21) ----
// 送信中は worker が list.csv の送信フラグを1件ごと立てる。画面はこれを数えて
// リアルタイム表示する。DB send_status は完了時一括同期のため送信中は遅れる。
echo "=== send_control_counts (list.csv リアルタイム) ===\n";

/** data_dir を作り list.csv を書く。sentFlags は各行の送信フラグ値の配列。 */
function makeListCsv(array $sentFlags): string
{
    $dir = sys_get_temp_dir() . '/tet2-lc-' . getmypid() . '-' . substr(md5((string) mt_rand()), 0, 8);
    @mkdir($dir, 0777, true);
    register_shutdown_function(function () use ($dir) {
        @unlink($dir . '/list.csv');
        @rmdir($dir);
    });
    $lines = ["乱数列,送信先情報,送信フラグ"];
    foreach ($sentFlags as $i => $flag) {
        $lines[] = sprintf('%010d,u%d@example.test,%s', $i + 1, $i, $flag);
    }
    file_put_contents($dir . '/list.csv', implode("\n", $lines) . "\n");
    return $dir;
}

// 送信中: list.csv で5行フラグ1, DB は送信フラグ未同期(sent=0)。sent は list.csv を正とし 5。
$midId = seedCampaignWithTargets(0, 10); // DB は全 pending
$midDir = makeListCsv(['1', '1', '1', '1', '1', '', '', '', '', '']);
$counts = send_control_counts($midId, $midDir);
check($counts['sent'] === 5, '送信中は list.csv の送信フラグ=1 の行数を sent とする(DB=0でも5)');
check($counts['total'] === 10, 'total は DB(campaign_targets)件数のまま');

// "1.0" 表記も送信済みとみなす(pandas 書き戻しの揺れ)。
$dotId = seedCampaignWithTargets(0, 3);
$dotDir = makeListCsv(['1.0', '1', '']);
$counts = send_control_counts($dotId, $dotDir);
check($counts['sent'] === 2, '"1.0" も送信済みとして数える');

// list.csv 不在 → DB フォールバック(従来動作)。
$fbId = seedCampaignWithTargets(4, 1);
$counts = send_control_counts($fbId, '/nonexistent/dir');
check($counts['sent'] === 4 && $counts['total'] === 5, 'list.csv 不在は DB 集計にフォールバック');

// 送信フラグ列が無い list.csv → DB フォールバック。
$noColId = seedCampaignWithTargets(2, 1);
$noColDir = sys_get_temp_dir() . '/tet2-nc-' . getmypid() . '-' . substr(md5((string) mt_rand()), 0, 8);
@mkdir($noColDir, 0777, true);
register_shutdown_function(function () use ($noColDir) {
    @unlink($noColDir . '/list.csv'); @rmdir($noColDir);
});
file_put_contents($noColDir . '/list.csv', "乱数列,送信先情報\n0000000001,a@example.test\n");
$counts = send_control_counts($noColId, $noColDir);
check($counts['sent'] === 2, '送信フラグ列なしは DB フォールバック');

// data_dir 未指定(後方互換) → DB 集計。
$compatId = seedCampaignWithTargets(3, 2);
$counts = send_control_counts($compatId);
check($counts['sent'] === 3 && $counts['total'] === 5, 'data_dir 未指定は従来通り DB 集計');

echo "ALL LISTCSV TESTS PASSED\n";
