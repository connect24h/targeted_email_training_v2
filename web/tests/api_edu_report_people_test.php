<?php
declare(strict_types=1);

/**
 * 教育レポートの配信ごとの詳細(受講者ごと、部署ごと、受講者1人、配信一覧の合格率)と CSV の API テスト。
 * 期限内合格の決まり: 配信の deadline、なければ割当の token_expiry を期限にし、期限のない配信の合格はすべて期限内。
 */
require_once __DIR__ . '/helpers.php';
tet2_test_boot();
load_api('edu_report');

// ---- 合成データ(組織1。組織2は分離の確認用) ----
$target = static function (int $tenantId, string $name, string $email, ?string $department): int {
    Db::run('INSERT INTO targets (tenant_id, email, name, department) VALUES (?, ?, ?, ?)', [$tenantId, $email, $name, $department]);
    return (int) Db::one('SELECT MAX(id) AS id FROM targets')['id'];
};
$delivery = static function (int $tenantId, string $title, string $type, ?int $pass, ?string $deadline): int {
    Db::run("INSERT INTO edu_deliveries (tenant_id, title, status, delivery_type, pass_score, deadline) VALUES (?, ?, 'running', ?, ?, ?)",
        [$tenantId, $title, $type, $pass, $deadline]);
    return (int) Db::one('SELECT MAX(id) AS id FROM edu_deliveries')['id'];
};
$seq = 0;
/** @param list<array{0:int,1:string}> $attempts 提出した回(点数、提出日時) */
$assign = static function (int $tenantId, int $deliveryId, int $targetId, string $status, ?int $pct, ?string $completedAt,
    array $attempts = [], ?string $tokenExpiry = null) use (&$seq): int {
    $seq++;
    Db::run('INSERT INTO edu_assignments (tenant_id, delivery_id, target_id, access_token, status, completed_at, token_expiry) VALUES (?, ?, ?, ?, ?, ?, ?)',
        [$tenantId, $deliveryId, $targetId, str_pad((string) $seq, 32, 'a', STR_PAD_LEFT), $status, $completedAt, $tokenExpiry]);
    $aid = (int) Db::one('SELECT MAX(id) AS id FROM edu_assignments')['id'];
    if ($pct !== null) {
        Db::run('INSERT INTO edu_responses (tenant_id, assignment_id, percentage, completed_at) VALUES (?, ?, ?, ?)', [$tenantId, $aid, $pct, $completedAt]);
    }
    foreach ($attempts as $i => [$score, $at]) {
        Db::run('INSERT INTO edu_attempts (tenant_id, assignment_id, attempt_no, completed_at, percentage) VALUES (?, ?, ?, ?, ?)',
            [$tenantId, $aid, $i + 1, $at, $score]);
    }
    return $aid;
};

$a = $target(1, '営業 一郎', 'sales1@example.test', '営業部');
$b = $target(1, '営業 二郎', 'sales2@example.test', '営業部');
$c = $target(1, '総務 三郎', 'ga3@example.test', '総務部');
$d = $target(1, '総務 四郎', 'ga4@example.test', '総務部');
$e = $target(1, '=cmd|calc', 'plus@example.test', '開発,品質');
$f = $target(1, '部署なし 五郎', 'nodept@example.test', null);
$other = $target(2, '他組織 太郎', 'other@other.example.test', '営業部');

