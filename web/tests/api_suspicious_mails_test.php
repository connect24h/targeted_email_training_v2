<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
tet2_test_boot();
require_once __DIR__ . '/../lib/SuspiciousMailStore.php';
require_once __DIR__ . '/../lib/VirusTotalClient.php';
require_once __DIR__ . '/../lib/Secrets.php';
load_api('suspicious_mails');

$root = __DIR__ . '/../../sm_test_' . bin2hex(random_bytes(5));
mkdir($root);
putenv("TET2_SUSPICIOUS_BASE=$root");
putenv('TET2_SECRETS_FILE=/nonexistent/secrets.ini');
Secrets::reset();
register_shutdown_function(static function () use ($root): void {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) { $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname()); }
    rmdir($root);
});

function smFixture(string $name): string { return base64_encode(file_get_contents(__DIR__ . '/fixtures/eml/' . $name)); }
function smCall(string $handler, array $body = [], array $get = [], string $role = 'operator'): array
{
    $GLOBALS['__TET2_TEST_BODY'] = $body;
    $GLOBALS['__TET2_TEST_ROLE'] = $role;
    $_GET = $get;
    // 本番ではディスパッチ側の require_role が走る（load_api はそこを切り落とす）ので、ここで同じ判定を通す。
    // 2026-09-28 から、実際のメールを扱うので読み取りも operator 以上(本物の入口は admin_permissions_e2e.mjs で確かめる)
    $minRole = 'operator';
    try {
        $handler(require_role($minRole));
    } catch (Tet2TestExit $e) {
        return ['code' => $e->httpCode, 'data' => $e->payload];
    }
    throw new RuntimeException('handler did not exit');
}

// upload: 正常
$r = smCall('sm_handle_upload', ['filename' => 'fw.eml', 'file_base64' => smFixture('forwarded_rfc822.eml'), 'reporter_email' => 'reporter@corp.example']);
check($r['code'] === 201 && $r['data']['success'] === true && $r['data']['suggested_category'] === 'threat', 'upload: 201 と推奨分類 threat');
$id = (int) $r['data']['id'];
$row = Db::one('SELECT * FROM suspicious_mails WHERE id=?', [$id]);
check($row['tenant_id'] === 1 || (int) $row['tenant_id'] === 1, 'upload: 自テナント (1) に保存');
check($row['source'] === 'upload' && $row['uploaded_by'] === 'test@t' && $row['reporter_email'] === 'reporter@corp.example', 'upload: source / uploaded_by / reporter');
check($row['from_email'] === 'notice@bank-secure.test' && $row['subject'] === 'Your account is locked', 'upload: 判定対象（内側）の From と件名を保存');
check(is_file($row['raw_path']) && str_starts_with($row['raw_path'], $root . '/1/') && (fileperms($row['raw_path']) & 0777) === 0640, 'upload: raw を 0640 で保存: ' . $row['raw_path']);
check($row['category'] === 'undetermined' && $row['status'] === 'open' && $row['priority'] === 'normal', 'upload: 初期の分類/状態/優先度');
check($GLOBALS['__TET2_TEST_AUDIT'][0]['action'] === 'suspicious_mail.upload' && $GLOBALS['__TET2_TEST_CSRF_CALLS'] === 1, 'upload: audit と CSRF');

// upload: 重複は 409
$r = smCall('sm_handle_upload', ['filename' => 'fw.eml', 'file_base64' => smFixture('forwarded_rfc822.eml')]);
check($r['code'] === 409 && $r['data']['duplicate'] === true && $r['data']['id'] === $id, 'upload: 同一 sha256 は 409 で既存 id');

