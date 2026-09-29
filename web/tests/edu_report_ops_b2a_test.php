<?php
declare(strict_types=1);

/**
 * 段B2 の教育レポートの追加(G58、G22、G61)。
 * - アウェアネスの受講者ごとの正解、不正解、未回答(配信か配信日の期間で絞る、CSV)
 * - 解答の1問1行の CSV(配信1件か、提出日の期間でテナント全体、上限で打ち切り)
 * - 自動の教育配信の実行履歴(誰を入れるかは変えずに、実行ごとに1行と監査ログ)
 * どれもテスト用と削除済みの対象者を除き、ほかのテナントの行を出さない。閲覧者が読める。
 */
require_once __DIR__ . '/helpers.php';
tet2_test_boot();
putenv('TET2_EDU_MAIL_DISABLE=1');
require_once __DIR__ . '/../lib/EduAnswerReport.php';
require_once __DIR__ . '/../lib/EduAutoEnrollRuns.php';
require_once __DIR__ . '/../lib/EduQuestionPicker.php';
require_once __DIR__ . '/../lib/EduMailer.php';
require_once __DIR__ . '/../lib/EduAutoEnroll.php';
require_once __DIR__ . '/../lib/EduScheduler.php';
load_api('edu_report');

// ---- 合成データ(組織1。組織2は分離の確認用) ----
$target = static function (int $tenantId, string $name, string $email, ?string $dept, array $o = []): int {
    return Db::insert('INSERT INTO targets (tenant_id, email, name, department, employee_no, is_test, status) VALUES (?, ?, ?, ?, ?, ?, ?)',
        [$tenantId, $email, $name, $dept, $o['no'] ?? null, $o['is_test'] ?? 0, $o['status'] ?? 'active']);
};
$a = $target(1, '=cmd|calc', 'aw-a@example.test', '営業部', ['no' => 'E-10']);
$b = $target(1, '受講 二郎', 'aw-b@example.test', null);
$t = $target(1, 'テスト 用', 'aw-test@example.test', '営業部', ['is_test' => 1]);
$x = $target(1, '退職 済', 'aw-gone@example.test', '営業部', ['status' => 'archived']);
$o = $target(2, '他組織 太郎', 'aw-o@other.example.test', '営業部');

$cat = Db::insert("INSERT INTO edu_categories (tenant_id, name, slug) VALUES (1, 'フィッシング', 'b2a-phish')");
$q = [];
foreach (['リンクを開く前に', '添付を開く前に', '送信者を確かめる'] as $i => $title) {
    $q[$i + 1] = Db::insert("INSERT INTO edu_questions (tenant_id, category_id, title, options, correct_answer) VALUES (1, ?, ?, '[\"開く\",\"報告する\"]', '[1]')",
        [$cat, $title]);
}
$delivery = static function (int $tenantId, string $title, string $type, ?string $scheduledAt, string $createdAt, array $questions): int {
    $id = Db::insert("INSERT INTO edu_deliveries (tenant_id, title, status, delivery_type, scheduled_at, created_at) VALUES (?, ?, 'running', ?, ?, ?)",
        [$tenantId, $title, $type, $scheduledAt, $createdAt]);
    foreach ($questions as $i => $qid) {
        Db::run('INSERT INTO edu_delivery_questions (delivery_id, question_id, sort_order) VALUES (?, ?, ?)', [$id, $qid, $i]);
    }
    return $id;
};
$seq = 0;
/** @param array<int,bool> $answers 設問 id => 正解か */
$take = static function (int $tenantId, int $deliveryId, int $targetId, ?string $completedAt, array $answers) use (&$seq): int {
    $seq++;
    $aid = Db::insert('INSERT INTO edu_assignments (tenant_id, delivery_id, target_id, access_token, status, completed_at) VALUES (?, ?, ?, ?, ?, ?)',
        [$tenantId, $deliveryId, $targetId, str_pad((string) $seq, 32, 'b', STR_PAD_LEFT), $completedAt === null ? 'assigned' : 'completed', $completedAt]);
    if ($completedAt !== null) {
        $rid = Db::insert('INSERT INTO edu_responses (tenant_id, assignment_id, percentage, completed_at) VALUES (?, ?, 50, ?)', [$tenantId, $aid, $completedAt]);
        foreach ($answers as $qid => $ok) {
            Db::run('INSERT INTO edu_response_answers (response_id, question_id, answer, is_correct) VALUES (?, ?, ?, ?)',
                [$rid, $qid, $ok ? '[1]' : '[0]', $ok ? 1 : 0]);
        }
    }
    return $aid;
};
$aw1 = $delivery(1, 'アウェアネス 9月', 'awareness_quiz', '2026-09-01T09:00', '2026-08-25 10:00:00', [$q[1], $q[2], $q[3]]);
$aw2 = $delivery(1, 'アウェアネス 10月', 'awareness_quiz', null, '2026-10-05 10:00:00', [$q[1], $q[2]]);
$el1 = $delivery(1, 'eラーニング', 'elearning', '2026-09-10 09:00', '2026-09-01 10:00:00', [$q[1]]);
$awOther = $delivery(2, '他組織のアウェアネス', 'awareness_quiz', '2026-09-01 09:00', '2026-09-01 09:00:00', [$q[1]]);
$aAw1 = $take(1, $aw1, $a, '2026-09-02 10:05:00', [$q[1] => true, $q[2] => false, $q[3] => true]);
Db::run("INSERT INTO edu_answer_locks (assignment_id, question_id, answer, is_correct, answered_at) VALUES (?, ?, '[1]', 1, '2026-09-02 10:01:00')", [$aAw1, $q[1]]);
$take(1, $aw1, $b, null, []);
$take(1, $aw1, $t, '2026-09-02 11:00:00', [$q[1] => true, $q[2] => true, $q[3] => true]);
$take(1, $aw1, $x, '2026-09-02 12:00:00', [$q[1] => true, $q[2] => true, $q[3] => true]);
$take(1, $aw2, $a, '2026-10-06 09:00:00', [$q[1] => true]);
$take(1, $aw2, $b, '2026-10-06 10:00:00', [$q[1] => false, $q[2] => false]);
$take(1, $el1, $a, '2026-09-11 09:00:00', [$q[1] => true]);
$take(2, $awOther, $o, '2026-09-02 09:00:00', [$q[1] => true]);

