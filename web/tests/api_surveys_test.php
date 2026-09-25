<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

tet2_test_boot();
if (!function_exists('tet2_csv_sanitize')) {
    // bootstrap.php と同じ定義(load_api は bootstrap を読まない)。
    function tet2_csv_sanitize($v): string
    {
        $v = (string) $v;
        return ($v !== '' && in_array($v[0], ['=', '+', '-', '@', "\t", "\r"], true)) ? "'" . $v : $v;
    }
}
putenv('TET2_SURVEY_MAIL_ENABLED');
require_once __DIR__ . '/../lib/SurveyService.php';
require_once __DIR__ . '/../lib/SurveyTemplates.php';
require_once __DIR__ . '/../lib/SurveyMailer.php';
load_api('surveys');

Db::run("INSERT INTO groups (id, tenant_id, name, kind) VALUES (10, 1, '全職員', 'all')");

// --- 雛形から作成 ---
$tpl = call_handler('sv_handle_templates', [], 'viewer');
check($tpl['code'] === 200 && count($tpl['payload']['templates']) === 2, 'AS-1: 雛形2種を返す');
check($tpl['payload']['mail_enabled'] === false, 'AS-1: 既定ではメール送信は無効と返す');

$created = call_handler('sv_handle_create', ['template' => 'after_training']);
check($created['code'] === 200 && $GLOBALS['__TET2_TEST_CSRF_CALLS'] === 1, 'AS-2: 雛形からの作成は CSRF を検証する');
$sid = (int) $created['payload']['id'];
$survey = call_handler('sv_handle_get', [], 'viewer', [current_user()]);
check($survey['code'] === 400, 'AS-2: id がなければ 400');
$_GET['id'] = (string) $sid;
$survey = call_handler('sv_handle_get', [], 'viewer');
check($survey['code'] === 200 && count($survey['payload']['survey']['questions']) === 6, 'AS-2: 雛形の6問を複製する');
check($survey['payload']['survey']['questions'][3]['show_if'] === ['question_index' => 0, 'option' => 3], 'AS-2: 雛形の表示条件を引き継ぐ');
check(call_handler('sv_handle_create', ['template' => 'nothing'])['code'] === 404, 'AS-2: 無い雛形は 404');

// --- 権限 ---
$_GET['delivery_id'] = '1';
check(call_handler('sv_handle_tokens_csv', [], 'viewer')['code'] === 403, 'AS-3: viewer は受講用 URL の一覧を取得できない');

// --- 配信と他テナント ---
$bad = call_handler('sv_handle_deliver', ['survey_id' => $sid, 'title' => '第1回', 'group_ids' => ['1']]);
check($bad['code'] === 400, 'AS-4: group_ids は整数の配列だけ受け付ける');
$other = call_handler('sv_handle_deliver', ['survey_id' => $sid, 'title' => '第1回', 'group_ids' => [10], 'tenant_id' => 2]);
check($other['code'] === 403, 'AS-4: 他テナントを指定すると 403');
$deliver = call_handler('sv_handle_deliver', ['survey_id' => $sid, 'title' => '第1回', 'group_ids' => [10]]);
check($deliver['code'] === 200 && $deliver['payload']['assigned'] === 2, 'AS-4: 配信を作り対象者を固定する');
$deliveryId = (int) $deliver['payload']['delivery_id'];
check(($GLOBALS['__TET2_TEST_AUDIT'][0]['action'] ?? '') === 'survey.deliver', 'AS-4: 配信を監査に記録する');

$upd = call_handler('sv_handle_update', ['id' => $sid, 'title' => '変更', 'questions' => []]);
check($upd['code'] === 409, 'AS-5: 配信後の編集は 409');

// --- 受講用 URL の一覧 ---
$rows = sv_token_csv_rows(1, $deliveryId);
check(count($rows) === 3 && str_contains($rows[1][3], '/survey.php?token='), 'AS-6: 未回答者の回答用 URL を出す');
$token = (string) Db::one('SELECT access_token FROM survey_assignments WHERE delivery_id = ? ORDER BY id LIMIT 1', [$deliveryId])['access_token'];
$qs = SurveyService::getSurvey(1, $sid)['questions'];
SurveyService::submitByToken($token, [$qs[0]['id'] => 0, $qs[2]['id'] => 0, $qs[4]['id'] => 0]);
check(count(sv_token_csv_rows(1, $deliveryId)) === 2, 'AS-6: 回答済みの人は一覧から外す');
check(tet2_csv_sanitize('=HYPERLINK("x")') === '\'=HYPERLINK("x")', 'AS-6: CSV の数式は無害化する');

// --- 集計 ---
$_GET['delivery_id'] = (string) $deliveryId;
$res = call_handler('sv_handle_results', [], 'viewer');
check($res['code'] === 200 && $res['payload']['results']['answered'] === 1 && $res['payload']['results']['assigned'] === 2,
    'AS-7: 集計を返す');
$_GET['tenant_id'] = '2';
check(call_handler('sv_handle_results', [], 'viewer')['code'] === 403, 'AS-7: 他テナントの集計は 403');
unset($_GET['tenant_id']);

// --- メール送信は既定で無効 ---
$send = call_handler('sv_handle_send_invitations', ['delivery_id' => $deliveryId]);
check($send['code'] === 409, 'AS-8: 送信が無効なら案内メールは 409');
$remind = call_handler('sv_handle_remind', ['delivery_id' => $deliveryId]);
check($remind['code'] === 409, 'AS-8: 送信が無効なら催促も 409');

// --- 終了と複製 ---
check(call_handler('sv_handle_close', ['delivery_id' => $deliveryId])['code'] === 200, 'AS-9: 配信を終了できる');
$dup = call_handler('sv_handle_duplicate', ['id' => $sid]);
check($dup['code'] === 200 && (int) $dup['payload']['id'] !== $sid, 'AS-9: 配信済みのアンケートを複製できる');
check(call_handler('sv_handle_delete', ['id' => (int) $dup['payload']['id']])['code'] === 200, 'AS-9: 複製した下書きは削除できる');

echo "ALL TESTS PASSED\n";