// upload: 入力検証
check(smCall('sm_handle_upload', ['filename' => 'a.msg', 'file_base64' => smFixture('benign.eml')])['code'] === 400, 'upload: .msg は 400');
check(smCall('sm_handle_upload', ['filename' => 'a.eml', 'file_base64' => '%%%'])['code'] === 400, 'upload: 不正 base64 は 400');
check(smCall('sm_handle_upload', ['filename' => 'a.eml', 'file_base64' => str_repeat('A', SM_MAX_BASE64_LEN + 1)])['code'] === 400, 'upload: 上限超過は 400');
check(smCall('sm_handle_upload', ['filename' => 'a.eml', 'file_base64' => base64_encode("not a mail at all\nno header")])['code'] === 400, 'upload: ヘッダー形でないものは 400');
check(smCall('sm_handle_upload', ['filename' => 'a.eml', 'file_base64' => smFixture('benign.eml'), 'reporter_email' => 'bad'])['code'] === 400, 'upload: 報告者アドレス不正は 400');
check(smCall('sm_handle_upload', ['filename' => 'a.eml', 'file_base64' => smFixture('benign.eml')], [], 'viewer')['code'] === 403, 'upload: viewer は 403');
check(smCall('sm_handle_upload', ['filename' => 'a.eml', 'file_base64' => smFixture('benign.eml'), 'tenant_id' => 2])['code'] === 403, 'upload: 他テナント指定は 403 (IDOR)');

// 訓練メール: campaign_targets の追跡 ID に一致 → training
$r = smCall('sm_handle_upload', ['filename' => 'tr.eml', 'file_base64' => smFixture('training_mail.eml')]);
check($r['code'] === 201 && $r['data']['is_training'] === 1 && $r['data']['suggested_category'] === 'training', 'upload: 訓練メールは training');
$trainingId = (int) $r['data']['id'];
check(Db::one('SELECT category, tracking_id FROM suspicious_mails WHERE id=?', [$trainingId]) == ['category' => 'training', 'tracking_id' => '0000000001'], '訓練メールは分類も training 固定');

// list
$r = smCall('sm_handle_list');
check($r['code'] === 200 && $r['data']['total'] === 2 && count($r['data']['rows']) === 2, 'list: 2 件');
check($r['data']['virustotal_configured'] === false, 'list: VirusTotal 未設定フラグ');
$r = smCall('sm_handle_list', [], ['category' => 'training']);
check($r['data']['total'] === 1 && $r['data']['rows'][0]['id'] === $trainingId, 'list: category フィルタ');
$r = smCall('sm_handle_list', [], ['q' => 'bank-secure']);
check($r['data']['total'] === 1 && $r['data']['rows'][0]['id'] === $id, 'list: キーワード検索');
check(smCall('sm_handle_list', [], ['status' => 'bogus'])['code'] === 400, 'list: 不正な status は 400');

// get
$r = smCall('sm_handle_get', [], ['id' => $id]);
$mail = $r['data']['mail'];
check($r['code'] === 200 && count($mail['analysis']['messages']) === 2 && $mail['findings'] !== [] && $mail['history'] === [], 'get: analysis / findings / history');
check(!isset($mail['analysis_json']) && !isset($mail['raw_path']), 'get: 内部列を出さない');
check($mail['reputation_targets'] === 1, 'get: 評判照会対象 1 件（URL）');
check(smCall('sm_handle_get', [], ['id' => 99999])['code'] === 404, 'get: 存在しない id は 404');

// update: 差分だけ履歴
$r = smCall('sm_handle_update', ['id' => $id, 'category' => 'threat', 'status' => 'in_progress', 'priority' => 'high', 'note' => '調査中']);
check($r['code'] === 200 && $r['data']['changed'] === ['category', 'status', 'priority', 'note'], 'update: 4 項目変更');
$hist = Db::all('SELECT field, old_value, new_value, actor_email FROM suspicious_mail_history WHERE suspicious_mail_id=? ORDER BY id', [$id]);
check(count($hist) === 4 && $hist[0] == ['field' => 'category', 'old_value' => 'undetermined', 'new_value' => 'threat', 'actor_email' => 'test@t'], 'update: 履歴 4 行');
$r = smCall('sm_handle_update', ['id' => $id, 'category' => 'threat']);
check($r['data']['changed'] === [] && count(Db::all('SELECT 1 FROM suspicious_mail_history WHERE suspicious_mail_id=?', [$id])) === 4, 'update: 変化なしは履歴を増やさない');
check(smCall('sm_handle_update', ['id' => $id, 'category' => 'bogus'])['code'] === 400, 'update: 不正な分類は 400');
check(smCall('sm_handle_update', ['id' => $id, 'status' => 'open'], [], 'viewer')['code'] === 403, 'update: viewer は 403');
$r = smCall('sm_handle_update', ['id' => $id, 'note' => null]);
check($r['data']['changed'] === ['note'] && Db::one('SELECT note FROM suspicious_mails WHERE id=?', [$id])['note'] === null, 'update: null でクリア');

