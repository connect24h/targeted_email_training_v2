<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
tet2_test_boot();
require_once __DIR__ . '/../lib/CampaignPreflight.php';
require_once __DIR__ . '/../lib/CampaignLaunchService.php';

$dataDir = sys_get_temp_dir() . '/tet2-launch-fixture-' . getmypid() . '-' . bin2hex(random_bytes(3));
mkdir($dataDir, 0700);
register_shutdown_function(static function () use ($dataDir): void {
    @unlink($dataDir . '/.launch.lock');
    @rmdir($dataDir);
});
$start = (new DateTimeImmutable('+1 day'))->format('Y-m-d 09:00:00');
$end = (new DateTimeImmutable('+2 days'))->format('Y-m-d 18:00:00');
Db::run("INSERT INTO templates (id, tenant_id, kind, name, content, auth_flag) VALUES (3, 1, 'phish_login', 'Fixture Form', '<form></form>', 1)");
Db::run('UPDATE campaigns SET from_address=?, send_mode=?, start_at=?, end_at=?, data_dir=? WHERE id=2',
    ['sender@example.test', 'normal', $start, $end, $dataDir]);
Db::run("INSERT INTO campaign_contents (campaign_id, content_no, subject_template_id, body_template_id, phish_template_id, link_mode) VALUES (2, 1, 1, 2, 3, 'link')");
Db::run('UPDATE campaign_targets SET koban=1 WHERE campaign_id=2');

$revision = CampaignPreflight::inspect(2, 1)['revision'];
$generated = false;
Db::run("UPDATE campaigns SET name='変更後' WHERE id=2");
$stale = CampaignLaunchService::launch(2, 1, $revision, static function () use (&$generated): array {
    $generated = true;
    return [true, ''];
});
check($stale['ok'] === false && $stale['status'] === 409 && !$generated, '確認後の編集を生成前に拒否');
check((int) Db::one('SELECT COUNT(*) AS n FROM send_schedule WHERE campaign_id=2')['n'] === 0, '拒否時にscheduleを作らない');

$revision = CampaignPreflight::inspect(2, 1)['revision'];
$changedDuringGeneration = CampaignLaunchService::launch(2, 1, $revision, static function (): array {
    Db::run("UPDATE templates SET content='changed during generation' WHERE id=2");
    return [true, ''];
});
check($changedDuringGeneration['ok'] === false && $changedDuringGeneration['status'] === 409, '生成中のテンプレート変更を拒否');
check(Db::one('SELECT status FROM campaigns WHERE id=2')['status'] === 'draft', '競合後もdraftを保持');
check((int) Db::one('SELECT COUNT(*) AS n FROM send_schedule WHERE campaign_id=2')['n'] === 0, '競合時の未実行scheduleを破棄');

$revision = CampaignPreflight::inspect(2, 1)['revision'];
$bodyAtLaunch = (string) Db::one('SELECT content FROM templates WHERE id=2')['content'];
$launched = CampaignLaunchService::launch(2, 1, $revision, static fn(): array => [true, '']);
check($launched['ok'] === true && $launched['batches'] === 1, '同じ確認値なら1バッチだけ予約');
check(Db::one('SELECT status FROM campaigns WHERE id=2')['status'] === 'scheduled', '確認成功後にscheduledへ公開');
check((int) Db::one('SELECT COUNT(*) AS n FROM campaign_template_snapshots WHERE campaign_id=2')['n'] === 3, '配信予約時に件名・本文・到達画面の版を固定');
$savedBody = Db::one("SELECT tenant_id, template_id, content FROM campaign_template_snapshots WHERE campaign_id=2 AND content_no=1 AND role='body'");
check((int) $savedBody['tenant_id'] === 1 && (int) $savedBody['template_id'] === 2, '版記録にtenantと元テンプレートを保持');
Db::run("UPDATE templates SET content='edited after launch' WHERE id=2");
check($savedBody['content'] === $bodyAtLaunch
    && Db::one("SELECT content FROM campaign_template_snapshots WHERE campaign_id=2 AND content_no=1 AND role='body'")['content'] === $bodyAtLaunch,
    '予約後のテンプレート編集で配信版を変更しない');
$repeat = CampaignLaunchService::launch(2, 1, $revision, static fn(): array => [true, '']);
check($repeat['ok'] === false && $repeat['status'] === 409, '二重launchを拒否');
check((int) Db::one('SELECT COUNT(*) AS n FROM campaign_template_snapshots WHERE campaign_id=2')['n'] === 3, '二重launchで版記録を増やさない');
Db::run('DELETE FROM send_schedule WHERE campaign_id=2');
Db::run("UPDATE campaigns SET status='draft' WHERE id=2");
$newRevision = CampaignPreflight::inspect(2, 1)['revision'];
$relaunched = CampaignLaunchService::launch(2, 1, $newRevision, static fn(): array => [true, '']);
check($relaunched['ok'] === true, '未送信の予約を下書きへ戻した後に再予約できる');
check((int) Db::one('SELECT COUNT(*) AS n FROM campaign_template_snapshots WHERE campaign_id=2')['n'] === 6,
    '再予約時も最初の配信版を消さずに記録する');
check(Db::one("SELECT content FROM campaign_template_snapshots WHERE campaign_id=2 AND launch_sequence=1 AND role='body'")['content'] === $bodyAtLaunch
    && Db::one("SELECT content FROM campaign_template_snapshots WHERE campaign_id=2 AND launch_sequence=2 AND role='body'")['content'] === 'edited after launch',
    '再予約した版を旧版と区別する');

echo "ALL TESTS PASSED\n";
