<?php
declare(strict_types=1);

/**
 * 対象者の従業員番号とメモ(段B2 の G46)。画面の編集、一覧の検索、CSV の取込と出力。
 * 取込の照合の順: 従業員番号(列があり値が入った行)が先、なければメールアドレス。
 * 列がないか空の行は、今の従業員番号とメモを残す(列を足す前の CSV で消さない)。
 */
require_once __DIR__ . '/helpers.php';
tet2_test_boot();
load_api('targets');
preg_match('/function tet2_csv_sanitize\(.*?\n\}/s', (string) file_get_contents(__DIR__ . '/../lib/bootstrap.php'), $sanitizer);
eval($sanitizer[0]);

$row = static fn(string $email, int $tenantId = 1): ?array =>
    Db::one('SELECT * FROM targets WHERE tenant_id = ? AND email = ?', [$tenantId, $email]);
$import = static fn(string $csv, string $role = 'operator'): array => call_handler('targets_handle_import_csv', ['csv' => $csv], $role);

// ---- 作成、編集、消す ----
$r = call_handler('targets_handle_create', ['email' => 'emp1@example.test', 'name' => '番号 一郎', 'employee_no' => 'E-001', 'memo' => '営業の窓口']);
check($r['code'] === 201 && $r['payload']['target']['employee_no'] === 'E-001' && $r['payload']['target']['memo'] === '営業の窓口',
    '作成で従業員番号とメモを保存する');
$emp1 = (int) $r['payload']['target']['id'];
$r = call_handler('targets_handle_create', ['email' => 'emp2@example.test', 'employee_no' => 'E-001']);
check($r['code'] === 409 && $r['payload']['error'] === '従業員番号は既に使用されています', '同じテナントの同じ従業員番号は 409');
Db::run("INSERT INTO targets (tenant_id, email, employee_no, memo) VALUES (2, 'other-emp@other.example.test', 'E-001', '他組織のメモ')");
$r = call_handler('targets_handle_create', ['email' => 'emp3@example.test', 'employee_no' => 'E-003']);
check($r['code'] === 201, '別の番号なら作れる');
$emp3 = (int) $r['payload']['target']['id'];
check((int) Db::one("SELECT COUNT(*) AS c FROM targets WHERE employee_no = 'E-001'")['c'] === 2, '別のテナントは同じ従業員番号を持てる(一意はテナントの中だけ)');
$r = call_handler('targets_handle_create', ['email' => 'nonum1@example.test']);
$r2 = call_handler('targets_handle_create', ['email' => 'nonum2@example.test', 'employee_no' => '']);
check($r['code'] === 201 && $r2['code'] === 201 && $r2['payload']['target']['employee_no'] === null, '従業員番号が空の人は何人でも作れる');

$r = call_handler('targets_handle_update', ['id' => $emp3, 'employee_no' => 'E-001']);
check($r['code'] === 409, '編集で同じテナントのほかの人の番号にすると 409');
$r = call_handler('targets_handle_update', ['id' => $emp1, 'memo' => '異動予定']);
check($r['code'] === 200 && $r['payload']['target']['memo'] === '異動予定' && $r['payload']['target']['employee_no'] === 'E-001',
    'メモだけ送ると、従業員番号は今のまま');
$r = call_handler('targets_handle_update', ['id' => $emp1, 'name' => '番号 一郎', 'memo' => '']);
check($r['code'] === 200 && $r['payload']['target']['memo'] === null, '空のメモを送ると消える');
$r = call_handler('targets_handle_update', ['id' => $emp1, 'memo' => str_repeat('あ', 1001)]);
check($r['code'] === 400 && str_contains($r['payload']['error'], '1000'), 'メモは1000文字まで');
$r = call_handler('targets_handle_update', ['id' => $emp1, 'memo' => "1行目\n2行目"]);
check($r['code'] === 400, 'メモに改行は入れられない(CSV を1行ずつ読むため)');
$r = call_handler('targets_handle_update', ['id' => $emp1, 'employee_no' => '<b>1</b>']);
check($r['code'] === 400, '従業員番号に < > は入れられない');
// 権限はルーター(ファイル末尾)で決まる: GET は閲覧者以上、それ以外(作成、編集、取込)は operator 以上
check(str_contains((string) file_get_contents(__DIR__ . '/../api/targets.php'), 'require_role($method === \'GET\' ? \'viewer\' : \'operator\')'),
    '閲覧者は読むだけで、編集と取込は operator 以上');

// ---- 一覧と検索 ----
$_GET = ['q' => 'E-00'];
$r = call_handler('targets_handle_list', [], 'viewer');
$emails = array_column($r['payload']['targets'], 'email');
sort($emails);
check($r['code'] === 200 && $emails === ['emp1@example.test', 'emp3@example.test'], '閲覧者も一覧を読め、従業員番号で検索できる(他組織は出ない)');
Db::run("UPDATE targets SET memo = '夜勤の担当' WHERE id = ?", [$emp3]);
$_GET = ['q' => '夜勤'];
$r = call_handler('targets_handle_list', [], 'viewer');
check(array_column($r['payload']['targets'], 'email') === ['emp3@example.test'], 'メモでも検索できる');
$_GET = [];
$r = call_handler('targets_handle_list', [], 'viewer');
$listed = array_column($r['payload']['targets'], null, 'email');
check(array_key_exists('employee_no', $listed['target1@example.test']) && $listed['target1@example.test']['employee_no'] === null,
    '一覧に従業員番号とメモの列が出る(空の人は null)');