// 配信1: 合格点80、期限は日付だけ(その日の 23:59:59 まで)
$d1 = $delivery(1, '配信1 期限あり', 'elearning', 80, '2026-09-10');
$assign(1, $d1, $a, 'completed', 90, '2026-09-10 18:00:00', [[60, '2026-09-09 10:00:00'], [90, '2026-09-10 18:00:00']]);
$assign(1, $d1, $b, 'completed', 85, '2026-09-11 09:00:00', [[85, '2026-09-11 09:00:00']]);   // 期限の後に合格
$assign(1, $d1, $c, 'started', 50, '2026-09-08 09:00:00', [[50, '2026-09-08 09:00:00']]);     // 不合格で受講中に戻った
$assign(1, $d1, $d, 'assigned', null, null);
$assign(1, $d1, $e, 'completed', 100, '2026-09-01 09:00:00', [[100, '2026-09-01 09:00:00']]);
$assign(1, $d1, $f, 'expired', null, null);
// 配信2: 合格点80、配信の期限なし。割当の token_expiry があればそれを期限にする
$d2 = $delivery(1, '配信2 期限なし', 'elearning', 80, null);
$assign(1, $d2, $a, 'completed', 80, '2027-01-01 00:00:00', [[80, '2027-01-01 00:00:00']]);
$assign(1, $d2, $b, 'completed', 95, '2026-09-06 10:00:00', [[95, '2026-09-06 10:00:00']], '2026-09-05 12:00:00');
// 配信3: アウェアネス(合格点なし)
$d3 = $delivery(1, '配信3 アウェアネス', 'awareness_quiz', null, '2026-09-30');
$assign(1, $d3, $a, 'completed', 40, '2026-09-20 10:00:00', [[40, '2026-09-20 10:00:00']]);
// 組織2の配信
$dOther = $delivery(2, '他組織の配信', 'elearning', 80, null);
$assign(2, $dOther, $other, 'completed', 100, '2026-09-01 10:00:00', [[100, '2026-09-01 10:00:00']]);