// IDOR: 他テナントの行は get/update で 404
Db::run("UPDATE suspicious_mails SET tenant_id=2 WHERE id=?", [$trainingId]);
check(smCall('sm_handle_get', [], ['id' => $trainingId])['code'] === 404, 'IDOR: 他テナントの get は 404');
check(smCall('sm_handle_update', ['id' => $trainingId, 'status' => 'resolved'])['code'] === 404, 'IDOR: 他テナントの update は 404');
check(smCall('sm_handle_list')['data']['total'] === 1, 'IDOR: list に他テナントは出ない');
// superadmin はテナント指定なしで横断、tenant_id NULL の行も見える
Db::run("UPDATE suspicious_mails SET tenant_id=NULL WHERE id=?", [$trainingId]);
check(smCall('sm_handle_list', [], [], 'superadmin')['data']['total'] === 2, 'superadmin: 横断 list に NULL テナント行も出る');
check(smCall('sm_handle_get', [], ['id' => $trainingId], 'superadmin')['code'] === 200, 'superadmin: NULL テナント行を get できる');
check(smCall('sm_handle_get', [], ['id' => $trainingId, 'tenant_id' => '1'], 'superadmin')['code'] === 404, 'superadmin: tenant_id 指定時はそのテナントに絞る');

// reanalyze: 手動分類は保持、analyzer_version 更新
Db::run('UPDATE suspicious_mails SET score=0, findings_json=\'[]\', analyzer_version=0 WHERE id=?', [$id]);
$r = smCall('sm_handle_reanalyze', ['id' => $id]);
check($r['code'] === 200 && $r['data']['score'] > 0 && $r['data']['suggested_category'] === 'threat', 'reanalyze: 所見を再計算');
$row = Db::one('SELECT category, analyzer_version FROM suspicious_mails WHERE id=?', [$id]);
check($row['category'] === 'threat' && (int) $row['analyzer_version'] === SuspiciousMailAnalyzer::VERSION, 'reanalyze: 手動分類を保持し version 更新');

// reputation: キー未設定は 409
$r = smCall('sm_handle_reputation', ['id' => $id]);
check($r['code'] === 409 && $r['data']['code'] === 'virustotal_not_configured', 'reputation: キー未設定は 409');

// reputation: Store を直接、差し替えクライアントで（4 件上限・キャッシュ・所見反映）
$r = smCall('sm_handle_upload', ['filename' => 'att.eml', 'file_base64' => smFixture('attachment_exe_zip.eml')]);
$attId = (int) $r['data']['id'];
$responses = [
    ['code' => 200, 'body' => json_encode(['data' => ['attributes' => ['last_analysis_stats' => ['malicious' => 30, 'suspicious' => 0, 'harmless' => 0, 'undetected' => 40]]]])],
    ['code' => 404, 'body' => '{}'],
];
$calls = 0;
$client = new VirusTotalClient('k', static function () use (&$responses, &$calls): array { $calls++; return array_shift($responses) ?? ['code' => 429, 'body' => '']; });
$before = (int) Db::one('SELECT score FROM suspicious_mails WHERE id=?', [$attId])['score'];
$summary = SuspiciousMailStore::runReputation($attId, 1, $client, 4);
check($summary['done'] === 2 && $summary['remaining'] === 0 && $calls === 2, 'reputation: 添付 2 件を照会 (' . json_encode($summary) . ')');
$cache = Db::all('SELECT kind, found, malicious FROM reputation_cache ORDER BY id');
check(count($cache) === 2 && (int) $cache[0]['malicious'] === 30 && (int) $cache[1]['found'] === 0, 'reputation: キャッシュ 2 行（found / not_found）');
$row = Db::one('SELECT score, findings_json, reputation_checked_at FROM suspicious_mails WHERE id=?', [$attId]);
check((int) $row['score'] === $before + 20 && str_contains($row['findings_json'], 'vt_file_malicious') && str_contains($row['findings_json'], 'vt_unknown') && $row['reputation_checked_at'] !== null,
    'reputation: 所見に vt_file_malicious / vt_unknown が付き +20 点');
