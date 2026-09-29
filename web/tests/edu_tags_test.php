<?php
declare(strict_types=1);

/**
 * 分野のタグ(C1、G09)の API: タグの管理(権限、2階層、削除の拒否、テナントの分離)と、
 * 設問の編集・一覧の絞り込み・Excel の出力と取込でのタグ。
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../lib/OfficeDocumentReader.php';
require_once __DIR__ . '/../lib/SimpleXlsx.php';

tet2_test_boot();
load_api('edu_tags');
load_api('edu_questions');
load_api('edu_categories');

$tenantId = current_user()['tenant_id'];
check($tenantId === 1, '前提: テストの利用者は組織1');

$create = static fn(array $body, string $role = 'operator'): array => call_handler('edu_tag_handle_create', $body, $role);
$update = static fn(array $body, string $role = 'operator'): array => call_handler('edu_tag_handle_update', $body, $role);
$delete = static fn(array $body, string $role = 'operator'): array => call_handler('edu_tag_handle_delete', $body, $role);
$list = static function (string $role = 'viewer', ?int $tenant = null): array {
    $_GET = $tenant !== null ? ['tenant_id' => (string) $tenant] : [];
    $r = call_handler('edu_tag_handle_list', [], $role);
    $_GET = [];
    return $r['payload']['tags'] ?? [];
};

// ---- 作成と権限(共有はシステム管理者だけ。edu_categories と同じ) ----
$r = $create(['name' => '  フィッシング ', 'description' => 'メールの見分け方']);
check($r['code'] === 201 && $r['payload']['tag']['name'] === 'フィッシング' && (int) $r['payload']['tag']['tenant_id'] === 1,
    'operator は自組織のタグを作れる(名前の前後の空白は落とす)');
$phish = (int) $r['payload']['tag']['id'];
$r = $create(['name' => '勝手な共有', 'shared' => true]);
check($r['code'] === 201 && (int) $r['payload']['tag']['is_shared'] === 0, 'operator が shared を送っても自組織のタグになる');
$notShared = (int) $r['payload']['tag']['id'];
$r = $create(['name' => '共有の分野', 'shared' => true, 'sort_order' => 1], 'superadmin');
check($r['code'] === 201 && $r['payload']['tag']['tenant_id'] === null && (int) $r['payload']['tag']['is_shared'] === 1,
    'superadmin は共有のタグを作れる');
$shared = (int) $r['payload']['tag']['id'];
check($update(['id' => $shared, 'name' => '変える'])['code'] === 403, 'operator は共有のタグを編集できない');
check($delete(['id' => $shared])['code'] === 403, 'operator は共有のタグを削除できない');
check($update(['id' => $shared, 'description' => '共有の説明'], 'superadmin')['code'] === 200, 'superadmin は共有のタグを編集できる');
check($create(['name' => ''])['code'] === 400, '名前は必須');
check($create(['name' => str_repeat('あ', 101)])['code'] === 400, '名前は100文字まで');
check($create(['name' => 'A>B'])['payload']['tag']['name'] === 'A＞B', 'Excel の区切りの > は全角にする');
check($create(['name' => 'フィッシング'])['code'] === 409, '同じ親の下の同じ名前は 409');
check($update(['id' => $phish])['code'] === 400, '更新の項目がなければ 400');

// ---- 2階層 ----
$r = $create(['name' => '添付ファイル', 'parent_id' => $phish]);
check($r['code'] === 201 && $r['payload']['tag']['path'] === 'フィッシング > 添付ファイル', '子のタグを作れ、path は「親 > 子」');
$attach = (int) $r['payload']['tag']['id'];
check($create(['name' => '孫', 'parent_id' => $attach])['code'] === 400, '子のタグの下には作れない(2階層まで)');
$r = $create(['name' => '自組織の細目', 'parent_id' => $shared]);
check($r['code'] === 201 && (int) $r['payload']['tag']['tenant_id'] === 1, '共有の親の下に自組織の子のタグを作れる');
$tenantChildOfShared = (int) $r['payload']['tag']['id'];
check($create(['name' => '共有の子', 'shared' => true, 'parent_id' => $phish], 'superadmin')['code'] === 400,
    '共有のタグは自組織の親の下に作れない');
check($update(['id' => $phish, 'parent_id' => $notShared])['code'] === 400, '子のあるタグはほかの親の下へ移せない');
check($update(['id' => $notShared, 'parent_id' => $notShared])['code'] === 400, '自分自身を親にはできない');
$r = $update(['id' => $notShared, 'parent_id' => $phish, 'name' => 'リンク']);
check($r['code'] === 200 && $r['payload']['tag']['path'] === 'フィッシング > リンク', '子のない親は、ほかの親の下へ移せる(名前も変えられる)');
$link = $notShared;
check($update(['id' => $link, 'description' => ''])['payload']['tag']['description'] === null, '説明を空にすると消える');

$tags = $list();
$paths = array_column($tags, 'path');
check(array_search('共有の分野', $paths, true) < array_search('共有の分野 > 自組織の細目', $paths, true)
    && array_search('フィッシング', $paths, true) < array_search('フィッシング > 添付ファイル', $paths, true),
    '一覧は親の直後にその子を並べる');

// ---- テナントの分離 ----
Db::run("INSERT INTO edu_tags (tenant_id, name) VALUES (2, '組織2の分野')");
$other = (int) Db::one("SELECT id FROM edu_tags WHERE tenant_id = 2")['id'];
check(!in_array('組織2の分野', array_column($list(), 'name'), true), 'ほかの組織のタグは一覧に出ない');
check(!in_array('フィッシング', array_column($list('superadmin', 2), 'name'), true)
    && in_array('共有の分野', array_column($list('superadmin', 2), 'name'), true),
    '組織2から見ると、組織1のタグは見えず、共有のタグは見える');
check($update(['id' => $other, 'name' => '奪う'])['code'] === 404, 'ほかの組織のタグは編集できない(404)');
check($delete(['id' => $other])['code'] === 404, 'ほかの組織のタグは削除できない(404)');
check($create(['name' => '子', 'parent_id' => $other])['code'] === 404, 'ほかの組織のタグの下には作れない');
check($update(['id' => $phish, 'name' => '組織2から', 'tenant_id' => 2], 'superadmin')['code'] === 404,
    '組織2として操作すると、組織1のタグは見つからない');

// ---- 設問のタグ ----
Db::run("INSERT INTO edu_categories (id, tenant_id, name, slug) VALUES (501, 1, 'カテゴリ1', 'cat-1'), (502, NULL, '共有カテゴリ', 'shared-cat')");
$qBody = static fn(array $extra): array => $extra + [
    'category_id' => 501, 'title' => 'タグの設問', 'question_type' => 'single_choice',
    'options' => ['A', 'B'], 'correct_answer' => [0], 'difficulty' => 1,
];
$r = call_handler('edu_q_handle_create', $qBody(['tag_ids' => [$attach, $tenantChildOfShared, $attach]]));
check($r['code'] === 201 && array_column($r['payload']['question']['tags'], 'path') === ['フィッシング > 添付ファイル', '共有の分野 > 自組織の細目'],
    '設問に複数のタグを付けられ(重複は1つ)、タグの並び順(並び順 0 のフィッシングが先)で返す');
$q1 = (int) $r['payload']['question']['id'];
check(call_handler('edu_q_handle_create', $qBody(['tag_ids' => [$other]]))['code'] === 400, 'ほかの組織のタグは設問に付けられない');
check(call_handler('edu_q_handle_create', $qBody(['tag_ids' => 'x']))['code'] === 400, 'tag_ids は配列');
check(call_handler('edu_q_handle_create', $qBody(['tag_ids' => [0]]))['code'] === 400, 'tag_ids の id は正の整数');
$r = call_handler('edu_q_handle_create', $qBody(['category_id' => 502, 'title' => '共有の設問', 'tag_ids' => [$phish]]), 'superadmin');
check($r['code'] === 400 && str_contains($r['payload']['error'], '共有の設問には共有のタグだけ'), '共有の設問には自組織のタグを付けられない');
$r = call_handler('edu_q_handle_create', $qBody(['category_id' => 502, 'title' => '共有の設問', 'tag_ids' => [$shared]]), 'superadmin');
check($r['code'] === 201, '共有の設問には共有のタグを付けられる');
$sharedQ = (int) $r['payload']['question']['id'];
$r = call_handler('edu_q_handle_create', $qBody(['title' => 'タグなし']));
check($r['code'] === 201 && $r['payload']['question']['tags'] === [], 'タグなしの設問も作れる');
$q3 = (int) $r['payload']['question']['id'];

$r = call_handler('edu_q_handle_update', ['id' => $q3, 'tag_ids' => [$link]]);
check($r['code'] === 200 && array_column($r['payload']['question']['tags'], 'path') === ['フィッシング > リンク'], 'タグだけを変える更新ができる');
$r = call_handler('edu_q_handle_update', ['id' => $q3, 'title' => '題名だけ']);
check(array_column($r['payload']['question']['tags'], 'id') === [$link], 'tag_ids を送らない更新ではタグは変わらない');
$r = call_handler('edu_q_handle_update', ['id' => $q3, 'tag_ids' => []]);
check($r['payload']['question']['tags'] === [], '空の配列でタグを外せる');
call_handler('edu_q_handle_update', ['id' => $q3, 'tag_ids' => [$link]]);

$listQ = static function (array $query): array {
    $_GET = $query;
    $r = call_handler('edu_q_handle_list', [], 'viewer');
    $_GET = [];
    return array_map('intval', array_column($r['payload']['questions'] ?? [], 'id'));
};
$ids = $listQ(['tag_id' => (string) $phish]);
sort($ids);
check($ids === [$q1, $q3], '親のタグで絞ると、子のタグが付いた設問も入る');
check($listQ(['tag_id' => (string) $attach]) === [$q1], '子のタグで絞ると、その子のタグの設問だけ');
check($listQ(['tag_id' => (string) $shared]) === [$q1, $sharedQ] || $listQ(['tag_id' => (string) $shared]) === [$sharedQ, $q1],
    '共有の親で絞ると、共有の設問と、その下の自組織の子のタグの設問');
$_GET = ['tag_id' => (string) $other];
check(call_handler('edu_q_handle_list', [], 'viewer')['code'] === 404, 'ほかの組織のタグで絞ると 404');
$_GET = [];

// ---- 削除の拒否 ----
$r = $delete(['id' => $phish]);
check($r['code'] === 409 && str_contains($r['payload']['error'], '子のタグ'), '子のタグがある親は削除できない(理由つき)');
$r = $delete(['id' => $attach]);
check($r['code'] === 409 && str_contains($r['payload']['error'], '設問に付いている'), '設問に付いているタグは削除できない(理由つき)');
$r = $delete(['id' => $shared], 'superadmin');
check($r['code'] === 409, 'ほかの組織の子のタグがある共有のタグも削除できない');
$unused = (int) $create(['name' => '使っていない'])['payload']['tag']['id'];
check($delete(['id' => $unused])['code'] === 200 && Db::one('SELECT 1 FROM edu_tags WHERE id = ?', [$unused]) === null,
    '子もなく設問にも付いていないタグは削除できる');
call_handler('edu_q_handle_delete', ['id' => $q3]);
check(Db::one('SELECT 1 FROM edu_question_tags WHERE question_id = ?', [$q3]) === null, '設問を消すとタグの結び付けも消える');

// ---- 共有カテゴリのコピー(fork)はタグも写す ----
$r = call_handler('edu_cat_handle_fork', ['id' => 502]);
check($r['code'] === 201, '共有カテゴリを自組織にコピーできる');
$forked = Db::one('SELECT id FROM edu_questions WHERE category_id = ?', [(int) $r['payload']['category']['id']]);
check(array_column(Db::all('SELECT tag_id FROM edu_question_tags WHERE question_id = ?', [(int) $forked['id']]), 'tag_id') === [$shared],
    'コピーした設問にも同じ共有のタグが付く');

// ---- Excel の出力と取込 ----
$export = edu_q_export_rows($tenantId);
$col = array_search('分野のタグ（改行区切り）', $export[0], true);
check($col !== false, 'Excel の出力に「分野のタグ（改行区切り）」の列がある');
$byTitle = [];
foreach (array_slice($export, 1) as $row) {
    $byTitle[$row[2]] = $row[$col];
}
check($byTitle['タグの設問'] === "フィッシング > 添付ファイル\n共有の分野 > 自組織の細目", '子のタグは「親 > 子」を改行で区切って出す');

$xlsxBytes = static function (array $rows): string {
    $path = tempnam(sys_get_temp_dir(), 'tet2-tags-');
    $book = new SimpleXlsx();
    $book->addSheet('設問', $rows);
    $book->saveToFile($path);
    $bytes = (string) file_get_contents($path);
    unlink($path);
    return $bytes;
};
$header = ['カテゴリスラッグ', '設問文', '種別', '選択肢（改行区切り）', '正答番号（1始まり）', '難易度', '分野のタグ（改行区切り）'];
$import = static fn(array $rows, string $role = 'operator'): array => call_handler('edu_q_handle_import_xlsx', [
    'filename' => 'tags.xlsx', 'file_base64' => base64_encode($xlsxBytes($rows)),
], $role);
$before = (int) Db::one('SELECT COUNT(*) AS c FROM edu_questions')['c'];
$r = $import([$header,
    ['cat-1', '取込1', 'single_choice', "A\nB", '1', 1, "フィッシング\nフィッシング>添付ファイル"],
    ['cat-1', '取込2', 'single_choice', "A\nB", '1', 1, '存在しない分野'],
]);
check($r['code'] === 400 && $r['payload']['error'] === 'Excel 3行目: 分野のタグ「存在しない分野」が見つかりません',
    '知らないタグの名前は行の番号つきで拒む');
check((int) Db::one('SELECT COUNT(*) AS c FROM edu_questions')['c'] === $before, '拒んだ時は1件も追加しない');
$r = $import([$header, ['cat-1', '取込3', 'single_choice', "A\nB", '1', 1, '組織2の分野']]);
check($r['code'] === 400, 'ほかの組織のタグの名前は知らない名前として拒む');
// (shared-cat は fork で自組織にも同じスラッグがあるので、共有だけのカテゴリで確かめる)
Db::run("INSERT INTO edu_categories (tenant_id, name, slug) VALUES (NULL, '共有だけ', 'shared-only')");
$r = $import([$header, ['shared-only', '共有の取込', 'single_choice', "A\nB", '1', 1, 'フィッシング']], 'superadmin');
check($r['code'] === 400, '共有の設問の行には自組織のタグを使えない');
$r = $import([$header, ['shared-only', '共有の取込', 'single_choice', "A\nB", '1', 1, '共有の分野']], 'superadmin');
check($r['code'] === 201, '共有の設問の行に共有のタグは使える');
$r = $import([$header,
    ['cat-1', '取込1', 'single_choice', "A\nB", '1', 1, "フィッシング\n フィッシング >  添付ファイル \n共有の分野"],
    ['cat-1', '取込2', 'single_choice', "A\nB", '1', 1, ''],
]);
check($r['code'] === 201 && $r['payload']['imported'] === 2, '知っているタグの名前なら取り込める(区切りの前後の空白は無視)');
$importedTags = static fn(string $title): array => array_column(Db::all(
    'SELECT g.name FROM edu_question_tags qt JOIN edu_tags g ON g.id = qt.tag_id JOIN edu_questions q ON q.id = qt.question_id
     WHERE q.title = ? ORDER BY g.id', [$title]), 'name');
check($importedTags('取込1') === ['フィッシング', '共有の分野', '添付ファイル'], '取り込んだ設問に、書いたタグ(親と子)が付く');
check($importedTags('取込2') === [], 'タグの欄が空なら、タグなしで取り込む');
// 同じ表記のタグが自組織と共有の両方にある時は、自組織の設問では自組織のタグを使う
Db::run("INSERT INTO edu_tags (tenant_id, name) VALUES (NULL, 'フィッシング')");
$r = $import([$header, ['cat-1', '取込4', 'single_choice', "A\nB", '1', 1, 'フィッシング']]);
check($r['code'] === 201 && (int) Db::one("SELECT g.tenant_id FROM edu_question_tags qt JOIN edu_tags g ON g.id = qt.tag_id
    JOIN edu_questions q ON q.id = qt.question_id WHERE q.title = '取込4'")['tenant_id'] === 1,
    '同じ表記なら自組織のタグを優先する');
$r = $import([['カテゴリスラッグ', '設問文', '種別', '選択肢（改行区切り）', '正答番号（1始まり）', '難易度'],
    ['cat-1', '列なし', 'single_choice', "A\nB", '1', 1]]);
check($r['code'] === 201, 'タグの列のない以前のテンプレートも取り込める');

echo "ALL TESTS PASSED\n";
