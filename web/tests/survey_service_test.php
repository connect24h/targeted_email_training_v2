<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

tet2_test_boot();
require_once __DIR__ . '/../lib/SurveyService.php';

function expect_survey_error(callable $fn, int $code, string $message): void
{
    try {
        $fn();
    } catch (SurveyException $e) {
        check($e->httpCode === $code, $message . "（{$e->httpCode}: {$e->getMessage()}）");
        return;
    }
    throw new RuntimeException("FAIL: {$message}（例外が出なかった）");
}

// 合成データ: テナント1にグループ1(対象者1)、全職員グループを追加、テスト用対象者を追加。
Db::run("INSERT INTO groups (id, tenant_id, name, kind) VALUES (10, 1, '全職員', 'all')");
Db::run("UPDATE targets SET department = '総務部' WHERE id = 1");
Db::run("UPDATE targets SET department = '営業部' WHERE id = 2");
Db::run("INSERT INTO targets (id, tenant_id, tenant_no, email, name, status, is_test) VALUES (4, 1, 3, 'tester@example.test', 'Tester', 'active', 1)");
Db::run("INSERT INTO targets (id, tenant_id, tenant_no, email, name, status) VALUES (5, 1, 4, 'left@example.test', 'Left', 'archived')");

$questions = [
    ['question_type' => 'single', 'title' => '訓練メールに気づきましたか', 'options' => ['気づいた', '気づかなかった'], 'is_required' => true],
    ['question_type' => 'multiple', 'title' => '気づいた理由', 'options' => ['差出人', '件名', 'リンク先'], 'show_if' => ['question_index' => 0, 'option' => 0], 'is_required' => true],
    ['question_type' => 'text', 'title' => 'ご意見', 'is_required' => false],
];

// --- 作成と検証 ---
$sid = SurveyService::createSurvey(1, 1, ['title' => '訓練後アンケート', 'questions' => $questions]);
check($sid > 0, 'SV-1: アンケートを作成できる');
$survey = SurveyService::getSurvey(1, $sid);
check(count($survey['questions']) === 3 && $survey['questions'][1]['show_if'] === ['question_index' => 0, 'option' => 0],
    'SV-1: 設問と表示条件を保存する');

expect_survey_error(fn() => SurveyService::createSurvey(1, 1, ['title' => '', 'questions' => []]), 400, 'SV-2: 題名が空なら拒否');
expect_survey_error(fn() => SurveyService::createSurvey(1, 1, ['title' => 'x', 'questions' => [
    ['question_type' => 'single', 'title' => 'q', 'options' => ['1つだけ']],
]]), 400, 'SV-2: 選択肢が1つなら拒否');
expect_survey_error(fn() => SurveyService::createSurvey(1, 1, ['title' => 'x', 'questions' => [
    ['question_type' => 'text', 'title' => 'q1'],
    ['question_type' => 'single', 'title' => 'q2', 'options' => ['a', 'b'], 'show_if' => ['question_index' => 0, 'option' => 0]],
]]), 400, 'SV-2: 自由記述を表示条件の元にすると拒否');
expect_survey_error(fn() => SurveyService::createSurvey(1, 1, ['title' => 'x', 'questions' => [
    ['question_type' => 'single', 'title' => 'q1', 'options' => ['a', 'b'], 'show_if' => ['question_index' => 0, 'option' => 0]],
]]), 400, 'SV-2: 自分自身や後ろの設問を表示条件にすると拒否');

// --- テナント境界 ---
expect_survey_error(fn() => SurveyService::getSurvey(2, $sid), 404, 'SV-3: 他テナントのアンケートは見えない');
expect_survey_error(fn() => SurveyService::updateSurvey(2, $sid, ['title' => '乗っ取り', 'questions' => []]), 404, 'SV-3: 他テナントのアンケートは更新できない');
expect_survey_error(fn() => SurveyService::createDelivery(1, $sid, 1, '配信', null, [2]), 404, 'SV-3: 他テナントのグループへは配信できない');

