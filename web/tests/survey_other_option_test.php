<?php
declare(strict_types=1);

/**
 * アンケートの選択肢の「その他（自由記述）」(段D の D6)と、従業員向けの雛形。
 * 選んだ時だけ記述を保存し、集計と CSV / Excel の出力に出すこと、雛形がどれもそのまま作れて訓練を予告しないことを確かめる。
 */

require_once __DIR__ . '/helpers.php';

tet2_test_boot();
require_once __DIR__ . '/../lib/SurveyService.php';
require_once __DIR__ . '/../lib/SurveyTemplates.php';
load_api('survey_take');

function expect_error(callable $fn, int $code, string $message): void
{
    try {
        $fn();
    } catch (SurveyException $e) {
        check($e->httpCode === $code, $message . '(' . $e->getMessage() . ')');
        return;
    }
    throw new RuntimeException('FAIL: ' . $message . '(例外が出なかった)');
}

Db::run("INSERT INTO groups (id, tenant_id, name, kind) VALUES (10, 1, '全職員', 'all')");
$sid = SurveyService::createSurvey(1, 1, ['title' => 'その他の確認', 'questions' => [
    ['question_type' => 'single', 'title' => '使っている端末', 'options' => ['会社の PC', '私物の PC'], 'is_required' => true, 'allow_other' => true],
    ['question_type' => 'multiple', 'title' => '気を付けていること', 'options' => ['画面のロック', '覗き見'], 'allow_other' => true],
    ['question_type' => 'text', 'title' => '自由記述', 'allow_other' => true],
    ['question_type' => 'single', 'title' => 'その他なし', 'options' => ['はい', 'いいえ']],
]]);
$qs = SurveyService::getSurvey(1, $sid)['questions'];
check($qs[0]['allow_other'] === true && $qs[1]['allow_other'] === true && $qs[2]['allow_other'] === false && $qs[3]['allow_other'] === false,
    'OT-1: その他は選択式の設問にだけ付く(自由記述の設問には付かない)');
$dup = SurveyService::getSurvey(1, SurveyService::duplicateSurvey(1, $sid, 1))['questions'];
check($dup[0]['allow_other'] === true && $dup[3]['allow_other'] === false, 'OT-1: 複製してもその他の設定を引き継ぐ');

$d = SurveyService::createDelivery(1, $sid, 1, 'その他の配信', null, [10]);
$tokens = array_column(Db::all('SELECT target_id, access_token FROM survey_assignments WHERE delivery_id = ? ORDER BY target_id', [$d['delivery_id']]), 'access_token', 'target_id');
$start = SurveyService::startByToken($tokens[1]);
check($start['questions'][0]['allow_other'] === true && $start['questions'][0]['options'] === ['会社の PC', '私物の PC'],
    'OT-2: 回答画面には、その他を足す印と元の選択肢を渡す');

[$q1, $q2, $q3, $q4] = array_map(static fn(array $q): int => (int) $q['id'], $qs);
expect_error(fn() => SurveyService::submitByToken($tokens[1], [$q1 => 2]), 400, 'OT-3: その他を選んで内容が空なら拒否');
expect_error(fn() => SurveyService::submitByToken($tokens[1], [$q1 => 2], [$q1 => '   ']), 400, 'OT-3: 空白だけの内容も拒否');
expect_error(fn() => SurveyService::submitByToken($tokens[1], [$q1 => 2], [$q1 => str_repeat('あ', 501)]), 400, 'OT-3: 内容は500文字まで');
expect_error(fn() => SurveyService::submitByToken($tokens[1], [$q1 => 0, $q4 => 2], [$q4 => 'x']), 400, 'OT-3: その他のない設問でその他の番号は拒否');
expect_error(fn() => SurveyService::submitByToken($tokens[1], [$q1 => 3], [$q1 => 'x']), 400, 'OT-3: その他より後の番号は拒否');

