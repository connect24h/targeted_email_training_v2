<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

tet2_test_boot();
require_once __DIR__ . '/../lib/IntegrationAuth.php';
require_once __DIR__ . '/../lib/AwarenessTargetService.php';

echo "=== Awareness participant integration ===\n";

$authEnv = [
    'TET2_AWARENESS_TOKEN' => 'test-token-with-enough-entropy',
    'TET2_AWARENESS_TENANT_ID' => '1',
];

$shortTokenRejected = false;
try {
    IntegrationAuth::tenantId(
        ['HTTP_AUTHORIZATION' => 'Bearer short'],
        ['TET2_AWARENESS_TOKEN' => 'short', 'TET2_AWARENESS_TENANT_ID' => '1']
    );
} catch (IntegrationAuthException $error) {
    $shortTokenRejected = $error->httpCode === 503;
}
check($shortTokenRejected, '短すぎるintegration tokenは拒否する');

try {
    IntegrationAuth::tenantId([], $authEnv);
    check(false, 'tokenなしを拒否する');
} catch (IntegrationAuthException $error) {
    check($error->httpCode === 401, 'tokenなしを拒否する');
}

try {
    IntegrationAuth::tenantId(['HTTP_AUTHORIZATION' => 'Bearer wrong'], $authEnv);
    check(false, '不正tokenを拒否する');
} catch (IntegrationAuthException $error) {
    check($error->httpCode === 401, '不正tokenを拒否する');
}

check(
    IntegrationAuth::tenantId(['HTTP_AUTHORIZATION' => 'Bearer test-token-with-enough-entropy'], $authEnv) === 1,
    'tokenからtenantをserver側で固定する'
);

$service = new AwarenessTargetService(1);
$groupCreated = $service->upsertGroup(['name' => '共同グループ', 'kind' => 'custom'], 'group-create-1');
check($groupCreated['created'] === true, 'integration経由でgroupを作成する');
$groupReplay = $service->upsertGroup(['name' => '共同グループ', 'kind' => 'custom'], 'group-create-1');
check($groupReplay === $groupCreated, 'group作成もprocess永続の冪等性を持つ');
$groupArchived = $service->archiveGroup((int) $groupCreated['group']['id'], 'group-archive-1');
check($groupArchived['group']['status'] === 'archived', 'integration経由のgroup削除はarchiveする');
$groupSnapshot = $service->groupSnapshot();
check(count(array_filter(
    $groupSnapshot['groups'],
    static fn (array $group): bool => $group['id'] === $groupCreated['group']['id'] && $group['status'] === 'archived'
)) === 1, 'group snapshotはarchived groupも含む');

$created = $service->upsert([
    'email' => 'shared-user@example.test',
    'name' => '共有 利用者',
    'company' => 'Example社',
    'department' => '情報システム部',
    'title' => '担当',
    'positionCategory' => '社員',
    'groupIds' => [1],
], 'create-key-1');

check($created['created'] === true, '新規targetを作成する');
check($created['target']['tenantId'] === 1, 'responseのtenantは認証tenantに固定する');
check($created['target']['groups'][0]['id'] === 1, '同一tenant groupを設定する');
check($created['target']['positionCategory'] === '一般従業員', '旧称「社員」を正規カテゴリへ変換する');

$replayed = $service->upsert([
    'email' => 'shared-user@example.test',
    'name' => '共有 利用者',
    'company' => 'Example社',
    'department' => '情報システム部',
    'title' => '担当',
    'positionCategory' => '社員',
    'groupIds' => [1],
], 'create-key-1');
check($replayed === $created, '同一idempotency keyは保存済みresponseを返す');
check(
    (int) Db::one("SELECT COUNT(*) AS c FROM targets WHERE tenant_id=1 AND email='shared-user@example.test'")['c'] === 1,
    '再送でtargetを重複作成しない'
);

try {
    $service->upsert([
        'email' => 'different@example.test',
        'name' => '別利用者',
    ], 'create-key-1');
    check(false, '同じkeyの異なるrequestを拒否する');
} catch (IntegrationConflictException) {
    pass('同じkeyの異なるrequestを拒否する');
}

try {
    $service->upsert([
        'email' => 'cross-tenant@example.test',
        'name' => '越境',
        'groupIds' => [2],
    ], 'cross-tenant-key');
    check(false, '他tenant groupを拒否する');
} catch (IntegrationValidationException) {
    pass('他tenant groupを拒否する');
}

$testTargetId = Db::insert(
    "INSERT INTO targets (tenant_id,email,name,is_test,tenant_no) VALUES (1,?,?,1,999)",
    ['awareness-test-user@example.test', '連携除外テスト']
);
$snapshot = $service->snapshot(0, 100, null);
$snapshotTarget = array_values(array_filter(
    $snapshot['targets'],
    static fn (array $target): bool => $target['id'] === $created['target']['id']
))[0] ?? null;
check($snapshotTarget !== null, 'full snapshotに作成targetを含む');
check(strlen((string) $snapshotTarget['sourceVersion']) === 64, 'snapshotにSHA-256 sourceVersionを含む');
check(count(array_filter(
    $snapshot['targets'],
    static fn (array $target): bool => $target['id'] === $testTargetId
)) === 0, 'full snapshotはテストユーザを除外する');
check($snapshot['nextCursor'] === null, '最終pageはnext cursorなし');

$archived = $service->archive((int) $created['target']['id'], 'archive-key-1');
check($archived['target']['status'] === 'archived', 'targetを論理archiveする');

Db::run("INSERT INTO campaigns (tenant_id, name, status) VALUES (1, '同期結果', 'done')");
$campaignId = (int) Db::one("SELECT id FROM campaigns WHERE name='同期結果'")['id'];
Db::run(
    "INSERT INTO campaign_targets (campaign_id,target_id,tracking_id,content_no,send_status,sent_at)
     VALUES (?,?,?,?,?,?)",
    [$campaignId, (int) $created['target']['id'], 'SYNC000001', 1, 'sent', '2026-08-09 10:00:00']
);
Db::run(
    "INSERT INTO events (tenant_id,campaign_id,tracking_id,event_type,occurred_at,source)
     VALUES (?,?,?,?,?,?)",
    [1, $campaignId, 'SYNC000001', 'click', '2026-08-09 10:05:00', 'test']
);
$results = $service->phishingResults(0, 100);
$result = array_values(array_filter(
    $results['results'],
    static fn (array $row): bool => $row['trackingId'] === 'SYNC000001'
))[0] ?? null;
check($result !== null, 'phishing result snapshotを返す');
check($result['tenantId'] === 1 && $result['targetId'] === $created['target']['id'], '結果にstable target identityを含む');
check($result['linkClicked'] === true, 'eventを結果flagへ集約する');

Db::run(
    "INSERT INTO campaign_targets (campaign_id,target_id,tracking_id,content_no,send_status,sent_at)
     VALUES (?,?,?,?,?,?)",
    [$campaignId, $testTargetId, 'SYNC-TEST01', 1, 'sent', '2026-08-09 10:00:00']
);
$filteredResults = $service->phishingResults(0, 100);
check(count(array_filter(
    $filteredResults['results'],
    static fn (array $row): bool => $row['trackingId'] === 'SYNC-TEST01'
)) === 0, 'phishing result snapshotはテストユーザを除外する');

echo "ALL TESTS PASSED\n";