// --- 配信 ---
expect_survey_error(fn() => SurveyService::createDelivery(1, $sid, 1, '配信', '2000-01-01 00:00:00', [10]), 400, 'SV-4: 過去の締切は拒否');
$d = SurveyService::createDelivery(1, $sid, 1, '第1回', date('Y-m-d H:i:s', time() + 86400), [10]);
check($d['assigned'] === 3, 'SV-4: 全職員グループは有効な対象者だけ（退職者を除く3名）');
check((string) Db::one('SELECT status FROM surveys WHERE id = ?', [$sid])['status'] === 'published', 'SV-4: 配信するとアンケートは公開状態になる');
expect_survey_error(fn() => SurveyService::updateSurvey(1, $sid, ['title' => '変更', 'questions' => $questions]), 409, 'SV-5: 配信後は設問を編集できない');
expect_survey_error(fn() => SurveyService::deleteSurvey(1, $sid), 409, 'SV-5: 配信後は削除できない');
$tokens = SurveyService::tokenRows(1, $d['delivery_id']);
check(count($tokens) === 3 && preg_match('/^[0-9a-f]{32}$/', $tokens[0]['token']) === 1, 'SV-4: 32桁のトークンを発行する');
expect_survey_error(fn() => SurveyService::tokenRows(2, $d['delivery_id']), 404, 'SV-3: 他テナントの配信のトークンは出せない');

$tokenOf = [];
foreach (Db::all('SELECT target_id, access_token FROM survey_assignments WHERE delivery_id = ?', [$d['delivery_id']]) as $r) {
    $tokenOf[(int) $r['target_id']] = $r['access_token'];
}
[$q1, $q2, $q3] = array_column($survey['questions'], 'id');

// --- 回答の検証 ---
expect_survey_error(fn() => SurveyService::submitByToken($tokenOf[1], [$q1 => 0]), 400, 'SV-6: 表示された必須設問が未回答なら拒否');
expect_survey_error(fn() => SurveyService::submitByToken($tokenOf[1], [$q1 => [0, 1]]), 400, 'SV-6: 単一選択に2つ選ぶと拒否');
expect_survey_error(fn() => SurveyService::submitByToken($tokenOf[1], [$q1 => 5]), 400, 'SV-6: 範囲外の選択肢は拒否');
expect_survey_error(fn() => SurveyService::submitByToken('zz', []), 400, 'SV-6: 形式の違うトークンは拒否');
expect_survey_error(fn() => SurveyService::submitByToken(str_repeat('0', 32), []), 404, 'SV-6: 存在しないトークンは拒否');

SurveyService::submitByToken($tokenOf[1], [$q1 => 0, $q2 => [2, 0, 2], $q3 => '  差出人が変だった  ']);
check((string) Db::one('SELECT status FROM survey_assignments WHERE target_id = 1 AND delivery_id = ?', [$d['delivery_id']])['status'] === 'answered',
    'SV-7: 回答すると割当が回答済みになる');
$saved = Db::all('SELECT a.question_id, a.value FROM survey_answers a INNER JOIN survey_responses r ON r.id = a.response_id WHERE r.assignment_id IS NOT NULL ORDER BY a.question_id');
check(json_decode($saved[1]['value'], true) === [0, 2], 'SV-7: 複数選択は重複を除いて並べ替える');
check(json_decode($saved[2]['value'], true) === '差出人が変だった', 'SV-7: 自由記述は前後の空白を除く');
expect_survey_error(fn() => SurveyService::submitByToken($tokenOf[1], [$q1 => 1]), 409, 'SV-8: 二重回答は拒否');
expect_survey_error(fn() => SurveyService::startByToken($tokenOf[1]), 409, 'SV-8: 回答済みのリンクは開けない');

// 表示条件で隠れた必須設問は求めず、送られても保存しない。
SurveyService::submitByToken($tokenOf[2], [$q1 => 1, $q2 => [1]]);
$hidden = Db::one(
    'SELECT COUNT(*) AS n FROM survey_answers a INNER JOIN survey_responses r ON r.id = a.response_id
     INNER JOIN survey_assignments s ON s.id = r.assignment_id WHERE s.target_id = 2 AND a.question_id = ?',
    [$q2]
);
check((int) $hidden['n'] === 0, 'SV-9: 隠れた設問の回答は保存しない');

