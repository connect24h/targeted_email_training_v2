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

// 生成段が派生列 resolved_* を書き換えても、設定変更ではないので launch を拒否しない（初回launch 409 バグの回帰）
Db::run('DELETE FROM send_schedule WHERE campaign_id=2');
Db::run('DELETE FROM campaign_template_snapshots WHERE campaign_id=2');
Db::run("UPDATE campaigns SET status='draft' WHERE id=2");
Db::run('UPDATE campaign_targets SET resolved_beacon_base=NULL, resolved_from_address=NULL WHERE campaign_id=2');
$revResolved = CampaignPreflight::inspect(2, 1)['revision'];
$resolvedLaunch = CampaignLaunchService::launch(2, 1, $revResolved, static function (): array {
    // PipelineRunner が生成中に行うのと同じ派生列の確定を模す
    Db::run("UPDATE campaign_targets SET resolved_beacon_base='https://beacon.example.test/', resolved_from_address='sender@example.test' WHERE campaign_id=2");
    return [true, ''];
});
check($resolvedLaunch['ok'] === true, '生成中の resolved_* 確定を設定変更とみなさず予約できる');

// --- 即時送信(immediate) ---
// draft に戻し、開始を遠い未来に設定しておく。即時送信で now+5分 付近へ差し替わるのを確認する。
Db::run('DELETE FROM send_schedule WHERE campaign_id=2');
Db::run('DELETE FROM campaign_template_snapshots WHERE campaign_id=2');
Db::run("UPDATE campaigns SET status='draft', start_at='2099-01-01 09:00:00', end_at='2099-01-02 18:00:00' WHERE id=2");
$insp = CampaignPreflight::inspect(2, 1);
$stable = $insp['stable_revision'];
$normal = $insp['revision'];
check(is_string($stable) && preg_match('/^[a-f0-9]{64}$/', $stable) === 1, 'inspect が stable_revision を返す');
check($stable !== $normal, 'stable_revision は通常 revision と異なる(日時を除いているため)');
// immediate=true で stable_revision を渡すと、start_at を差し替えても churn せず予約できる
$imm = CampaignLaunchService::launch(2, 1, $stable, static fn(): array => [true, ''], true);
check($imm['ok'] === true, '即時送信は stable_revision で churn せず予約できる');
$after = Db::one('SELECT status, start_at FROM campaigns WHERE id=2');
check($after['status'] === 'scheduled', '即時送信で scheduled へ');
$startTs = strtotime((string) $after['start_at']);
$nowTs = time();
check($startTs > $nowTs && $startTs <= $nowTs + 20 * 60, '即時送信で start_at が今から数分後へ差し替わる(2099年のままでない): ' . $after['start_at']);

// immediate=true に通常 revision(日時含む)を渡すと stable と一致せず 409 になる(誤用の検出)
Db::run('DELETE FROM send_schedule WHERE campaign_id=2');
Db::run('DELETE FROM campaign_template_snapshots WHERE campaign_id=2');
Db::run("UPDATE campaigns SET status='draft', start_at='2099-01-01 09:00:00', end_at='2099-01-02 18:00:00' WHERE id=2");
$insp2 = CampaignPreflight::inspect(2, 1);
$immWrong = CampaignLaunchService::launch(2, 1, $insp2['revision'], static fn(): array => [true, ''], true);
check($immWrong['ok'] === false && $immWrong['status'] === 409, '即時送信に通常 revision を渡すと 409(stable と不一致)');

echo "ALL TESTS PASSED\n";
