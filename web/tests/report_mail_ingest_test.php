<?php
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';
tet2_test_boot();
require_once __DIR__ . '/../lib/ReportMailIngest.php';

$root = __DIR__ . '/../../wp1b_test_' . bin2hex(random_bytes(5));
mkdir($root);
register_shutdown_function(static function () use ($root): void {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        if ($f->isDir() && !$f->isLink()) { rmdir($f->getPathname()); }
        else { unlink($f->getPathname()); }
    }
    rmdir($root);
});
function setupMail(): string {
    global $root;
    Db::run('DELETE FROM report_mails');
    Db::run('DELETE FROM events');
    Db::run('DELETE FROM campaign_targets WHERE id > 2');
    Db::run("UPDATE campaign_targets SET sent_at='2026-08-01 00:00:00'");
    Db::run("UPDATE campaigns SET deleted_at=NULL, from_address=NULL");
    $dir = $root . '/' . bin2hex(random_bytes(4));
    foreach (['new','cur','tmp'] as $sub) { mkdir($dir . '/' . $sub, 0700, true); }
    return $dir;
}
function mailRaw(string $from='target1@example.test', string $body='https://example.test/link-0000000001.html', string $extra="Message-ID: <outer@example.test>\r\n"): string {
    return "From: $from\r\nSubject: report\r\nDate: Tue, 01 Jan 2030 00:00:00 +0900\r\n$extra\r\n$body";
}
function deliver(string $dir, string $raw, string $name='1786752000.a', string $sub='new'): void {
    file_put_contents("$dir/$sub/$name", $raw);
}
function ingest(string $dir, array $opts=[]): array {
    return ReportMailIngest::run($opts + ['maildir'=>$dir, 'now'=>'2026-08-16 00:00:00']);
}
function matches(): array { return Db::all('SELECT * FROM report_mail_matches ORDER BY tracking_id'); }
function mailCount(): int { return (int) Db::one('SELECT count(*) n FROM report_mails')['n']; }

$d=setupMail(); deliver($d,mailRaw('TARGET1@example.test'));
$r=ingest($d); $e=Db::one("SELECT * FROM events WHERE event_type='report'");
check($r['report']===1 && matches()[0]['status']==='confirmed', 'ID + From一致で確定');
check($e['source']==='report_mail' && $e['occurred_at']===date('Y-m-d H:i:s',1786752000), '受信時刻はファイル名、sourceはreport_mail');
check(ingest($d)['report']===0 && mailCount()===1, '同じファイルの再取込は冪等');
deliver($d,mailRaw(), '1786752001.b','cur'); ingest($d);
check(mailCount()===1, '別ファイルの同一Message-IDも冪等');
$d=setupMail(); deliver($d,mailRaw('other@example.test')); $r=ingest($d);
check($r['report']===0 && $r['report_pending']===1 && matches()[0]['status']==='pending', 'From不一致はpending');
$d=setupMail(); deliver($d,mailRaw(body:'link-0000000001.html link-0000000002.html')); ingest($d);
check(array_column(matches(),'status')===['confirmed','pending'], '複数IDはFrom一致分のみ確定');
$d=setupMail(); deliver($d,mailRaw(body:'link-9999999999.html')); ingest($d);
check(count(matches())===1 && matches()[0]['status']==='rejected' && str_contains(matches()[0]['evidence'],'unknown'), '未知IDはrejected、senderへ落とさない');
$d=setupMail(); deliver($d,mailRaw(body:'please check')); ingest($d);
check(matches()[0]['method']==='sender' && matches()[0]['status']==='pending', 'IDなしで一意な差出人はpending');
Db::run('DELETE FROM report_mails');
Db::run("INSERT INTO campaign_targets(campaign_id,target_id,tracking_id,sent_at) VALUES(2,1,'0000000003','2026-08-02 00:00:00')"); ingest($d);
check(matches()===[] && Db::one('SELECT parse_status FROM report_mails')['parse_status']==='parsed', '同一テナントの複数候補はunmatched');
$d=setupMail(); deliver($d,mailRaw(body:'first',extra:'')); deliver($d,mailRaw(body:'second',extra:''),'1786752001.b'); ingest($d);
check(mailCount()===2, 'Message-ID欠落で異なる内容は別メール');
$d=setupMail(); Db::run("INSERT INTO events(tenant_id,campaign_id,tracking_id,event_type,occurred_at,source) VALUES(1,2,'0000000001','report','2026-08-01','fixture')");
$old=Db::one('SELECT id FROM events')['id']; deliver($d,mailRaw()); $r=ingest($d);
check($r['report']===0 && matches()[0]['status']==='confirmed' && matches()[0]['event_id']===$old, '既存reportを再利用');
foreach (['ignored_self','ignored_auto','ignored_oversize','parse_error'] as $status) {
    $d=setupMail();
    if ($status==='ignored_self') { Db::run("UPDATE campaigns SET from_address='TARGET1@example.test' WHERE id=3"); }
    $raw=match($status) {
        'ignored_auto'=>mailRaw(extra:"Message-ID: <auto@example.test>\r\nAuto-Submitted: auto-replied\r\n"),
        'ignored_oversize'=>mailRaw(body:str_repeat('x',2*1024*1024)),
        'parse_error'=>'broken', default=>mailRaw()
    };
    deliver($d,$raw); ingest($d);
    check(Db::one('SELECT parse_status FROM report_mails')['parse_status']===$status && matches()===[], $status . 'はmatchesなし');
}
$d=setupMail(); deliver($d,mailRaw()); chmod($d.'/new/1786752000.a',0000);
check(ingest($d,['readable'=>static fn(string $p):bool=>false])['skipped']===1 && mailCount()===0, '読めないファイルは未記録');
chmod($d.'/new/1786752000.a',0600); check(ingest($d)['report']===1, '次回再試行');
$d=setupMail(); deliver($d,mailRaw()); $r=ingest($d,['mode'=>'match_only']);
check($r['report']===0 && matches()[0]['status']==='pending' && Db::one('SELECT ingest_mode FROM report_mails')['ingest_mode']==='match_only', 'match_onlyはeventsなし');
$d=setupMail(); deliver($d,mailRaw()); deliver($d,mailRaw(extra:"Message-ID: <b@example.test>\r\n"),'1786752001.b','cur');
deliver($d,mailRaw(),'1786751999.tmp','tmp'); symlink($d.'/tmp/1786751999.tmp',$d.'/new/1786751998.link');
check(ingest($d,['max_files'=>1])['scanned']===1 && mailCount()===1, 'tmp/symlink除外、max_filesと受信順');
check(Db::one('SELECT maildir_file FROM report_mails')['maildir_file']===$d.'/new/1786752000.a', 'new/curをまとめて昇順');
check(ingest($d,['max_files'=>1])['scanned']===1 && mailCount()===2, '既読が上限を消費せず次のメールへ進む');
$d=setupMail(); deliver($d,mailRaw()); deliver($d,mailRaw(extra:"Message-ID: <next@example.test>\r\n"),'1786752001.b');
Db::run("CREATE TRIGGER fail_mail BEFORE INSERT ON report_mail_matches WHEN (SELECT message_id FROM report_mails WHERE id=NEW.report_mail_id)='<outer@example.test>' BEGIN SELECT RAISE(ABORT,'test failure'); END");
$r=ingest($d); check(mailCount()===1 && $r['report']===1, '途中失敗は当該メールのみ全ロールバックし次へ');
Db::run('DROP TRIGGER fail_mail'); ingest($d); check(mailCount()===2, 'ロールバックしたメールを次回再試行');


