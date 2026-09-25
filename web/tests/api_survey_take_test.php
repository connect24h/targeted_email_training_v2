<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

tet2_test_boot();
require_once __DIR__ . '/../lib/SurveyService.php';
load_api('survey_take');

$sid = SurveyService::createSurvey(1, 1, ['title' => '回答テスト', 'description' => '<b>説明</b>', 'questions' => [
    ['question_type' => 'single', 'title' => '<script>alert(1)</script>', 'options' => ['はい', 'いいえ'], 'is_required' => true],
    ['question_type' => 'text', 'title' => '理由', 'show_if' => ['question_index' => 0, 'option' => 1], 'is_required' => true],
]]);
$d = SurveyService::createDelivery(1, $sid, 1, '配信', date('Y-m-d H:i:s', time() + 3600), [1]);
$token = (string) Db::one('SELECT access_token FROM survey_assignments WHERE delivery_id = ?', [$d['delivery_id']])['access_token'];
[$q1, $q2] = array_column(SurveyService::getSurvey(1, $sid)['questions'], 'id');

[$code, $data] = stake_start('not-a-token');
check($code === 400 && $data['success'] === false, 'ST-1: 形式の違うトークンは 400');
[$code] = stake_start(str_repeat('f', 32));
check($code === 404, 'ST-1: 存在しないトークンは 404');

[$code, $data] = stake_start($token);
check($code === 200 && count($data['questions']) === 2, 'ST-2: 設問を返す');
check($data['questions'][0]['title'] === '<script>alert(1)</script>', 'ST-2: 設問文は加工せずに返し、画面側で textContent として描く');
check(!array_key_exists('target_id', $data) && !str_contains(json_encode($data), 'tenant_id'), 'ST-2: 対象者やテナントの識別子を返さない');

[$code, $data] = stake_submit(['token' => $token, 'answers' => 'x']);
check($code === 400, 'ST-3: answers が配列でなければ 400');
[$code, $data] = stake_submit(['token' => $token, 'answers' => [$q1 => 1]]);
check($code === 400 && str_contains($data['error'], '設問2'), 'ST-3: 条件で表示された必須設問が未回答なら 400');
[$code] = stake_submit(['token' => $token, 'answers' => [(string) $q1 => 1, (string) $q2 => '忙しかった']]);
check($code === 200, 'ST-4: JSON のキーが文字列でも回答を保存する');
[$code, $data] = stake_submit(['token' => $token, 'answers' => [$q1 => 0]]);
check($code === 409, 'ST-5: 回答済みは 409');
[$code] = stake_start($token);
check($code === 409, 'ST-5: 回答済みのリンクは開けない');

// 期限切れ
$d2 = SurveyService::createDelivery(1, $sid, 1, '期限', date('Y-m-d H:i:s', time() + 3600), [1]);
Db::run("UPDATE survey_deliveries SET deadline = '2000-01-01 00:00:00' WHERE id = ?", [$d2['delivery_id']]);
$expired = (string) Db::one('SELECT access_token FROM survey_assignments WHERE delivery_id = ?', [$d2['delivery_id']])['access_token'];
[$code] = stake_start($expired);
check($code === 410, 'ST-6: 締切を過ぎたリンクは 410');
[$code] = stake_submit(['token' => $expired, 'answers' => [$q1 => 0]]);
check($code === 410, 'ST-6: 締切を過ぎたら送信も 410');

echo "ALL TESTS PASSED\n";