// ---- CSV の取込: 照合の順 ----
Db::run("UPDATE targets SET employee_no = 'E-100', memo = '元のメモ' WHERE tenant_id = 1 AND email = 'target1@example.test'");
$r = $import("メールアドレス,氏名,従業員番号,メモ\nnew-address@example.test,Target One,E-100,\n");
check($r['code'] === 200 && $r['payload']['updated'] === 1 && $r['payload']['email_changed'] === 1 && $r['payload']['imported'] === 0,
    '従業員番号が先: 番号で当たった人はメールアドレスを CSV の値に変える(新しい人として足さない)');
$t1 = Db::one('SELECT * FROM targets WHERE id = 1');
check($t1['email'] === 'new-address@example.test' && $t1['memo'] === '元のメモ', 'メモの列が空なら今のメモを残す');
check($row('target1@example.test') === null, '古いアドレスの人は残らない(同じ人の行を変えた)');

$r = $import("メールアドレス,従業員番号\ntarget2@example.test,E-200\n");
check($r['payload']['updated'] === 1 && $row('target2@example.test')['employee_no'] === 'E-200',
    '番号で当たらなければメールアドレスで照合し、番号のない人に番号を入れる');
$r = $import("メールアドレス,従業員番号\ntarget2@example.test,E-999\n");
check($r['payload']['skipped'] === 1 && str_contains($r['payload']['errors'][0]['reason'], 'E-200') && $row('target2@example.test')['employee_no'] === 'E-200',
    'メールアドレスで当たった人に別の番号があれば、その行は取り込まない(番号を書き換えない)');
$r = $import("メールアドレス,従業員番号\ntarget2@example.test,E-100\n");
check($r['payload']['skipped'] === 1 && str_contains($r['payload']['errors'][0]['reason'], '別の対象者'),
    '番号で当たった人のアドレスを、ほかの人が使っているアドレスには変えない');
$r = $import("メールアドレス,従業員番号\ndup1@example.test,E-300\ndup2@example.test,E-300\n");
check($r['payload']['imported'] === 1 && $r['payload']['skipped'] === 1 && $r['payload']['errors'][0]['line'] === 3
    && $row('dup2@example.test') === null, 'CSV の中で同じ番号の2行目は取り込まない');
$r = $import("メールアドレス,氏名\ntarget2@example.test,Target Two\n");
check($r['payload']['updated'] === 1 && $row('target2@example.test')['employee_no'] === 'E-200', '従業員番号の列がない CSV は今の番号を残す(従来の取込のまま)');
$r = $import("email,name,employee_no,memo\nemp-en@example.test,English Header,E-400,英語の見出し\n");
check($r['payload']['imported'] === 1 && $row('emp-en@example.test')['employee_no'] === 'E-400', '英語の見出し(employee_no、memo)も受け付ける');
Db::run("INSERT INTO targets (tenant_id, email, employee_no) VALUES (2, 'only-t2@other.example.test', 'T2-ONLY')");
$r = $import("メールアドレス,従業員番号\nnew-in-t1@example.test,T2-ONLY\n");
check($r['payload']['imported'] === 1 && $row('new-in-t1@example.test')['employee_no'] === 'T2-ONLY',
    'ほかのテナントにだけある番号では照合せず、自分のテナントの新しい人として足す');
check($row('other-emp@other.example.test', 2)['employee_no'] === 'E-001' && $row('other-emp@other.example.test', 2)['memo'] === '他組織のメモ',
    '取込はほかのテナントの同じ番号の人に触れない');
$r = $import("メールアドレス,メモ\nctrl@example.test,\"タブ\tあり\"\n");
check($r['payload']['skipped'] === 1 && str_contains($r['payload']['errors'][0]['reason'], '制御文字'), '取込のメモも制御文字を拒む');

// ---- CSV の出力: 式の無害化と往復 ----
Db::run("UPDATE targets SET memo = '=HYPERLINK(\"x\")', employee_no = '-7' WHERE id = ?", [$emp3]);
$csv = targets_build_csv(1);
$lines = array_values(array_filter(explode("\n", str_replace("\r", '', $csv))));
check($lines[0] === 'メールアドレス,氏名,会社名,部署,役職,役職カテゴリ,従業員番号,メモ', '出力の見出しに従業員番号とメモが付く');
$emp3Line = current(array_filter($lines, static fn(string $l): bool => str_starts_with($l, 'emp3@example.test')));
check(str_contains($emp3Line, ",'-7,") && str_contains($emp3Line, "\"'=HYPERLINK(\"\"x\"\")\""), '先頭が = や - の値は \' を付けて出す(式として開かれない)');
check(!str_contains($csv, 'other-emp@other.example.test'), '出力にほかのテナントの人は出ない');
$r = $import($csv);
$after = Db::one('SELECT employee_no, memo FROM targets WHERE id = ?', [$emp3]);
check($r['payload']['imported'] === 0 && $r['payload']['skipped'] === 0 && $after['employee_no'] === '-7' && $after['memo'] === '=HYPERLINK("x")',
    '出力した CSV をそのまま取り込むと、付けた \' を外して元の値に戻る(往復)');

echo "ALL TESTS PASSED\n";
