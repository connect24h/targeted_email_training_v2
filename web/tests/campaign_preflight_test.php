<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
tet2_test_boot();
require_once __DIR__ . '/../lib/CampaignPreflight.php';

$start = (new DateTimeImmutable('+1 day'))->format('Y-m-d 09:00:00');
$end = (new DateTimeImmutable('+2 days'))->format('Y-m-d 18:00:00');
Db::run("INSERT INTO templates (id, tenant_id, kind, name, content, auth_flag) VALUES (3, 1, 'phish_login', 'Fixture Form', '<form></form>', 1)");
Db::run("UPDATE campaigns SET from_address='sender@example.test', send_mode='normal', start_at=?, end_at=?, data_dir='/tmp/tet2-preflight-fixture' WHERE id=2", [$start, $end]);
Db::run("INSERT INTO campaign_contents (campaign_id, content_no, subject_template_id, body_template_id, phish_template_id, link_mode) VALUES (2, 1, 1, 2, 3, 'link')");

$ready = CampaignPreflight::inspect(2, 1);
check($ready['can_launch'] === true, '有効なdraftは開始可能');
check($ready['summary']['target_count'] === 1 && $ready['summary']['send_count'] === 1, '人物数と総通数を分けて表示');
check($ready['summary']['content_count'] === 1, 'コンテンツ数を表示');
check($ready['contents'][0]['subject_name'] === 'Shared Subject', '表示用シナリオを確認値と同じsnapshotから返す');
$revision = $ready['revision'];
Db::run("UPDATE campaign_contents SET link_mode='attachment', attachment_ext='html' WHERE campaign_id=2");
$withAttachment = CampaignPreflight::inspect(2, 1);
check($withAttachment['can_launch'] === true, '添付型の下書きも事前確認を通せる');
check(count(array_filter($withAttachment['warnings'], static fn(string $warning): bool => str_contains($warning, '添付/QR形式'))) === 1, '添付の生成・検証タイミングを事前警告');
Db::run("UPDATE campaign_contents SET link_mode='link', attachment_ext=NULL WHERE campaign_id=2");
Db::run("INSERT INTO templates (id, tenant_id, kind, name, content) VALUES (4, 2, 'subject', '他組織の秘密名', 'secret')");
Db::run('UPDATE campaign_contents SET subject_template_id=4 WHERE campaign_id=2');
$foreignTemplate = CampaignPreflight::inspect(2, 1);
check($foreignTemplate['can_launch'] === false, '他tenantのテンプレートで開始不可');
check($foreignTemplate['contents'][0]['subject_name'] === '利用できないテンプレート', '他tenantのテンプレート名を表示しない');
Db::run('UPDATE campaign_contents SET subject_template_id=1 WHERE campaign_id=2');

Db::run("UPDATE campaigns SET from_address='changed@example.test' WHERE id=2");
check(CampaignPreflight::inspect(2, 1)['revision'] !== $revision, '送信設定の変更で確認値を失効');
Db::run("UPDATE templates SET content='changed body' WHERE id=2");
check(CampaignPreflight::inspect(2, 1)['revision'] !== $revision, 'テンプレート変更で確認値を失効');

Db::run("UPDATE campaigns SET is_test=1, test_redirect_emails='one@example.test,two@example.test' WHERE id=2");
$testRun = CampaignPreflight::inspect(2, 1);
check($testRun['can_launch'] === true && count($testRun['summary']['test_distribution']) === 2, 'TEST宛先ごとの通数を表示');
check(array_sum(array_column($testRun['summary']['test_distribution'], 'count')) === 1, 'TEST通数は総通数と一致');
Db::run("UPDATE campaigns SET test_redirect_emails='' WHERE id=2");
check(CampaignPreflight::inspect(2, 1)['can_launch'] === false, '空のTEST宛先を拒否');
Db::run("UPDATE campaigns SET is_test=0 WHERE id=2");