// exit する CSV の経路は別のプロセスで、本物の式の無害化(bootstrap)と一緒に実行する
$mode = $argv[1] ?? '';
if (str_starts_with($mode, '--csv-')) {
    preg_match('/function tet2_csv_sanitize\(.*?\n\}/s', (string) file_get_contents(__DIR__ . '/../lib/bootstrap.php'), $match);
    eval($match[0]);
    $GLOBALS['__TET2_TEST_ROLE'] = 'viewer';
    if ($mode === '--csv-awareness') {
        $_GET = ['format' => 'csv'];
        edu_rep_handle_awareness_people(current_user());
    }
    $_GET = $mode === '--csv-answers-delivery' ? ['id' => (string) $aw1] : ['from' => '2026-09-01', 'to' => '2026-10-31'];
    edu_rep_handle_answers(current_user());
}
$runCsv = static function (string $mode): string {
    $proc = proc_open([PHP_BINARY, __FILE__, $mode], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($proc) !== 0 || $err !== '') {
        throw new RuntimeException("CSV の子プロセスが失敗: {$err}");
    }
    return $out;
};
$csvLines = static fn(string $csv): array => array_values(array_filter(explode("\r\n", substr($csv, 3)), static fn(string $l): bool => $l !== ''));

// ---- G58 アウェアネスの受講者ごと ----
$_GET = [];
$r = call_handler('edu_rep_handle_awareness_people', [], 'viewer');
check($r['code'] === 200, 'G58: 閲覧者がアウェアネスの受講者ごとの成績を読める');
$people = array_column($r['payload']['people'], null, 'email');
check(count($people) === 2 && !isset($people['aw-test@example.test']) && !isset($people['aw-gone@example.test']) && !isset($people['aw-o@other.example.test']),
    'G58: テスト用、削除済み、ほかのテナントの人は出ない(実対象者の2人だけ)');
$pa = $people['aw-a@example.test'];
check([$pa['deliveries'], $pa['completed'], $pa['total'], $pa['correct'], $pa['incorrect'], $pa['unanswered'], $pa['correct_rate']] === [2, 2, 5, 3, 1, 1, 75.0],
    'G58: 配信を横断して、設問5、正解3、不正解1、未回答1(答えなかった設問)、正答率75%(eラーニングは入れない)');
