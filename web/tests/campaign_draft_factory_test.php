<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

tet2_test_boot();
require_once __DIR__ . '/../lib/CampaignDraftFactory.php';

const FACTORY_TENANT_ID = 71;
const FACTORY_OTHER_TENANT_ID = 72;
const FACTORY_USER_ID = 7101;

Db::run(
    'INSERT INTO tenants (id, name, slug, data_dir) VALUES (?, ?, ?, ?)',
    [FACTORY_TENANT_ID, 'Factory Tenant', 'factory-tenant', '/opt/training/tet2-data/factory-tenant']
);
Db::run(
    'INSERT INTO tenants (id, name, slug, data_dir) VALUES (?, ?, ?, ?)',
    [FACTORY_OTHER_TENANT_ID, 'Other Factory Tenant', 'other-factory', '/opt/training/tet2-data/other-factory']
);
Db::run(
    'INSERT INTO users (id, tenant_id, email, password_hash, role) VALUES (?, ?, ?, ?, ?)',
    [FACTORY_USER_ID, FACTORY_TENANT_ID, 'factory@example.test', 'x', 'operator']
);

function factoryTemplate(string $kind, string $name, ?int $authFlag = null): int
{
    return Db::insert(
        'INSERT INTO templates (tenant_id, kind, name, content, auth_flag) VALUES (?, ?, ?, ?, ?)',
        [FACTORY_TENANT_ID, $kind, $name, $name . ' content', $authFlag]
    );
}

function factoryTarget(int $tenantId, string $email, string $status = 'active'): int
{
    return Db::insert(
        'INSERT INTO targets (tenant_id, email, name, status) VALUES (?, ?, ?, ?)',
        [$tenantId, $email, $email, $status]
    );
}

function factorySource(string $mode = 'distribute', bool $withContents = true): int
{
    $subjectId = factoryTemplate('subject', 'subject-' . uniqid('', true));
    $bodyId = factoryTemplate('body', 'body-' . uniqid('', true));
    $phishId = factoryTemplate('phish_login', 'phish-' . uniqid('', true), 1);
    $campaignId = Db::insert(
        'INSERT INTO campaigns
         (tenant_id, name, status, subject_template_id, body_template_id, phish_template_id,
          from_address, link_mode, send_mode, content_delivery, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [
            FACTORY_TENANT_ID, 'Factory Source', 'done', $subjectId, $bodyId, $phishId,
            'factory@example.test', 'link', 'normal', $mode, FACTORY_USER_ID,
        ]
    );
    if (!$withContents) {
        return $campaignId;
    }
    foreach ([1, 2] as $contentNo) {
        Db::run(
            'INSERT INTO campaign_contents
             (campaign_id, content_no, subject_template_id, body_template_id, phish_template_id,
              link_mode, from_address)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                $campaignId, $contentNo, $subjectId, $bodyId, $phishId,
                'link', "content{$contentNo}@example.test",
            ]
        );
    }
    return $campaignId;
}

function trackingSequence(int $start): callable
{
    $ids = [];
    for ($offset = 0; $offset < 10; $offset++) {
        $ids[] = str_pad((string) ($start + $offset), 10, '0', STR_PAD_LEFT);
    }
    return static function () use (&$ids): string {
        $next = array_shift($ids);
        if ($next === null) {
            throw new RuntimeException('tracking sequence exhausted');
        }
        return $next;
    };
}

function campaignCount(): int
{
    return (int) (Db::one('SELECT COUNT(*) AS n FROM campaigns')['n'] ?? 0);
}

function expectFactoryFailure(callable $operation, string $exceptionClass, string $message): void
{
    $before = campaignCount();
    try {
        $operation();
        throw new RuntimeException('期待した例外が発生しませんでした');
    } catch (Throwable $e) {
        check($e instanceof $exceptionClass, $message . ': domain例外');
    }
    check(campaignCount() === $before, $message . ': campaign途中行なし');
}

echo "=== CampaignDraftFactory ===\n";

$target1 = factoryTarget(FACTORY_TENANT_ID, 'factory-target-1@example.test');
$target2 = factoryTarget(FACTORY_TENANT_ID, 'factory-target-2@example.test');
$sourceId = factorySource();
$factory = new CampaignDraftFactory(trackingSequence(9901));
$draftId = $factory->createFromSource($sourceId, FACTORY_TENANT_ID, [
    'created_by' => FACTORY_USER_ID,
    'target_ids' => [$target1, $target2],
    'name' => 'Factory Draft',
]);