// テスト用対象者の回答は集計から外す。
SurveyService::submitByToken($tokenOf[4], [$q1 => 1]);
$res = SurveyService::results(1, $d['delivery_id']);
check($res['assigned'] === 2 && $res['answered'] === 2 && $res['rate'] === 100.0, 'SV-10: 母数と回答からテスト用対象者を除く');
check($res['questions'][0]['counts'] === [1, 1], 'SV-10: 選択肢ごとの件数を数える');
check($res['questions'][2]['texts'] === ['差出人が変だった'], 'SV-10: 自由記述を一覧にする');
check(array_column($res['by_department'], 'department') === ['営業部', '総務部'], 'SV-10: 部署ごとの回答率を出す');
$export = SurveyService::exportRows(1, $d['delivery_id']);
check($export['header'][1] === '氏名' && count($export['rows']) === 2, 'SV-10: 記名の出力は回答者の列を持つ');

// --- 匿名 ---
$aid = SurveyService::createSurvey(1, 1, ['title' => '匿名', 'is_anonymous' => true, 'questions' => [
    ['question_type' => 'single', 'title' => '満足度', 'options' => ['高い', '低い'], 'is_required' => true],
]]);
$ad = SurveyService::createDelivery(1, $aid, 1, '匿名配信', null, [1]);
$atoken = (string) Db::one('SELECT access_token FROM survey_assignments WHERE delivery_id = ?', [$ad['delivery_id']])['access_token'];
SurveyService::submitByToken($atoken, [(int) SurveyService::getSurvey(1, $aid)['questions'][0]['id'] => 0]);
$resp = Db::one('SELECT assignment_id, submitted_at FROM survey_responses WHERE delivery_id = ?', [$ad['delivery_id']]);
check($resp['assignment_id'] === null, 'SV-11: 匿名の回答は割当と結ばない');
check(preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $resp['submitted_at']) === 1, 'SV-11: 匿名の回答日時は日付まで');
$asg = Db::one('SELECT status, answered_at FROM survey_assignments WHERE delivery_id = ?', [$ad['delivery_id']]);
check($asg['status'] === 'answered' && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $asg['answered_at']) === 1,
    'SV-11: 匿名でも回答済みは管理し、日時は日付まで');
$aexport = SurveyService::exportRows(1, $ad['delivery_id']);
check($aexport['header'] === ['回答日', 'Q1 満足度'] && $aexport['rows'][0][1] === '高い', 'SV-11: 匿名の出力は回答者の列を持たない');

// --- 終了と複製 ---
SurveyService::closeDelivery(1, $d['delivery_id']);
expect_survey_error(fn() => SurveyService::startByToken($tokenOf[5] ?? str_repeat('a', 32)), 404, 'SV-12: 退職者にはトークンがない');
$d2 = SurveyService::createDelivery(1, $sid, 1, '第2回', null, [1]);
$remaining = (string) Db::one("SELECT access_token FROM survey_assignments WHERE delivery_id = ? AND status = 'assigned'", [$d2['delivery_id']])['access_token'];
check(is_array(SurveyService::startByToken($remaining)), 'SV-12: 公開中のアンケートは2回目の配信を作れ、開ける');
SurveyService::closeDelivery(1, $d2['delivery_id']);
expect_survey_error(fn() => SurveyService::startByToken($remaining), 410, 'SV-12: 終了した配信は回答できない');
$copy = SurveyService::duplicateSurvey(1, $sid, 1);
$copied = SurveyService::getSurvey(1, $copy);
check($copied['status'] === 'draft' && count($copied['questions']) === 3 && $copied['questions'][1]['show_if'] !== null,
    'SV-13: 複製は下書きで、設問と表示条件を引き継ぐ');
SurveyService::updateSurvey(1, $copy, ['title' => '改訂版', 'questions' => [$questions[0]]]);
check(count(SurveyService::getSurvey(1, $copy)['questions']) === 1, 'SV-13: 複製した下書きは編集できる');
SurveyService::deleteSurvey(1, $copy);
check(Db::one('SELECT id FROM surveys WHERE id = ?', [$copy]) === null, 'SV-13: 下書きは削除できる');

echo "ALL TESTS PASSED\n";