// 除外メールもMessage-ID・Date・受信時刻を保存する。
$d=setupMail();
$oversize=mailRaw(body:str_repeat('x',2*1024*1024), extra:"Message-ID: <large@example.test>\r\nReceived: from relay; Sat, 15 Aug 2026 12:00:00 +0900\r\n");
deliver($d,$oversize,'large.a'); ingest($d); $large=Db::one('SELECT * FROM report_mails');
check($large['parse_status']==='ignored_oversize' && $large['message_id_hash']===hash('sha256','<large@example.test>')
    && $large['content_hash']===hash('sha256',$oversize), 'サイズ超過でも外側Message-IDと全内容のhashを保存');
check($large['date_header']==='Tue, 01 Jan 2030 00:00:00 +0900'
    && $large['received_at']===date('Y-m-d H:i:s',strtotime('2026-08-15 12:00:00 +0900')), 'サイズ超過でもDate原値とReceived時刻を保存');
$reads=0; ingest($d,['readable'=>static function(string $p) use (&$reads):bool { $reads++; return true; }]);
check($reads===0 && file_get_contents($d.'/new/large.a')===$oversize, '既存パスは再読込せずMaildirの内容も維持');
$d=setupMail(); $raw=mailRaw(body:'no message id',extra:''); deliver($d,$raw); ingest($d);
check(Db::one('SELECT message_id_hash FROM report_mails')['message_id_hash']===hash('sha256','nomsgid:'.hash('sha256',$raw).':1786752000'), 'Message-ID欠落時のhash式を固定');
$d=setupMail(); deliver($d,mailRaw(),'1786752000.a','tmp');
rmdir($d.'/new'); symlink($d.'/tmp',$d.'/new');
check(ingest($d)['scanned']===0 && mailCount()===0, 'newディレクトリ自体がsymlinkなら走査しない');