$draft = Db::one('SELECT * FROM campaigns WHERE id = ?', [$draftId]);
check($draft !== null && $draft['status'] === 'draft', '新規キャンペーンはdraft');
check($draft['name'] === 'Factory Draft', '指定名を使用する');
check($draft['start_at'] === null && $draft['end_at'] === null, '配信日時を引き継がない');
check(
    $draft['data_dir'] === '/opt/training/tet2-data/factory-tenant/campaign_' . $draftId,
    '新規ID専用のdata_dirを設定する'
);
$draftContents = Db::all(
    'SELECT content_no FROM campaign_contents WHERE campaign_id = ? ORDER BY content_no',
    [$draftId]
);
check(array_column($draftContents, 'content_no') === [1, 2], '全コンテンツをコピーする');
$draftTargets = Db::all(
    'SELECT target_id, tracking_id, content_no FROM campaign_targets WHERE campaign_id = ? ORDER BY koban',
    [$draftId]
);
check(count($draftTargets) === 2, 'distributeは対象者ごとに1行作る');
check(array_column($draftTargets, 'content_no') === [1, 2], 'distributeはround-robinで割り当てる');
check(array_column($draftTargets, 'tracking_id') === ['0000009901', '0000009902'], 'tracking IDの先頭ゼロを保持する');

$allSourceId = factorySource('all');
$allFactory = new CampaignDraftFactory(trackingSequence(9911));
$allDraftId = $allFactory->createFromSource($allSourceId, FACTORY_TENANT_ID, [
    'created_by' => FACTORY_USER_ID,
    'target_ids' => [$target1, $target2],
]);
$allTargets = Db::all(
    'SELECT target_id, tracking_id, content_no FROM campaign_targets WHERE campaign_id = ? ORDER BY target_id, content_no',
    [$allDraftId]
);
check(count($allTargets) === 4, 'allは対象者×全コンテンツの行を作る');
check(count(array_unique(array_column($allTargets, 'tracking_id'))) === 4, '全配信行のtracking IDが一意');
check(array_column($allTargets, 'content_no') === [1, 2, 1, 2], '各対象者へ全コンテンツを割り当てる');

$otherSource = Db::insert(
    'INSERT INTO campaigns (tenant_id, name, status) VALUES (?, ?, ?)',
    [FACTORY_OTHER_TENANT_ID, 'Other Source', 'done']
);
expectFactoryFailure(
    fn() => (new CampaignDraftFactory())->createFromSource($otherSource, FACTORY_TENANT_ID, [
        'created_by' => FACTORY_USER_ID,
        'target_ids' => [$target1],
    ]),
    CampaignDraftNotFoundException::class,
    '他テナントの元キャンペーンを拒否する'
);

$otherTarget = factoryTarget(FACTORY_OTHER_TENANT_ID, 'other-target@example.test');
expectFactoryFailure(
    fn() => (new CampaignDraftFactory())->createFromSource($sourceId, FACTORY_TENANT_ID, [
        'created_by' => FACTORY_USER_ID,
        'target_ids' => [$otherTarget],
    ]),
    CampaignDraftValidationException::class,
    '他テナントの対象者を拒否する'
);

$archivedTarget = factoryTarget(FACTORY_TENANT_ID, 'archived-target@example.test', 'archived');
expectFactoryFailure(
    fn() => (new CampaignDraftFactory())->createFromSource($sourceId, FACTORY_TENANT_ID, [
        'created_by' => FACTORY_USER_ID,
        'target_ids' => [$archivedTarget],
    ]),
    CampaignDraftValidationException::class,
    '非active対象者を拒否する'
);

$deletedSource = factorySource();
Db::run("UPDATE campaigns SET deleted_at = datetime('now') WHERE id = ?", [$deletedSource]);
expectFactoryFailure(
    fn() => (new CampaignDraftFactory())->createFromSource($deletedSource, FACTORY_TENANT_ID, [
        'created_by' => FACTORY_USER_ID,
        'target_ids' => [$target1],
    ]),
    CampaignDraftNotFoundException::class,
    '削除済み元キャンペーンを拒否する'
);

expectFactoryFailure(
    fn() => (new CampaignDraftFactory())->createFromSource($sourceId, FACTORY_TENANT_ID, [
        'created_by' => FACTORY_USER_ID,
        'target_ids' => [],
    ]),
    CampaignDraftValidationException::class,
    '対象者ゼロを拒否する'
);

$incompleteSource = factorySource('distribute', false);
expectFactoryFailure(
    fn() => (new CampaignDraftFactory())->createFromSource($incompleteSource, FACTORY_TENANT_ID, [
        'created_by' => FACTORY_USER_ID,
        'target_ids' => [$target1],
    ]),
    CampaignDraftValidationException::class,
    'コンテンツなしの元キャンペーンを拒否する'
);

echo "ALL TESTS PASSED\n";
