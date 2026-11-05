<?php
declare(strict_types=1);

/**
 * report_handle_campaigns の include_deleted / test_filter の回帰テスト。
 *
 * 背景: ログ画面の絞り込みは action=campaigns で選択肢を作るが、既定(prod)では本番のみ、
 *   かつ deleted_at IS NULL で削除済みを除くため、テスト/削除済みキャンペーンが出なかった。
 * 修正: include_deleted=1 で削除済みも返す。test_filter=all でテストも返す。
 *   既定(include_deleted なし)は従来どおり削除済みを除く(ダッシュボード/レポートに影響させない)。
 */

require_once __DIR__ . '/helpers.php';
tet2_test_boot();
load_api('report');

$tenantId = current_user()['tenant_id'];

// 本番(生存) / テスト(生存) / テスト(削除済み) / 本番(削除済み) の4本。
$mk = static function (string $name, int $isTest, bool $deleted) use ($tenantId): int {
    Db::run('INSERT INTO campaigns (tenant_id, name, status, is_test, deleted_at) VALUES (?,?,?,?,?)',
        [$tenantId, $name, 'done', $isTest, $deleted ? '2026-11-01 00:00:00' : null]);
    return (int) Db::one('SELECT id FROM campaigns WHERE tenant_id=? AND name=? ORDER BY id DESC LIMIT 1',
        [$tenantId, $name])['id'];
};
$prodLive  = $mk('C_PROD_LIVE', 0, false);
$testLive  = $mk('C_TEST_LIVE', 1, false);
$testDel   = $mk('C_TEST_DEL', 1, true);
$prodDel   = $mk('C_PROD_DEL', 0, true);

$ids = static function (array $payload): array {
    return array_map(static fn ($c) => (int) $c['id'], $payload['campaigns'] ?? []);
};

// --- 既定(prod・削除済み除外): 本番生存のみ ---
$_GET = ['action' => 'campaigns'];
$r = call_handler('report_handle_campaigns', [], 'viewer');
$got = $ids($r['payload']);
check(in_array($prodLive, $got, true), '既定: 本番生存が出る');
check(!in_array($testLive, $got, true) && !in_array($testDel, $got, true) && !in_array($prodDel, $got, true),
    '既定: テスト・削除済みは出ない');

// --- test_filter=all（削除済みはまだ除外）: 本番生存+テスト生存 ---
$_GET = ['action' => 'campaigns', 'test_filter' => 'all'];
$r = call_handler('report_handle_campaigns', [], 'viewer');
$got = $ids($r['payload']);
check(in_array($prodLive, $got, true) && in_array($testLive, $got, true), 'all: 本番生存+テスト生存が出る');
check(!in_array($testDel, $got, true) && !in_array($prodDel, $got, true), 'all: 削除済みはまだ出ない');

// --- test_filter=all + include_deleted=1: 4本すべて ---
$_GET = ['action' => 'campaigns', 'test_filter' => 'all', 'include_deleted' => '1'];
$r = call_handler('report_handle_campaigns', [], 'viewer');
$got = $ids($r['payload']);
foreach ([$prodLive, $testLive, $testDel, $prodDel] as $id) {
    check(in_array($id, $got, true), "all+include_deleted: #{$id} が出る");
}
// 削除済みフラグが応答に載る(画面で[削除済]を付けるため)。
$byId = [];
foreach ($r['payload']['campaigns'] as $c) { $byId[(int) $c['id']] = $c; }
check(!empty($byId[$testDel]['deleted_at']), 'deleted_at が応答に載る(削除済み)');
check(empty($byId[$prodLive]['deleted_at']), 'deleted_at は生存キャンペーンでは空');

// --- include_deleted は 1 以外では効かない ---
$_GET = ['action' => 'campaigns', 'test_filter' => 'all', 'include_deleted' => '0'];
$r = call_handler('report_handle_campaigns', [], 'viewer');
$got = $ids($r['payload']);
check(!in_array($testDel, $got, true), 'include_deleted=0 は削除済みを出さない');

echo "\n✅ report_campaigns_include_deleted_test 完了\n";