// 受信時刻のフォールバック、原ヘッダ保存、判定の境界。
$d=setupMail();
deliver($d,mailRaw(extra:"Message-ID: <received@example.test>\r\nReceived: from relay by inbox; Sat, 15 Aug 2026 12:00:00 +0900\r\n"),'noepoch.a');
ingest($d); $m=Db::one('SELECT * FROM report_mails');
check($m['received_at']===date('Y-m-d H:i:s',strtotime('2026-08-15 12:00:00 +0900')) && $m['date_header']==='Tue, 01 Jan 2030 00:00:00 +0900', 'Receivedの時刻を採用、Dateは原値のみ保存');
$d=setupMail(); deliver($d,mailRaw(),'noepoch.b'); touch($d.'/new/noepoch.b',1786752000); ingest($d);
check(Db::one('SELECT received_at FROM report_mails')['received_at']===date('Y-m-d H:i:s',1786752000), 'Receivedもない場合はmtime');
foreach (['not_sent','campaign_deleted','target_tenant_mismatch'] as $reason) {
    $d=setupMail();
    if ($reason==='not_sent') { Db::run('UPDATE campaign_targets SET sent_at=NULL WHERE id=1'); }
    if ($reason==='campaign_deleted') { Db::run("UPDATE campaigns SET deleted_at='2026-08-01' WHERE id=2"); }
    if ($reason==='target_tenant_mismatch') { Db::run('UPDATE campaign_targets SET target_id=3 WHERE id=1'); }
    deliver($d,mailRaw($reason==='target_tenant_mismatch' ? 'target@other.example.test' : 'target1@example.test')); ingest($d);
    check(matches()[0]['status']==='pending' && str_contains(matches()[0]['evidence'],$reason), $reason.'はFromが一致してもpending');
    Db::run('UPDATE campaign_targets SET target_id=1 WHERE id=1');
}
foreach ([-1,0,60*86400,60*86400+1] as $offset) {
    $d=setupMail();
    $epoch=strtotime('2026-08-01 00:00:00')+$offset;
    deliver($d,mailRaw(body:'no ID'),$epoch.'.boundary'); ingest($d);
    check(count(matches())===(($offset>=0 && $offset<=60*86400) ? 1 : 0), '差出人照合60日境界: '.$offset);
}
$d=setupMail();
Db::run("UPDATE targets SET email='TARGET1@example.test' WHERE id=3");
deliver($d,mailRaw(body:'no ID')); ingest($d);
check(matches()===[], '全テナントに同じFromの候補があればunmatched');
Db::run("UPDATE targets SET email='target@other.example.test' WHERE id=3");
$d=setupMail(); Db::run("UPDATE targets SET status='archived' WHERE id=1");
deliver($d,mailRaw(body:'no ID')); ingest($d); check(matches()===[], '差出人照合はactive対象者のみ');
Db::run("UPDATE targets SET status='active' WHERE id=1");
$d=setupMail(); deliver($d,mailRaw(body:'forwarded',extra:"Message-ID: <reply@example.test>\r\nIn-Reply-To: <t0000000001.sent@example.test>\r\n")); ingest($d);
check(matches()[0]['method']==='msgid' && matches()[0]['status']==='confirmed', '返信ヘッダの回収IDでも照合');
$d=setupMail();
Db::run("INSERT INTO campaign_contents(campaign_id,content_no,from_address) VALUES(2,99,'TARGET1@example.test')");
deliver($d,mailRaw()); ingest($d);
check(Db::one('SELECT parse_status FROM report_mails')['parse_status']==='ignored_self', 'campaign_contentsの送信元も自己メールとして除外');
Db::run('DELETE FROM campaign_contents WHERE campaign_id=2 AND content_no=99');
$d=setupMail(); deliver($d,mailRaw());
putenv('TET2_REPORT_MAILDIR='.$d); putenv('TET2_REPORT_INGEST_MODE=match_only'); putenv('TET2_REPORT_INGEST_MAX_FILES=1');
check(ReportMailIngest::run()['report_pending']===1, '環境変数のMaildir/mode/max_filesを使用');
putenv('TET2_REPORT_INGEST_MODE'); putenv('TET2_REPORT_INGEST_MAX_FILES');
require_once __DIR__ . '/../lib/EventIngest.php';
$d=setupMail(); deliver($d,mailRaw()); putenv('TET2_REPORT_MAILDIR='.$d); putenv('TET2_APACHE_LOG_DIR='.$d.'/absent');
check(EventIngest::ingestAll()['report']===1, 'EventIngest経由でreportを返す');
$d=setupMail(); Db::run('DELETE FROM campaign_targets'); deliver($d,mailRaw()); putenv('TET2_REPORT_MAILDIR='.$d);
check(EventIngest::ingestAll()['report']===0 && matches()[0]['status']==='rejected', '対象マップが空でも報告の取込は実行');
check(ingest($d.'/absent')===['report'=>0,'report_pending'=>0,'scanned'=>0,'skipped'=>0], 'Maildir不在は4カウンタすべて0');
echo "ALL TESTS PASSED\n";
