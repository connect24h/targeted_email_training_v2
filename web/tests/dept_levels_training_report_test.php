<?php
declare(strict_types=1);

/**
 * 訓練のレポートの部署ごとの表(C3、G14)。report.php?action=departments&dept_level=0|1|2。
 *
 * - 既存の詳細(action=detail)の出力は C3 の前と同じ(部署の表は別の action で足した)。
 *   比べる相手の fixtures/dept_levels_training_golden.json は、C3 の前のコード(02cbef3)で
 *   `php dept_levels_training_report_test.php --emit-defaults` を実行して作った(generated_at は時刻なので除く)。
 * - 部署の表は、会社別と同じ数え方(対象数は届かない宛先を除く、表示と認証と報告は1人1回)で部署ごとに数え、
 *   1段目と2段目では件数を足してから率を計算し直す。
 * 合成データは fixtures/dept_levels_seed.php。
 */
require_once __DIR__ . '/helpers.php';
tet2_test_boot();
load_api('report');
require_once __DIR__ . '/fixtures/dept_levels_seed.php';
$seed = dept_levels_seed();
$campaign = (string) $seed['campaign'];

$json = static function (string $handler, array $query): array {
    $_GET = $query;
    $r = call_handler($handler, [], 'viewer');
    if ($r['code'] !== 200) {
        throw new RuntimeException("$handler が {$r['code']} を返した: " . json_encode($r['payload'], JSON_UNESCAPED_UNICODE));
    }
    return $r['payload'];
};
$enc = static fn(array $payload): string => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
$defaults = static function (array $extra) use ($json, $enc, $campaign): array {
    $out = [];
    foreach (['detail' => [], 'detail_test' => ['test_filter' => 'test'], 'detail_all' => ['test_filter' => 'all']] as $key => $q) {
        $d = $json('report_handle_detail', ['campaign_id' => $campaign] + $q + $extra);
        unset($d['generated_at']);
        $out[$key] = $enc($d);
    }
    return $out;
};
if (($argv[1] ?? '') === '--emit-defaults') {
    echo json_encode($defaults([]), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
    exit(0);
}

// ---- 既存の詳細は C3 の前と同じ(dept_level を付けても変わらない) ----
$golden = json_decode((string) file_get_contents(__DIR__ . '/fixtures/dept_levels_training_golden.json'), true);
foreach ([[], ['dept_level' => '1']] as $extra) {
    $now = $defaults($extra);
    foreach ($golden as $key => $before) {
        check($now[$key] === $before, ($extra === [] ? '' : 'dept_level=1 でも ') . "{$key} は C3 の前の出力と同じ");
    }
}

// ---- 部署ごとの表 ----
$rows = static function (array $query) use ($json, $campaign): array {
    $d = $json('report_handle_departments', ['campaign_id' => $campaign] + $query);
    return array_map(static fn(array $r): array => [$r['department'], $r['count'], $r['link_clicked'], $r['auth_count'], $r['report_count'],
        $r['link_rate'], $r['auth_rate'], $r['report_rate']], $d['departments']);
};
check($rows([]) === [
    ['(未設定)', 0, 0, 0, 0, 0.0, 0.0, 0.0],
    ['/営業本部//東日本営業部/', 1, 1, 1, 0, 100.0, 100.0, 0.0],
    ['営業本部 / 東日本営業部 / 第2課', 1, 0, 0, 1, 0.0, 0.0, 100.0],
    ['営業本部/東日本営業部/第1課', 2, 2, 1, 0, 100.0, 50.0, 0.0],
    ['営業本部／西日本営業部', 1, 1, 0, 0, 100.0, 0.0, 0.0],
    ['管理本部', 1, 0, 0, 0, 0.0, 0.0, 0.0],
    ['管理本部/総務部', 2, 2, 0, 1, 100.0, 0.0, 50.0],
], '全部(既定): 部署の文字列ごと。届かない宛先は対象数に入れず、テスト用は除き、削除済みは会社別と同じく数える');
check($rows(['dept_level' => '1']) === [
    ['(未設定)', 0, 0, 0, 0, 0.0, 0.0, 0.0],
    ['営業本部', 5, 4, 2, 1, 80.0, 50.0, 20.0],
    ['管理本部', 3, 2, 0, 1, 66.67, 0.0, 33.33],
], '1段目: 件数を足して率を計算し直す(認証率の分母は表示の数)');
check($rows(['dept_level' => '2']) === [
    ['(未設定)', 0, 0, 0, 0, 0.0, 0.0, 0.0],
    ['営業本部 / 東日本営業部', 4, 3, 2, 1, 75.0, 66.67, 25.0],
    ['営業本部 / 西日本営業部', 1, 1, 0, 0, 100.0, 0.0, 0.0],
    ['管理本部', 1, 0, 0, 0, 0.0, 0.0, 0.0],
    ['管理本部 / 総務部', 2, 2, 0, 1, 100.0, 0.0, 50.0],
], '2段目');
check($rows(['dept_level' => '1', 'test_filter' => 'test']) === [['営業本部', 1, 1, 1, 0, 100.0, 100.0, 0.0]],
    'テストの対象者だけ(test_filter=test)も同じ条件で数える');
$d = $json('report_handle_departments', ['campaign_id' => $campaign]);
check($d['has_hierarchy'] === true && $d['max_depth'] === 3 && $d['dept_level'] === 0, '階層の有無と、使った段を返す');

// ---- 入力の検査とテナントの分離 ----
$_GET = ['campaign_id' => $campaign, 'dept_level' => '9'];
check(call_handler('report_handle_departments', [], 'viewer')['code'] === 400, 'dept_level=9 は 400');
$_GET = [];
check(call_handler('report_handle_departments', [], 'viewer')['code'] === 400, 'campaign_id がなければ 400');
$other = Db::insert("INSERT INTO campaigns (tenant_id, name, status, created_by) VALUES (2, '他組織', 'done', 1)");
$_GET = ['campaign_id' => (string) $other];
check(call_handler('report_handle_departments', [], 'viewer')['code'] === 404, '他組織のキャンペーンは 404');
Db::run("UPDATE campaigns SET closed_at = '2026-09-20 00:00:00' WHERE id = ?", [$seed['campaign']]);
$_GET = ['campaign_id' => $campaign, 'test_filter' => 'all'];
check(call_handler('report_handle_departments', [], 'viewer')['code'] === 409, 'クローズ済みは詳細と同じく本番の対象者の全期間だけ');

echo "ALL TESTS PASSED\n";
