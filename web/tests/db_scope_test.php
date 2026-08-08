<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
tet2_test_boot();

$tenantOne = Db::forTenant(1);
$tenantTwo = Db::forTenant(2);

$campaign = $tenantOne->find('campaigns', 2);
check($campaign !== null, 'tenant 1 can find campaign 2');

$otherTenantCampaign = $tenantTwo->find('campaigns', 2);
check($otherTenantCampaign === null, 'tenant 2 cannot find campaign 2');

$campaignCount = $tenantOne->count('campaigns');
check($campaignCount >= 1, 'tenant 1 campaign count is >= 1');

$openEvents = $tenantOne->all('events', 'event_type = ?', ['open']);
$onlyOpenEvents = array_reduce(
    $openEvents,
    fn(bool $ok, array $event): bool => $ok && $event['event_type'] === 'open',
    true
);
check($onlyOpenEvents, 'tenant 1 event query returns only open events');

try {
    $tenantOne->find('delivery_log', 1);
    throw new RuntimeException('FAIL: delivery_log is rejected');
} catch (InvalidArgumentException) {
    pass('delivery_log is rejected');
}

try {
    $tenantOne->update('campaigns', 2, ['id=1; DROP' => 'x']);
    throw new RuntimeException('FAIL: invalid update column is rejected');
} catch (InvalidArgumentException) {
    pass('invalid update column is rejected');
}

$sharedTemplate = Db::one('SELECT id FROM templates WHERE tenant_id IS NULL ORDER BY id LIMIT 1');
if ($sharedTemplate === null) {
    echo "SKIP: shared template row does not exist\n";
} else {
    $templateId = (int) $sharedTemplate['id'];
    check($tenantOne->findTemplate($templateId, true) !== null, 'shared template is visible when included');
    check($tenantTwo->findTemplate($templateId, false) === null, 'shared template is hidden without includeShared');
}

echo "ALL TESTS PASSED\n";