$pb = $people['aw-b@example.test'];
check([$pb['deliveries'], $pb['completed'], $pb['total'], $pb['correct'], $pb['incorrect'], $pb['unanswered'], $pb['correct_rate']] === [2, 1, 5, 0, 2, 3, 0.0],
    'G58: 受講していない配信の設問は全部が未回答');
check($pb['department'] === '(未設定)' && $pa['employee_no'] === 'E-10', 'G58: 部署が空は(未設定)、従業員番号も出す');
check($r['payload']['summary'] === ['learners' => 2, 'total' => 10, 'correct' => 3, 'incorrect' => 3, 'unanswered' => 4], 'G58: 合計');

$_GET = ['delivery_id' => (string) $aw1];
$r = call_handler('edu_rep_handle_awareness_people', [], 'viewer');
$people = array_column($r['payload']['people'], null, 'email');
check($people['aw-b@example.test']['unanswered'] === 3 && $people['aw-b@example.test']['correct_rate'] === null
    && $people['aw-a@example.test']['total'] === 3, 'G58: 配信1件で絞る(答えていない人の正答率は null)');
$_GET = ['from' => '2026-10-01', 'to' => '2026-10-31'];
$r = call_handler('edu_rep_handle_awareness_people', [], 'viewer');
check(array_sum(array_column($r['payload']['people'], 'deliveries')) === 2 && $r['payload']['summary']['total'] === 4,
    'G58: 配信日の期間で絞る(予約の日時がなければ作った日時)');
foreach ([['delivery_id' => (string) $el1], ['delivery_id' => (string) $awOther], ['from' => '2026-13-01'], ['from' => '2026-10-02', 'to' => '2026-10-01']] as $bad) {
    $_GET = $bad;
    $r = call_handler('edu_rep_handle_awareness_people', [], 'viewer');
    check(in_array($r['code'], [400, 404], true), 'G58: 不正な絞り込みは拒む ' . json_encode($bad, JSON_UNESCAPED_UNICODE) . ' → ' . $r['code']);
}
$_GET = ['tenant_id' => '2'];
$r = call_handler('edu_rep_handle_awareness_people', [], 'viewer');
check($r['code'] === 403, 'G58: ほかのテナントを指定すると 403');

$csv = $runCsv('--csv-awareness');
$lines = $csvLines($csv);
check(str_starts_with($csv, "\xEF\xBB\xBF") && str_contains($csv, "\r\n"), 'G58: CSV は BOM と CRLF');
check($lines[0] === '氏名,メール,従業員番号,部署,配信,受講完了,設問,正解,不正解,未回答,正答率(%)' && count($lines) === 3, 'G58: CSV の見出しと行の数');
check(str_contains($csv, "'=cmd|calc,aw-a@example.test,E-10,営業部,2,2,5,3,1,1,75"), 'G58: CSV は先頭が = の値に \' を付ける');

// ---- G22 解答の1問1行 ----
$_GET = [];
$r = call_handler('edu_rep_handle_answers', [], 'viewer');
check($r['code'] === 400, 'G22: 配信も期間もなければ 400(テナント全体は期間が要る)');
$_GET = ['from' => '2026-09-01'];
$r = call_handler('edu_rep_handle_answers', [], 'viewer');
check($r['code'] === 400, 'G22: 期間は始まりと終わりの両方が要る');
$_GET = ['id' => (string) $awOther];
$r = call_handler('edu_rep_handle_answers', [], 'viewer');
check($r['code'] === 404, 'G22: ほかのテナントの配信は 404');

$csv = $runCsv('--csv-answers-delivery');
$lines = $csvLines($csv);
check($lines[0] === '配信日,配信,種類,氏名,メール,従業員番号,部署,カテゴリ,設問,解答,正誤,解答日時', 'G22: CSV の見出し');
check(count($lines) === 4, 'G22: 配信1件は、実対象者の提出した解答だけ(3問 × 1人。未受講、テスト用、削除済みは出ない)');
check(str_getcsv($lines[1], ',', '"', '') === ['2026-09-01 09:00', 'アウェアネス 9月', 'アウェアネス', "'=cmd|calc", 'aw-a@example.test', 'E-10', '営業部',
    'フィッシング', 'リンクを開く前に', '報告する', '正解', '2026-09-02 10:01:00'],
    'G22: 1行に配信日、設問、選択肢の文の解答、正誤、答え合わせの記録の日時');