Db::run("UPDATE targets SET status='archived' WHERE id=1");
check(CampaignPreflight::inspect(2, 1)['can_launch'] === false, '停止・退職者が含まれると拒否');
Db::run("UPDATE targets SET status='active' WHERE id=1");
Db::run("UPDATE campaigns SET status='scheduled' WHERE id=2");
check(CampaignPreflight::inspect(2, 1)['can_launch'] === false, '二重launchを拒否');
Db::run("UPDATE campaigns SET status='draft', end_at='2020-01-01 18:00:00' WHERE id=2");
check(CampaignPreflight::inspect(2, 1)['can_launch'] === false, '終了済み期間を拒否');
Db::run('DELETE FROM campaign_targets WHERE campaign_id=2');
check(CampaignPreflight::inspect(2, 1)['can_launch'] === false, '対象0人を拒否');

try {
    CampaignPreflight::inspect(3, 1);
    check(false, '他tenantを拒否');
} catch (CampaignPreflightNotFound $error) {
    check(true, '他tenantを404相当で拒否');
}

// --- デフォルト値の注意(2026-11-03追加) ---
// 状態を正常な draft に戻し、対象者を1名用意する。
Db::run("UPDATE campaigns SET status='draft', start_at=?, end_at=?, beacon_base=NULL, beacon_bases=NULL, attachment_filename=NULL WHERE id=2", [$start, $end]);
Db::run("UPDATE campaign_contents SET link_mode='link', beacon_base=NULL, beacon_bases=NULL, attachment_filename=NULL WHERE campaign_id=2");
$exists = Db::one('SELECT COUNT(*) AS n FROM campaign_targets WHERE campaign_id=2')['n'];
if ((int) $exists === 0) {
    Db::run("INSERT INTO campaign_targets (campaign_id, target_id, tracking_id, koban, send_status, content_no) VALUES (2, 1, 'pfbeacon01', 1, 'pending', 1)");
}
$hasDefaultWarn = static fn(array $r): bool => count(array_filter($r['warnings'], static fn(string $w): bool => str_contains($w, '既定のIP'))) > 0;
$hasKunrenWarn = static fn(array $r): bool => count(array_filter($r['warnings'], static fn(string $w): bool => str_contains($w, 'kunren'))) > 0;

// beacon 未設定 → 既定IPフォールバックの注意が出る
check($hasDefaultWarn(CampaignPreflight::inspect(2, 1)), 'ビーコン未設定(既定IPフォールバック)で注意を出す');
// 既定IPを明示 → 注意が出る
Db::run("UPDATE campaigns SET beacon_base='http://85.131.251.224/' WHERE id=2");
check($hasDefaultWarn(CampaignPreflight::inspect(2, 1)), '既定IPを明示しても注意を出す');
// 正式ドメイン → 注意は出ない
Db::run("UPDATE campaigns SET beacon_base='https://filesend.cojp.online/' WHERE id=2");
check(!$hasDefaultWarn(CampaignPreflight::inspect(2, 1)), '正式ドメインなら注意を出さない');

// 添付名の注意: link_mode=attachment かつ attachment_filename 未設定 → kunren 注意
Db::run("UPDATE campaign_contents SET link_mode='attachment', attachment_ext='html', attachment_filename=NULL WHERE campaign_id=2");
check($hasKunrenWarn(CampaignPreflight::inspect(2, 1)), '添付名未設定(既定kunren)で注意を出す');
// 添付名を設定 → kunren 注意は出ない
Db::run("UPDATE campaign_contents SET attachment_filename='案内状' WHERE campaign_id=2");
check(!$hasKunrenWarn(CampaignPreflight::inspect(2, 1)), '添付名を設定すれば注意を出さない');
// link 形式なら添付名未設定でも kunren 注意は出ない(添付を作らないため)
Db::run("UPDATE campaign_contents SET link_mode='link', attachment_ext=NULL, attachment_filename=NULL WHERE campaign_id=2");
check(!$hasKunrenWarn(CampaignPreflight::inspect(2, 1)), 'link形式は添付名未設定でもkunren注意を出さない');

echo "ALL TESTS PASSED\n";