// 回答画面の API を通して保存する
$GLOBALS['__TET2_TEST_BODY'] = [];
[$code] = stake_submit(['token' => $tokens[1], 'answers' => [$q1 => 2, $q2 => [0, 2], $q3 => '感想'], 'others' => 'x']);
check($code === 400, 'OT-4: others が配列でなければ 400');
[$code, $res] = stake_submit(['token' => $tokens[1], 'answers' => [$q1 => 2, $q2 => [0, 2], $q3 => '感想'],
    'others' => [$q1 => ' 貸与のタブレット ', $q2 => '=HYPERLINK("x")', $q4 => '捨てられる']]);
check($code === 200 && $res['success'] === true, 'OT-4: その他を選んで内容を書けば受け付ける');
$rows = Db::all('SELECT question_id, value, other_text FROM survey_answers ORDER BY question_id');
$byQ = array_column($rows, null, 'question_id');
check($byQ[$q1]['value'] === '[2]' && $byQ[$q1]['other_text'] === '貸与のタブレット' && $byQ[$q2]['other_text'] === '=HYPERLINK("x")'
    && $byQ[$q3]['other_text'] === null && !isset($byQ[$q4]), 'OT-4: その他の内容を回答と一緒に保存し、選んでいない設問の内容は捨てる');
SurveyService::submitByToken($tokens[2], [$q1 => 0, $q2 => [1]], [$q1 => '選んでいないので捨てる']);
check(Db::one('SELECT other_text FROM survey_answers WHERE question_id = ? AND other_text IS NOT NULL AND value = ?', [$q1, '[0]']) === null,
    'OT-4: その他を選んでいなければ内容は保存しない');

$results = SurveyService::results(1, $d['delivery_id']);
$r1 = $results['questions'][0];
check($r1['options'] === ['会社の PC', '私物の PC', 'その他（自由記述）'] && $r1['counts'] === [1, 0, 1] && $r1['other_texts'] === ['貸与のタブレット'],
    'OT-5: 集計ではその他を最後の選択肢として数え、内容を並べる');
check($results['questions'][3]['options'] === ['はい', 'いいえ'] && $results['questions'][3]['other_texts'] === [], 'OT-5: その他のない設問の集計は今のまま');

$export = SurveyService::exportRows(1, $d['delivery_id']);
$line = $export['rows'][0];
check($line[4] === 'その他（自由記述）: 貸与のタブレット' && $line[5] === '画面のロック / その他（自由記述）: =HYPERLINK("x")',
    'OT-6: CSV と Excel の出力に、その他の内容を「その他（自由記述）: 内容」で出す');

// --- 雛形: どれもそのまま下書きにでき、従業員向けの4つは訓練を予告しない ---
$all = SurveyTemplates::all();
check(count($all) === 6, 'TP-1: 雛形は6つ(従業員向けの4つを足した)');
foreach ($all as $key => $tpl) {
    $id = SurveyService::createSurvey(1, 1, $tpl);
    $got = SurveyService::getSurvey(1, $id);
    check(count($got['questions']) === count($tpl['questions']) && $got['title'] === $tpl['title'], "TP-2: 雛形 {$key} はそのまま下書きにできる");
}
foreach (['incident_experience', 'remote_work', 'personal_data', 'training_feedback'] as $key) {
    $text = json_encode($all[$key], JSON_UNESCAPED_UNICODE);
    $bad = array_filter(['訓練', '模擬', '抜き打ち', 'テストメール', '偽のメール', '標的型'], static fn(string $w): bool => str_contains($text, $w));
    check($bad === [], "TP-3: 雛形 {$key} に、今後の訓練を予告する言葉がない");
    check(array_filter($all[$key]['questions'], static fn(array $q): bool => !empty($q['allow_other'])) !== [],
        "TP-3: 雛形 {$key} は、その他（自由記述）の選択肢を使う");
}

echo "ALL TESTS PASSED\n";