check(array_slice(str_getcsv($lines[2], ',', '"', ''), 8) === ['添付を開く前に', '開く', '不正解', '2026-09-02 10:05:00'], 'G22: 設問ごとの記録がなければ提出の日時');
$csv = $runCsv('--csv-answers-period');
$lines = $csvLines($csv);
check(count($lines) === 1 + 3 + 1 + 2 + 1, 'G22: 期間(提出日)でテナント全体(アウェアネス2件とeラーニング、他組織は出ない)');
check(!str_contains($csv, 'aw-o@other.example.test') && !str_contains($csv, 'aw-test@example.test'), 'G22: 他組織とテスト用は出ない');
$capped = EduAnswerReport::answerRows(1, EDU_REP_REAL_TARGET_SQL, ['from' => '2026-09-01', 'to' => '2026-10-31'], 4);
check(count($capped['rows']) === 4 && $capped['truncated'] === true, 'G22: 上限を超えたら上限の行で打ち切り、打ち切ったことを返す');
$full = EduAnswerReport::answerRows(1, EDU_REP_REAL_TARGET_SQL, ['from' => '2026-09-01', 'to' => '2026-10-31'], 7);
check(count($full['rows']) === 7 && $full['truncated'] === false, 'G22: ちょうど上限なら打ち切りにしない');
check(EduAnswerReport::ANSWER_ROW_LIMIT === 50000, 'G22: 上限は 50000 行');
check(str_contains((string) file_get_contents(__DIR__ . '/../api/edu_report.php'), "'X-Tet2-Truncated'"), 'G22: 打ち切りは X-Tet2-Truncated ヘッダーで知らせる');