// exit する CSV の経路は別のプロセスで、本物のサニタイザと一緒に実行する
if (in_array($argv[1] ?? '', ['--csv-people', '--csv-depts'], true)) {
    $bootstrap = (string) file_get_contents(__DIR__ . '/../lib/bootstrap.php');
    preg_match('/function tet2_csv_sanitize\(.*?\n\}/s', $bootstrap, $match);
    eval($match[0]);
    $_GET = ['id' => (string) $d1, 'format' => 'csv'];
    $GLOBALS['__TET2_TEST_ROLE'] = 'viewer';
    $argv[1] === '--csv-people' ? edu_rep_handle_delivery_people(current_user()) : edu_rep_handle_delivery_depts(current_user());
}
$runCsv = static function (string $mode): string {
    $proc = proc_open([PHP_BINARY, __FILE__, $mode], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    if ($code !== 0) {
        throw new RuntimeException("CSV の実行に失敗: $err");
    }
    return $out;
};
$byName = static fn(array $rows): array => array_column($rows, null, 'name');

// ---- delivery_people ----
$_GET = ['id' => (string) $d1];
$r = call_handler('edu_rep_handle_delivery_people', [], 'viewer');
check($r['code'] === 200, 'viewer が配信の受講者ごとを読める');
$people = $byName($r['payload']['people']);
check(count($people) === 6, '配信1の対象者6人を返す');
check($r['payload']['delivery']['deadline'] === '2026-09-10 23:59:59', '日付だけの期限はその日の 23:59:59');
check($people['営業 一郎']['attempt_count'] === 2 && $people['総務 四郎']['attempt_count'] === 0, '受講回数は提出した回を数える');
check($people['営業 一郎']['passed'] === true && $people['営業 一郎']['on_time'] === true, '期限の日の夕方の合格は期限内');
check($people['営業 二郎']['passed'] === true && $people['営業 二郎']['on_time'] === false, '期限の後の合格は期限内に数えない');
check($people['総務 三郎']['passed'] === false && $people['総務 三郎']['score'] === 50, '合格点に届かない人は不合格');
check($people['総務 四郎']['passed'] === false && $people['総務 四郎']['score'] === null, '未受講は点数なしで不合格');
check($people['部署なし 五郎']['department'] === '(未設定)', '部署が空の人は(未設定)');
$s = $r['payload']['summary'];
check($s['assigned'] === 6 && $s['completed'] === 3 && $s['incomplete'] === 3, '対象、完了、未完了を数える');
check($s['passed'] === 3 && $s['on_time_passed'] === 2, '合格3、期限内合格2');
check($s['pass_rate'] === 50.0 && $s['on_time_pass_rate'] === 33.3, '合格率と期限内合格率の分母は対象者');

$_GET = ['id' => (string) $d1, 'incomplete' => '1'];
$r = call_handler('edu_rep_handle_delivery_people', [], 'viewer');
$statuses = array_column($r['payload']['people'], 'status');
sort($statuses);
check($statuses === ['assigned', 'expired', 'started'], '未完了だけに絞ると完了の人を除く');
check(count($r['payload']['people']) === 3 && $r['payload']['summary']['assigned'] === 6, '絞っても集計は配信全体のまま');

$_GET = ['id' => (string) $d1, 'q' => '営業'];
$r = call_handler('edu_rep_handle_delivery_people', [], 'viewer');
check(array_column($r['payload']['people'], 'name') === ['営業 一郎', '営業 二郎'], '氏名の部分一致で絞れる');
$_GET = ['id' => (string) $d1, 'q' => 'GA4@'];
$r = call_handler('edu_rep_handle_delivery_people', [], 'viewer');
check(array_column($r['payload']['people'], 'name') === ['総務 四郎'], 'メールでも大文字小文字を問わず絞れる');

// 期限のない配信と token_expiry
$_GET = ['id' => (string) $d2];
$r = call_handler('edu_rep_handle_delivery_people', [], 'viewer');
$people = $byName($r['payload']['people']);
check($people['営業 一郎']['deadline'] === null && $people['営業 一郎']['on_time'] === true, '期限のない配信の合格は期限内に数える');
check($people['営業 二郎']['deadline'] === '2026-09-05 12:00:00' && $people['営業 二郎']['on_time'] === false,
    '配信の期限がなければ割当の token_expiry を期限にする');
check($r['payload']['summary']['on_time_pass_rate'] === 50.0, '期限なしの配信の期限内合格率');

// 合格点のない配信
$_GET = ['id' => (string) $d3];
$r = call_handler('edu_rep_handle_delivery_people', [], 'viewer');
check($r['payload']['people'][0]['passed'] === null && $r['payload']['summary']['pass_rate'] === null, 'アウェアネスは合否と合格率を出さない');

// ---- 分離と権限 ----
$_GET = ['id' => (string) $dOther];
check(call_handler('edu_rep_handle_delivery_people', [], 'viewer')['code'] === 404, 'ほかの組織の配信の受講者ごとは 404');
check(call_handler('edu_rep_handle_delivery_depts', [], 'viewer')['code'] === 404, 'ほかの組織の配信の部署ごとは 404');
$_GET = ['id' => (string) $d1, 'tenant_id' => '2'];
check(call_handler('edu_rep_handle_delivery_people', [], 'viewer')['code'] === 403, 'ほかの組織の tenant_id は拒否');
$_GET = [];
check(call_handler('edu_rep_handle_delivery_people', [], 'viewer')['code'] === 400, 'id がなければ 400');
$_GET = ['id' => (string) $d1, 'format' => 'xlsx'];
check(call_handler('edu_rep_handle_delivery_people', [], 'viewer')['code'] === 400, '知らない format は 400');

// ---- delivery_depts ----
$_GET = ['id' => (string) $d1];
$r = call_handler('edu_rep_handle_delivery_depts', [], 'viewer');
check($r['code'] === 200, 'viewer が部署ごとを読める');
$depts = array_column($r['payload']['departments'], null, 'department');
check(array_keys($depts) === ['(未設定)', '営業部', '総務部', '開発,品質'], '部署ごとに分け、部署名の順に並べる');
check($depts['営業部']['assigned'] === 2 && $depts['営業部']['completed'] === 2 && $depts['営業部']['passed'] === 2, '営業部の対象、完了、合格');
check($depts['営業部']['pass_rate'] === 100.0 && $depts['営業部']['on_time_pass_rate'] === 50.0, '営業部の合格率と期限内合格率');
check($depts['総務部']['incomplete'] === 2 && $depts['総務部']['pass_rate'] === 0.0, '総務部は未完了2、合格率0');

// ---- 配信一覧(deliveries)に足した集計 ----
$r = call_handler('edu_rep_handle_deliveries', [], 'viewer');
$list = array_column($r['payload']['deliveries'], null, 'id');
check(!isset($list[$dOther]), '配信一覧にほかの組織の配信を出さない');
check($list[$d1]['incomplete'] === 3 && $list[$d1]['pass_rate'] === 50.0 && $list[$d1]['on_time_pass_rate'] === 33.3,
    '配信一覧に未完了、合格率、期限内合格率を足す');
check($list[$d1]['deadline'] === '2026-09-10 23:59:59' && $list[$d2]['deadline'] === null, '配信一覧に期限を足す');
check($list[$d3]['pass_rate'] === null, '配信一覧でもアウェアネスの合格率は null');

// ---- learners / person ----
$_GET = ['q' => '総務'];
$r = call_handler('edu_rep_handle_learners', [], 'viewer');
check(array_column($r['payload']['learners'], 'name') === ['総務 三郎', '総務 四郎'], '受講者を氏名と部署で検索できる');
$_GET = ['q' => '%'];
$r = call_handler('edu_rep_handle_learners', [], 'viewer');
check($r['payload']['learners'] === [], '% は文字として扱う');
$_GET = [];
$r = call_handler('edu_rep_handle_learners', [], 'viewer');
check(!in_array('他組織 太郎', array_column($r['payload']['learners'], 'name'), true) && count($r['payload']['learners']) === 6,
    '受講者の一覧は自組織の割当のある人だけ');

$_GET = ['target_id' => (string) $a];
$r = call_handler('edu_rep_handle_person', [], 'viewer');
check($r['code'] === 200 && $r['payload']['person']['name'] === '営業 一郎', 'viewer が受講者1人の横断を読める');
$rows = array_column($r['payload']['deliveries'], null, 'delivery_id');
check(count($rows) === 3 && $rows[$d1]['attempt_count'] === 2 && $rows[$d3]['passed'] === null, '受講者1人の配信ごとの受講回数と合否');
$_GET = ['target_id' => (string) $other];
check(call_handler('edu_rep_handle_person', [], 'viewer')['code'] === 404, 'ほかの組織の受講者は 404');

// ---- CSV ----
$bootstrap = (string) file_get_contents(__DIR__ . '/../lib/bootstrap.php');
preg_match('/function tet2_csv_sanitize\(.*?\n\}/s', $bootstrap, $match);
eval($match[0]);
$csv = edu_rep_csv(['a'], [['-1+1'], ['@x'], ['+y'], ['ok "q"']]);
check(str_starts_with($csv, "\xEF\xBB\xBFa\r\n"), 'CSV は BOM で始まり、行末は CRLF');
check(str_contains($csv, "'-1+1\r\n'@x\r\n'+y\r\n\"ok \"\"q\"\"\"\r\n"), '先頭の - @ + を無害化し、引用符を二重にする');

$csv = $runCsv('--csv-people');
check(str_starts_with($csv, "\xEF\xBB\xBF状態,氏名,メール,部署,点数,合否,受講回数,期限,完了日時\r\n"), '受講者ごとの CSV は BOM と見出しの行で始まる');
$lines = explode("\r\n", rtrim(substr($csv, 3), "\r\n"));
check(count($lines) === 7, '受講者ごとの CSV は見出しと6人の行');
$rowE = array_values(array_filter(array_map(fn($l) => str_getcsv($l, ',', '"', ''), $lines), fn($row) => $row[2] === 'plus@example.test'))[0];
check($rowE[1] === "'=cmd|calc" && $rowE[3] === '開発,品質', '= で始まる氏名を無害化し、カンマを含む部署を引用する');
check(!str_contains(str_replace("\r\n", '', $csv), "\n"), 'CSV の行末はすべて CRLF');
$rowA = array_values(array_filter(array_map(fn($l) => str_getcsv($l, ',', '"', ''), $lines), fn($row) => $row[1] === '営業 一郎'))[0];
check($rowA === ['完了', '営業 一郎', 'sales1@example.test', '営業部', '90', '合格', '2', '2026-09-10 23:59:59', '2026-09-10 18:00:00'],
    '受講者ごとの CSV の列');
$rowD = array_values(array_filter(array_map(fn($l) => str_getcsv($l, ',', '"', ''), $lines), fn($row) => $row[1] === '総務 四郎'))[0];
check($rowD[4] === '' && $rowD[5] === '', '未受講の人は点数と合否が空');

$csv = $runCsv('--csv-depts');
check(str_starts_with($csv, "\xEF\xBB\xBF部署,対象,完了,未完了,合格,合格率(%),期限内合格率(%)\r\n"), '部署ごとの CSV は BOM と見出しの行で始まる');
check(str_contains($csv, "営業部,2,2,0,2,100,50\r\n") && str_contains($csv, "\"開発,品質\",1,1,0,1,100,100\r\n"), '部署ごとの CSV の行');

echo "ALL TESTS PASSED\n";
