<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
tet2_test_boot();
require_once __DIR__ . '/../lib/CampaignLauncher.php';

Db::run(
    "UPDATE campaigns
     SET send_mode='normal', start_at='2026-08-11 09:00:00', end_at='2026-08-11 18:00:00'
     WHERE id=2"
);
Db::run('UPDATE campaign_targets SET koban=1 WHERE campaign_id=2');

$scheduleWasHiddenDuringGeneration = false;
[$ok, $error, $batches] = CampaignLauncher::prepare(
    2,
    '/tmp/campaign_2',
    static function (int $campaignId, string $dataDir) use (&$scheduleWasHiddenDuringGeneration): array {
        $count = Db::one(
            'SELECT COUNT(*) AS count FROM send_schedule WHERE campaign_id=?',
            [$campaignId]
        );
        $scheduleWasHiddenDuringGeneration = (int) $count['count'] === 0;
        return [false, 'fixture generation failure'];
    }
);

check($scheduleWasHiddenDuringGeneration, '生成中は送信scheduleを公開しない');
check(!$ok && $batches === 0, '生成失敗をlaunch失敗として返す');
check($error === 'fixture generation failure', '生成失敗理由を保持する');
$count = Db::one('SELECT COUNT(*) AS count FROM send_schedule WHERE campaign_id=2');
check((int) $count['count'] === 0, '生成失敗後も送信scheduleを残さない');

[$ok, $error, $batches] = CampaignLauncher::prepare(
    2,
    '/tmp/campaign_2',
    static fn(int $campaignId, string $dataDir): array => [true, '']
);
check($ok && $error === '' && $batches === 1, '生成成功後にscheduleを作成する');
$schedule = Db::one('SELECT status FROM send_schedule WHERE campaign_id=2');
check(($schedule['status'] ?? '') === 'queued', '生成成功後のscheduleはqueued');

echo "ALL TESTS PASSED\n";