// ---- G61 自動の教育配信の実行履歴 ----
$campaign = Db::insert("INSERT INTO campaigns (tenant_id, name, status, created_by) VALUES (1, '訓練', 'done', 1)");
$clicker = $target(1, 'クリック 太郎', 'clicker@example.test', '営業部');
$testClicker = $target(1, 'テスト クリック', 'test-clicker@example.test', '営業部', ['is_test' => 1]);
foreach ([[$clicker, '0000000901'], [$testClicker, '0000000902']] as [$tid, $track]) {
    Db::run("INSERT INTO campaign_targets (campaign_id, target_id, tracking_id, content_no, send_status) VALUES (?, ?, ?, 1, 'sent')", [$campaign, $tid, $track]);
    Db::run("INSERT INTO events (tenant_id, campaign_id, tracking_id, event_type, occurred_at, source) VALUES (1, ?, ?, 'click', '2026-09-05 10:00:00', 'fixture')",
        [$campaign, $track]);
}
$trigger = Db::insert("INSERT INTO edu_deliveries (tenant_id, title, status, delivery_type, question_count, triggered_by, target_type, created_at)
    VALUES (1, '訓練の後の小問', 'running', 'awareness_quiz', 1, 'phishing_failure', 'risk', '2026-09-01 00:00:00')");
Db::run('INSERT INTO edu_delivery_questions (delivery_id, question_id) VALUES (?, ?)', [$trigger, $q[1]]);
$broken = Db::insert("INSERT INTO edu_deliveries (tenant_id, title, status, delivery_type, question_count, triggered_by, target_type, risk_results, created_at)
    VALUES (1, 'キャンペーンのない区分', 'running', 'awareness_quiz', 1, 'phishing_failure', 'risk', '[\"reported\"]', '2026-09-01 00:00:00')");

$first = EduAutoEnroll::run();
$assigned = array_map('intval', array_column(Db::all('SELECT target_id FROM edu_assignments WHERE delivery_id = ? ORDER BY target_id', [$trigger]), 'target_id'));
check($assigned === [$clicker] && $first['assigned'] === 1, 'G61: 投入する人は今までどおり(クリックした実対象者だけ、テスト用は入れない)');
$runs = Db::all('SELECT * FROM edu_auto_enroll_runs WHERE delivery_id = ? ORDER BY id', [$trigger]);
check(count($runs) === 1 && (int) $runs[0]['matched_count'] === 1 && (int) $runs[0]['enrolled_count'] === 1 && $runs[0]['error'] === null
    && $runs[0]['source'] === 'phishing_failure' && $runs[0]['finished_at'] >= $runs[0]['started_at'], 'G61: 実行を1行残す(当てはまった数、入れた数、開始と終了)');
EduAutoEnroll::run();
$runs = Db::all('SELECT * FROM edu_auto_enroll_runs WHERE delivery_id = ? ORDER BY id', [$trigger]);
check(count($runs) === 2 && (int) $runs[1]['matched_count'] === 1 && (int) $runs[1]['enrolled_count'] === 0, 'G61: 2回目は当てはまった1人、新しく入れた0人');
check((int) Db::one('SELECT COUNT(*) AS c FROM edu_assignments WHERE delivery_id = ?', [$trigger])['c'] === 1, 'G61: 2回目で割当は増えない(冪等のまま)');
$brokenRuns = Db::all('SELECT * FROM edu_auto_enroll_runs WHERE delivery_id = ?', [$broken]);
check(count($brokenRuns) === 2 && str_contains((string) $brokenRuns[0]['error'], 'キャンペーン') && (int) $brokenRuns[0]['enrolled_count'] === 0,
    'G61: 対象を決められない配信は、理由を実行の行に残す(投入はしない)');
$audits = Db::all("SELECT tenant_id, user_id, detail FROM audit_log WHERE action = 'edu_auto_enroll.run' ORDER BY id");
check(count($audits) === 3 && $audits[0]['user_id'] === null && str_contains((string) $audits[0]['detail'], 'delivery_id=' . $trigger . ',source=phishing_failure,matched=1,enrolled=1'),
    'G61: 監査ログは入れた実行と失敗した実行だけ(0件の実行は積まない)、user_id は NULL');

$newcomer = $target(1, '新人 花子', 'newcomer@example.test', '総務部');
Db::run("UPDATE targets SET created_at = '2020-01-01 00:00:00' WHERE id != ?", [$newcomer]);
Db::run("UPDATE targets SET created_at = '2026-09-28 09:00:00' WHERE id = ?", [$newcomer]);
$nt = Db::insert("INSERT INTO edu_deliveries (tenant_id, title, status, delivery_type, question_count, triggered_by, target_type, new_target_days)
    VALUES (1, '入社の小問', 'running', 'awareness_quiz', 1, 'new_target', 'all', 30)");
Db::run('INSERT INTO edu_delivery_questions (delivery_id, question_id) VALUES (?, ?)', [$nt, $q[1]]);
EduScheduler::enrollNewTargets(new DateTimeImmutable('2026-10-01 09:00:00', new DateTimeZone('Asia/Tokyo')));
$ntRuns = Db::all('SELECT * FROM edu_auto_enroll_runs WHERE delivery_id = ?', [$nt]);
$ntAssigned = array_map('intval', array_column(Db::all('SELECT target_id FROM edu_assignments WHERE delivery_id = ?', [$nt]), 'target_id'));
check($ntAssigned === [$newcomer] && count($ntRuns) === 1 && $ntRuns[0]['source'] === 'new_target'
    && (int) $ntRuns[0]['matched_count'] === 1 && (int) $ntRuns[0]['enrolled_count'] === 1, 'G61: 新入社員の投入も実行を残す(入れる人は今までどおり)');

$_GET = ['id' => (string) $trigger];
$r = call_handler('edu_rep_handle_auto_runs', [], 'viewer');
check($r['code'] === 200 && count($r['payload']['runs']) === 2 && $r['payload']['runs'][0]['enrolled_count'] === 0
    && $r['payload']['delivery']['triggered_by'] === 'phishing_failure', 'G61: 閲覧者が実行の一覧を新しい順に読める');
check(count($r['payload']['learners']) === 1 && $r['payload']['learners'][0]['email'] === 'clicker@example.test'
    && $r['payload']['learners'][0]['matched_at'] !== null, 'G61: 当てはまって入った人と入った日時');
Db::run("INSERT INTO edu_auto_enroll_runs (tenant_id, delivery_id, source, started_at, matched_count, enrolled_count)
    VALUES (2, ?, 'phishing_failure', '2026-09-01 00:00:00', 9, 9)", [$awOther]);
$_GET = ['id' => (string) $awOther];
$r = call_handler('edu_rep_handle_auto_runs', [], 'viewer');
check($r['code'] === 404, 'G61: ほかのテナントの配信の履歴は 404');

echo "ALL TESTS PASSED\n";