$summary = SuspiciousMailStore::runReputation($attId, 1, $client, 4);
check($summary['done'] === 0 && $calls === 2, 'reputation: キャッシュ済みは再照会しない');
Db::run("UPDATE reputation_cache SET fetched_at=datetime('now','-8 days')");
$summary = SuspiciousMailStore::runReputation($attId, 1, $client, 4);
check($summary['done'] === 0 && $summary['rate_limited'] === true && $calls === 3, 'reputation: TTL 切れは再照会し、429 で止まる');
$detail = SuspiciousMailStore::detail($attId, 1);
check(isset($detail['reputation']['file']) && count($detail['reputation']['file']) === 2, 'detail: 評判をキャッシュから返す');

// Maildir 連携: ReportMailIngest 経由で登録される（訓練メールは登録されない）
require_once __DIR__ . '/../lib/ReportMailIngest.php';
$maildir = $root . '/maildir';
foreach (['new', 'cur', 'tmp'] as $sub) { mkdir("$maildir/$sub", 0700, true); }
Db::run("UPDATE campaign_targets SET sent_at='2026-08-01 00:00:00'");
file_put_contents("$maildir/new/1786752000.a", file_get_contents(__DIR__ . '/fixtures/eml/forwarded_rfc822.eml'));
file_put_contents("$maildir/new/1786752001.b", "From: target1@example.test\r\nSubject: report\r\nMessage-ID: <t0000000001.x@example.test>\r\n\r\nhttps://example.test/link-0000000001.html");
$counts = ReportMailIngest::run(['maildir' => $maildir, 'now' => '2026-08-16 00:00:00', 'mode' => 'normal']);
check($counts['scanned'] === 2, 'maildir: 2 通を走査');
$fromMaildir = Db::all("SELECT * FROM suspicious_mails WHERE source='maildir' ORDER BY id");
check(count($fromMaildir) === 1 && $fromMaildir[0]['reporter_email'] === 'reporter@corp.example' && $fromMaildir[0]['tenant_id'] === null
    && $fromMaildir[0]['report_mail_id'] !== null, 'maildir: 訓練メールでない報告だけ suspicious_mails に登録（報告者が対象者でなければテナント未確定）');
check(str_starts_with($fromMaildir[0]['raw_path'], $root . '/unassigned/'), 'maildir: テナント未確定は unassigned に保存');
// 報告者が一意のテナントの対象者なら、そのテナントに割り当てる
file_put_contents("$maildir/new/1786752002.c", "From: target1@example.test\r\nSubject: odd mail\r\nMessage-ID: <odd@example.test>\r\n\r\nplease check http://192.0.2.9/x");
ReportMailIngest::run(['maildir' => $maildir, 'now' => '2026-08-16 00:00:00', 'mode' => 'normal']);
$assigned = Db::one("SELECT tenant_id, source FROM suspicious_mails WHERE reporter_email='target1@example.test'");
check($assigned !== null && (int) $assigned['tenant_id'] === 1, 'maildir: 対象者からの報告はそのテナントに割り当てる');

echo "api_suspicious_mails_test: OK\n";
