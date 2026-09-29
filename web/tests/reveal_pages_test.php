<?php
declare(strict_types=1);

/**
 * 種明かしページの複数管理(G29)の回帰テスト。
 * - 作成・更新・一覧・本文の読み出し(ファイルに保存)
 * - 既存の種明かし upload と同じ検証(sanitization reuse: script/onclick/iframe/meta refresh 等)
 * - テナント分離(他テナントのページは見えない・選べない)
 * - キャンペーンの選択(reveal_page_id)と、配信経路のフォールバック(選択→既定 reveal.html)
 * - 削除するとそのページを選んでいるキャンペーンは既定へ戻る
 */

require_once __DIR__ . '/helpers.php';
$root = tet2_test_boot();
require_once __DIR__ . '/../lib/RevealPages.php';

// 各テナントの data_dir を、このテスト専用の一時ディレクトリに向ける(本番の領域は使わない)。
$dataRoot = sys_get_temp_dir() . '/tet2-reveal-' . getmypid();
@mkdir($dataRoot . '/t1', 0775, true);
@mkdir($dataRoot . '/t2', 0775, true);
register_shutdown_function(static function () use ($dataRoot): void {
    if (!is_dir($dataRoot)) { return; }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dataRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
    @rmdir($dataRoot);
});
Db::run('UPDATE tenants SET data_dir = ? WHERE id = 1', [$dataRoot . '/t1']);
Db::run('UPDATE tenants SET data_dir = ? WHERE id = 2', [$dataRoot . '/t2']);

$goodHtml = '<!DOCTYPE html><html><body><h1>種明かし</h1></body></html>';

// --- 作成・保存 ---
$id1 = RevealPages::save(1, null, '営業部向け', $goodHtml, 'op@t1');
check($id1 > 0, 'save: 新規作成で id を返す');
$row = RevealPages::find($id1, 1);
check($row !== null && $row['storage_name'] === 'reveal-' . $id1 . '.html', 'save: storage_name は reveal-<id>.html');
$file = $dataRoot . '/t1/reveal-pages/reveal-' . $id1 . '.html';
check(is_file($file) && file_get_contents($file) === $goodHtml, 'save: 本文をファイルに保存する');
check((fileperms($file) & 0777) === 0640, 'save: ファイルは 0640');
check(RevealPages::content($id1, 1) === $goodHtml, 'content: 本文を読み出せる');

// --- 更新 ---
$goodHtml2 = '<!DOCTYPE html><html><body><h1>改訂</h1></body></html>';
RevealPages::save(1, $id1, '営業部向け(改訂)', $goodHtml2, 'op@t1');
check(RevealPages::content($id1, 1) === $goodHtml2, 'save(update): 本文を差し替える');
check(RevealPages::find($id1, 1)['name'] === '営業部向け(改訂)', 'save(update): 名前を差し替える');

// --- 検証(既存の種明かし upload と同じ規則) ---
foreach ([
    ['<html><script>x</script></html>', 'script'],
    ['<html><body onload="x()"></body></html>', 'onload'],
    ['<html><iframe src="x"></iframe></html>', 'iframe'],
    ['<html><object></object></html>', 'object'],
    ['<html><embed></embed></html>', 'embed'],
    ['<html><meta http-equiv="refresh" content="0"></html>', 'meta refresh'],
    ['ただのテキスト', 'HTML でない'],
    ['', '空'],
] as [$bad, $label]) {
    $threw = false;
    try { RevealPages::save(1, null, 'x', $bad, 'op@t1'); } catch (DomainException) { $threw = true; }
    check($threw, "validate: {$label} は拒否する");
}

// --- テナント分離 ---
$id2 = RevealPages::save(2, null, '他テナント', $goodHtml, 'op@t2');
check(RevealPages::find($id2, 1) === null, 'isolation: 他テナントのページは自テナントから見えない');
check(count(RevealPages::all(1)) === 1 && count(RevealPages::all(2)) === 1, 'isolation: 一覧は自テナントの分だけ');
$threw = false;
try { RevealPages::save(1, $id2, 'x', $goodHtml, 'op@t1'); } catch (DomainException $e) { $threw = $e->getCode() === 404; }
check($threw, 'isolation: 他テナントのページは更新できない(404)');

// --- キャンペーンの選択と配信経路(credential_capture.php の解決ロジック) ---
// fixture の campaign 2(tenant 1)、tracking 0000000001 を使う。
Db::run('UPDATE campaigns SET reveal_page_id = ? WHERE id = 2', [$id1]);
// 他テナントのページは選べない(API 層 campaigns_optional_reveal_page_id の検証)。
require_once __DIR__ . '/../lib/Db.php';
load_api('campaigns');
$crossThrew = false;
try {
    campaigns_optional_reveal_page_id(['reveal_page_id' => $id2], 1);
} catch (Tet2TestExit $e) {
    $crossThrew = $e->httpCode === 400;
}
check($crossThrew, 'campaign: 他テナントの種明かしページは選べない(400)');
check(campaigns_optional_reveal_page_id(['reveal_page_id' => $id1], 1) === $id1, 'campaign: 自テナントのページは選べる');
check(campaigns_optional_reveal_page_id([], 1) === null, 'campaign: 未指定は既定(null)');

// 配信経路の解決(credential_capture.php と同じ: 選択→既定 reveal.html)。
$resolve = static function (?int $revealPageId, string $dataDir): string {
    $envDefault = __DIR__ . '/../../bin/master.html';
    $masterPath = $envDefault;
    $selected = $revealPageId !== null ? $dataDir . '/reveal-pages/reveal-' . $revealPageId . '.html' : null;
    $default = $dataDir . '/reveal.html';
    if ($selected !== null && is_file($selected) && is_readable($selected)) {
        $masterPath = $selected;
    } elseif (is_file($default) && is_readable($default)) {
        $masterPath = $default;
    }
    return $masterPath;
};
$t1Dir = $dataRoot . '/t1';
check($resolve($id1, $t1Dir) === $t1Dir . '/reveal-pages/reveal-' . $id1 . '.html', 'serving: キャンペーンが選んだページを使う');
// 選んだページのファイルが無い時と、選んでいない時は既定 reveal.html にフォールバック。
file_put_contents($t1Dir . '/reveal.html', $goodHtml);
check($resolve(null, $t1Dir) === $t1Dir . '/reveal.html', 'serving: 未選択は既定 reveal.html にフォールバック');
check($resolve(99999, $t1Dir) === $t1Dir . '/reveal.html', 'serving: 選んだページのファイルが無ければ既定にフォールバック');
@unlink($t1Dir . '/reveal.html');
check($resolve(null, $t1Dir) === __DIR__ . '/../../bin/master.html', 'serving: 既定も無ければ共通 master.html');

// --- 削除するとキャンペーンは既定へ戻る ---
RevealPages::delete($id1, 1);
check(RevealPages::find($id1, 1) === null, 'delete: ページが消える');
check(!is_file($file), 'delete: ファイルも消える');
check(Db::one('SELECT reveal_page_id FROM campaigns WHERE id = 2')['reveal_page_id'] === null, 'delete: 選んでいたキャンペーンは既定(NULL)へ戻る');
$threw = false;
try { RevealPages::delete($id1, 1); } catch (DomainException $e) { $threw = $e->getCode() === 404; }
check($threw, 'delete: 無い id は 404');

echo "ALL TESTS PASSED\n";
