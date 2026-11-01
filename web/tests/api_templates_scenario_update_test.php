<?php
declare(strict_types=1);

/**
 * シナリオ(件名+本文の対)の一括更新 update_scenario のテスト。
 * - 新規作成で入力する全項目(シナリオ名、件名、本文の形式、本文、概要)を直せる
 * - 件名と本文の2行を同時に変える。入力が不正なら、どちらも変えない
 * - 件名と本文が同じシナリオの対でなければ拒む
 * - 共有プリセットはシステム管理者だけ、ほかのテナントの行は見えない(404)
 */

require_once __DIR__ . '/helpers.php';
tet2_test_boot();
load_api('templates');

function scenario_row(int $id): array
{
    return Db::one('SELECT name, format, content, description, scenario_key FROM templates WHERE id = ?', [$id]);
}

$r = call_handler('templates_handle_create_scenario', ['name' => '人事部のマイナンバー確認', 'subject_content' => '【至急】マイナンバーの確認',
    'body_content' => '<p>旧本文 #$2$# 様</p>', 'format' => 'html', 'description' => '旧概要'], 'operator');
check($r['code'] === 201, '準備: シナリオを作成');
$subjectId = (int) $r['payload']['subject']['id'];
$bodyId = (int) $r['payload']['body']['id'];
$key = $r['payload']['scenario_key'];

// 全項目を1回で直す
$r = call_handler('templates_handle_update_scenario', ['subject_id' => $subjectId, 'body_id' => $bodyId,
    'name' => '総務部の年末調整', 'subject_content' => '年末調整の書類提出のお願い', 'format' => 'text',
    'body_content' => "新しい本文\n#\$1\$#", 'description' => '新しい概要'], 'operator');
check($r['code'] === 200, '更新: 200 を返す');
$s = scenario_row($subjectId);
$b = scenario_row($bodyId);
check($s['name'] === '総務部の年末調整' && $b['name'] === '総務部の年末調整', '更新: シナリオ名を件名と本文の両方に反映する');
check($s['content'] === '年末調整の書類提出のお願い' && $s['format'] === 'text', '更新: 件名の文面を変え、件名の形式は text のまま');
check($b['content'] === "新しい本文\n#\$1\$#" && $b['format'] === 'text', '更新: 本文と本文の形式を変える');
check($b['description'] === '新しい概要' && $s['description'] === null, '更新: 概要は本文の行に入れる');
check($s['scenario_key'] === $key && $b['scenario_key'] === $key, '更新: 対のキーは変えない');
check($r['payload']['subject']['name'] === '総務部の年末調整' && $r['payload']['body']['content'] === "新しい本文\n#\$1\$#", '更新: 更新後の2行を返す');
check($GLOBALS['__TET2_TEST_AUDIT'][0]['action'] === 'template.update_scenario', '更新: 監査に残す');

// 概要: 送らなければ変えない、空文字で消す
$base = ['subject_id' => $subjectId, 'body_id' => $bodyId, 'name' => '総務部の年末調整', 'subject_content' => '件名', 'format' => 'html', 'body_content' => '<p>本文</p>'];
$r = call_handler('templates_handle_update_scenario', $base, 'operator');
check($r['code'] === 200 && scenario_row($bodyId)['description'] === '新しい概要', '概要: 送らなければ変えない');
$r = call_handler('templates_handle_update_scenario', $base + ['description' => ''], 'operator');
check($r['code'] === 200 && scenario_row($bodyId)['description'] === null, '概要: 空文字で消す');

// 入力の検証: 拒んだら件名も本文も変えない
$before = [scenario_row($subjectId), scenario_row($bodyId)];
foreach ([
    'シナリオ名が空' => ['name' => '  '],
    '件名が空' => ['subject_content' => ''],
    '本文が空' => ['body_content' => ''],
    '形式が不正' => ['format' => 'pdf'],
    '概要に制御文字' => ['description' => "x\x07"],
    'body_id がない' => ['body_id' => null],
] as $label => $override) {
    $r = call_handler('templates_handle_update_scenario', array_merge($base, ['name' => '変えてはいけない'], $override), 'operator');
    check($r['code'] === 400, "検証: {$label}は 400");
}
check([scenario_row($subjectId), scenario_row($bodyId)] === $before, '検証: 拒んだ時は件名も本文も変わらない');

// 対の検証: 別のシナリオの本文、件名と本文の取り違えは拒む
$r = call_handler('templates_handle_create_scenario', ['name' => '別シナリオ', 'subject_content' => '別件名', 'body_content' => '別本文'], 'operator');
$otherBodyId = (int) $r['payload']['body']['id'];
$r = call_handler('templates_handle_update_scenario', array_merge($base, ['body_id' => $otherBodyId]), 'operator');
check($r['code'] === 400, '対の検証: 別のシナリオの本文と組み合わせると 400');
$r = call_handler('templates_handle_update_scenario', array_merge($base, ['subject_id' => $bodyId, 'body_id' => $subjectId]), 'operator');
check($r['code'] === 400, '対の検証: 件名と本文を取り違えると 400');
check(scenario_row($otherBodyId)['name'] === '別シナリオ', '対の検証: 拒んだ時はほかのシナリオも変わらない');

// ほかのテナントのシナリオ: 見えない(404)、変わらない
Db::run("INSERT INTO templates (tenant_id, kind, name, format, content, scenario_key) VALUES (2, 'subject', '他社', 'text', '他社件名', 'sc900'), (2, 'body', '他社', 'html', '他社本文', 'sc900')");
$o = Db::all("SELECT id, kind FROM templates WHERE scenario_key = 'sc900' ORDER BY kind DESC");
$r = call_handler('templates_handle_update_scenario', array_merge($base, ['subject_id' => (int) $o[0]['id'], 'body_id' => (int) $o[1]['id']]), 'tenant_admin');
check($r['code'] === 404, 'テナントの分離: ほかのテナントのシナリオは 404');
check(scenario_row((int) $o[0]['id'])['name'] === '他社', 'テナントの分離: ほかのテナントの行は変わらない');

// 共有プリセット: operator と tenant_admin は 403、superadmin は直せる
Db::run("INSERT INTO templates (tenant_id, kind, name, format, content, scenario_key, is_preset) VALUES (NULL, 'subject', '共有', 'text', '共有件名', 'mfa_t', 1), (NULL, 'body', '共有', 'html', '共有本文', 'mfa_t', 1)");
$p = Db::all("SELECT id, kind FROM templates WHERE scenario_key = 'mfa_t' ORDER BY kind DESC");
$presetBody = array_merge($base, ['subject_id' => (int) $p[0]['id'], 'body_id' => (int) $p[1]['id'], 'name' => '共有の改訂']);
foreach (['operator', 'tenant_admin'] as $role) {
    $r = call_handler('templates_handle_update_scenario', $presetBody, $role);
    check($r['code'] === 403, "共有プリセット: {$role} は 403");
}
check(scenario_row((int) $p[0]['id'])['name'] === '共有', '共有プリセット: 拒まれた変更は反映されない');
$r = call_handler('templates_handle_update_scenario', $presetBody, 'superadmin');
check($r['code'] === 200 && scenario_row((int) $p[0]['id'])['name'] === '共有の改訂' && scenario_row((int) $p[1]['id'])['name'] === '共有の改訂',
    '共有プリセット: システム管理者は直せる');

echo "ALL TESTS PASSED\n";
