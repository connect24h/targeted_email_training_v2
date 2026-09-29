<?php
declare(strict_types=1);

/**
 * 配信ごとの受講の設定と、マイページの小さな改善のテスト(段A の A-1〜A-5、G52〜G55、G60)。
 *   A-1 選択肢の並べ替え(shuffle_options): 開き直しても同じ順、並べた順の正解で満点、並べる前の番号では正解にならない、
 *       答え合わせの取り違えがない、保存とマイページの答え合わせは元の番号
 *   A-2 テスト中は教材を閉じる(lock_material_during_test): テストを始めた後は API も教材を返さない
 *   A-3 期限後の受講(allow_after_deadline): 期限の後も受講でき、レポートでは期限後(期限内合格に入らない)
 *   A-4 テストから受け直す(retake_from_test): 不合格の回がある時だけ start_at_test
 *   A-5 マイページ: 配信日時と「正解 n/m」、自分の受講完了率、戻るリンク(アカウントがある人だけ)、社内の問い合わせ先
 */
require_once __DIR__ . '/helpers.php';
tet2_test_boot();
load_api('edu_take');
load_api('edu_report');
load_api('edu_deliveries');
require_once __DIR__ . '/../lib/LearnerAuth.php';

$tenantId = 1;
$seq = 0;
function optTarget(string $email): int
{
    return Db::insert("INSERT INTO targets (tenant_id, email, name, department, status) VALUES (1, ?, '受講者', '営業部', 'active')", [$email]);
}
function optDelivery(array $overrides = []): int
{
    $d = array_merge(['title' => '配信', 'delivery_type' => 'elearning', 'pass_score' => 100, 'feedback_mode' => 'after_submit',
        'material_id' => null, 'deadline' => null, 'shuffle_options' => 0, 'lock_material_during_test' => 0,
        'allow_after_deadline' => 0, 'retake_from_test' => 0, 'scheduled_at' => null], $overrides);
    $names = array_keys($d);
    return Db::insert("INSERT INTO edu_deliveries (tenant_id, status, target_type, " . implode(', ', $names) . ")
        VALUES (1, 'running', 'individual', " . implode(', ', array_fill(0, count($names), '?')) . ')', array_values($d));
}
function optAssign(int $deliveryId, int $targetId, string $token, ?string $expiry = null): int
{
    return Db::insert("INSERT INTO edu_assignments (tenant_id, delivery_id, target_id, access_token, status, token_expiry) VALUES (1, ?, ?, ?, 'assigned', ?)",
        [$deliveryId, $targetId, $token, $expiry]);
}
function takeGet(string $fn, string $token): array
{
    $_GET = ['token' => $token];
    return call_handler($fn, [], 'viewer', []);
}
function takePost(string $fn, string $token, array $body = []): array
{
    return call_handler($fn, ['token' => $token] + $body, 'viewer', []);
}

$categoryId = Db::insert("INSERT INTO edu_categories (tenant_id, name, slug) VALUES (1, '選択肢', 'opt-cat')");
$q1 = Db::insert(
    "INSERT INTO edu_questions (tenant_id, category_id, title, question_type, options, correct_answer, option_explanations, difficulty)
     VALUES (1, ?, '1つ選ぶ', 'single_choice', '[\"選択肢A\",\"選択肢B\",\"選択肢C\",\"選択肢D\"]', '[2]', '[\"解説A\",\"解説B\",\"解説C\",\"解説D\"]', 1)",
    [$categoryId]
);
$q2 = Db::insert(
    "INSERT INTO edu_questions (tenant_id, category_id, title, question_type, options, correct_answer, difficulty)
     VALUES (1, ?, 'すべて選ぶ', 'multiple_choice', '[\"甲\",\"乙\",\"丙\",\"丁\"]', '[0,3]', 1)",
    [$categoryId]
);
$correct = [$q1 => [2], $q2 => [0, 3]];
$materialId = Db::insert("INSERT INTO edu_materials (tenant_id, title, description, slides, is_active)
    VALUES (1, '教材', '説明', '[{\"title\":\"教材\",\"body\":\"本文\"}]', 1)");
$withQuestions = static function (int $deliveryId) use ($q1, $q2): void {
    Db::run('INSERT INTO edu_delivery_questions (delivery_id, question_id, sort_order) VALUES (?, ?, 0), (?, ?, 1)',
        [$deliveryId, $q1, $deliveryId, $q2]);
};
$sorted = static function (array $v): array {
    sort($v);
    return $v;
};
/** 条件に合うトークンを決まった順で探す(並べ替えは種のトークンで決まる)。 */
$pickToken = static function (int $salt, callable $ok): string {
    for ($i = 0; $i < 5000; $i++) {
        $token = sprintf('%02x%030x', $salt, $i);
        if ($ok(['shuffle_options' => 1, 'access_token' => $token])) {
            return $token;
        }
    }
    throw new RuntimeException('条件に合うトークンが見つかりません');
};
$perm = static fn(array $a, int $qid): array => EduOptionOrder::permutation($a, $qid, 4);

// ================================================================ A-1 選択肢の並べ替え
$shuffled = optDelivery(['title' => '並べ替える配信', 'shuffle_options' => 1, 'material_id' => $materialId]);
$withQuestions($shuffled);
$learnerA = optTarget('shuffle-a@example.test');
$learnerB = optTarget('shuffle-b@example.test');
// A: どちらの設問も元の順ではない並び
$tokenA = $pickToken(1, static fn(array $a): bool => $perm($a, $q1) !== [0, 1, 2, 3] && $perm($a, $q2) !== [0, 1, 2, 3]);
// B: 元の番号の正解をそのまま送ると、元に戻した時に正解と違う番号になる並び
$tokenB = $pickToken(2, static fn(array $a): bool =>
    $sorted(EduOptionOrder::toOriginal($perm($a, $q1), [2])) !== [2]
    && $sorted(EduOptionOrder::toOriginal($perm($a, $q2), [0, 3])) !== [0, 3]);
optAssign($shuffled, $learnerA, $tokenA);
optAssign($shuffled, $learnerB, $tokenB);
$aRow = ['shuffle_options' => 1, 'access_token' => $tokenA];

$first = takeGet('take_handle_start', $tokenA);
check($first['code'] === 200, 'A-1: 並べ替える配信の受講を開始できる');
$options = array_column($first['payload']['questions'], 'options', 'id');
check($options[$q1] === EduOptionOrder::arrange($perm($aRow, $q1), ['選択肢A', '選択肢B', '選択肢C', '選択肢D'])
    && $options[$q1] !== ['選択肢A', '選択肢B', '選択肢C', '選択肢D'], 'A-1: 選択肢をサーバーで決めた順に並べて返す');
$again = takeGet('take_handle_start', $tokenA);
check(array_column($again['payload']['questions'], 'options', 'id') === $options, 'A-1: 同じ割当で開き直すと同じ順');
check(!isset($first['payload']['questions'][0]['correct_answer']), 'A-1: 開始の時は正解を返さない');

$displayedAnswers = [];
foreach ([$q1, $q2] as $qid) {
    $displayedAnswers[] = ['question_id' => $qid, 'answer' => EduOptionOrder::toDisplayed($perm($aRow, $qid), $correct[$qid])];
}
$submitA = takePost('take_handle_submit', $tokenA, ['answers' => $displayedAnswers]);
check($submitA['code'] === 200 && $submitA['payload']['result']['percentage'] === 100 && $submitA['payload']['result']['passed'] === true,
    'A-1: 並べた順で正解を選ぶと満点で合格');
$fb1 = array_column($submitA['payload']['feedback'], null, 'id')[$q1];
$pos = $fb1['correct_answer'][0];
check($fb1['your_answer'] === $fb1['correct_answer'] && $fb1['options'][$pos] === '選択肢C' && $fb1['option_explanations'][$pos] === '解説C',
    'A-1: 答え合わせの正解、自分の解答、選択肢ごとの解説が表示の順でそろう');
$stored = Db::one('SELECT ra.answer FROM edu_response_answers ra INNER JOIN edu_responses r ON r.id = ra.response_id
    INNER JOIN edu_assignments a ON a.id = r.assignment_id WHERE a.access_token = ? AND ra.question_id = ?', [$tokenA, $q1]);
check(json_decode((string) $stored['answer'], true) === [2], 'A-1: 保存する解答は元の選択肢の番号');

$rawB = takePost('take_handle_submit', $tokenB, ['answers' => [
    ['question_id' => $q1, 'answer' => [2]], ['question_id' => $q2, 'answer' => [0, 3]],
]]);
check($rawB['code'] === 200 && $rawB['payload']['result']['percentage'] === 0 && $rawB['payload']['result']['passed'] === false,
    'A-1: 並べる前の番号で送っても正解にならない');

$gradesA = LearnerPortal::grades($tenantId, $learnerA);
$reviewQ1 = $gradesA['deliveries'][0]['review'][0];
check($reviewQ1['options'] === ['選択肢A', '選択肢B', '選択肢C', '選択肢D'] && $reviewQ1['your_answer'] === [2]
    && $reviewQ1['correct_answer'] === [2] && $reviewQ1['is_correct'] === true, 'A-1: マイページの答え合わせは元の順で正しく出る');

// 1問ごとの答え合わせでも、表示の順の番号を元に戻して固定する
$immediate = optDelivery(['title' => '1問ごと', 'delivery_type' => 'awareness_quiz', 'pass_score' => null,
    'feedback_mode' => 'immediate', 'shuffle_options' => 1]);
$withQuestions($immediate);
$tokenI = $pickToken(3, static fn(array $a): bool => $perm($a, $q1)[2] !== 2);
optAssign($immediate, optTarget('shuffle-i@example.test'), $tokenI);
$iRow = ['shuffle_options' => 1, 'access_token' => $tokenI];
$ans = takePost('take_handle_answer', $tokenI, ['question_id' => $q1, 'answer' => EduOptionOrder::toDisplayed($perm($iRow, $q1), [2])]);
$lock = Db::one('SELECT l.answer, l.is_correct FROM edu_answer_locks l INNER JOIN edu_assignments a ON a.id = l.assignment_id WHERE a.access_token = ?', [$tokenI]);
check($ans['code'] === 200 && $ans['payload']['feedback']['is_correct'] === true && json_decode((string) $lock['answer'], true) === [2]
    && (int) $lock['is_correct'] === 1, 'A-1: 1問ごとの答え合わせも元の番号で判定して固定する');
$resumed = takeGet('take_handle_start', $tokenI);
check($resumed['payload']['answered'][0]['feedback']['your_answer'] === EduOptionOrder::toDisplayed($perm($iRow, $q1), [2]),
    'A-1: 再開した時の答え合わせ済みの解答も表示の順で返す');

// 並べ替えない配信(既存の配信)は元の順のまま
$plain = optDelivery(['title' => '並べ替えない配信']);
$withQuestions($plain);
optAssign($plain, optTarget('plain@example.test'), str_repeat('c', 32));
$plainStart = takeGet('take_handle_start', str_repeat('c', 32));
check(array_column($plainStart['payload']['questions'], 'options', 'id')[$q1] === ['選択肢A', '選択肢B', '選択肢C', '選択肢D'],
    'A-1: shuffle_options=0 の配信は元の順');
$plainSubmit = takePost('take_handle_submit', str_repeat('c', 32), ['answers' => [
    ['question_id' => $q1, 'answer' => [2]], ['question_id' => $q2, 'answer' => [0, 3]],
]]);
check($plainSubmit['payload']['result']['percentage'] === 100, 'A-1: 並べ替えない配信は元の番号で満点');

// 配信の作成の既定: 並べ替えは有効、ほかは無効。編集で変えられる
$created = call_handler('edu_d_handle_create', ['title' => '新しい配信', 'delivery_type' => 'awareness_quiz', 'target_type' => 'all']);
$new = $created['payload']['delivery'];
check($created['code'] === 201 && (int) $new['shuffle_options'] === 1 && (int) $new['lock_material_during_test'] === 0
    && (int) $new['allow_after_deadline'] === 0 && (int) $new['retake_from_test'] === 0, 'A-1: 新しい配信は選択肢の並べ替えが既定で有効、ほかは無効');
$created2 = call_handler('edu_d_handle_create', ['title' => '設定つき', 'delivery_type' => 'elearning', 'pass_score' => 80, 'target_type' => 'all',
    'shuffle_options' => false, 'lock_material_during_test' => true, 'allow_after_deadline' => true, 'retake_from_test' => true]);
$d2 = $created2['payload']['delivery'];
check((int) $d2['shuffle_options'] === 0 && (int) $d2['lock_material_during_test'] === 1 && (int) $d2['allow_after_deadline'] === 1
    && (int) $d2['retake_from_test'] === 1, '作成で4つの設定を指定できる');
$updated = call_handler('edu_d_handle_update', ['id' => (int) $d2['id'], 'shuffle_options' => true, 'retake_from_test' => false]);
check($updated['code'] === 200 && (int) $updated['payload']['delivery']['shuffle_options'] === 1
    && (int) $updated['payload']['delivery']['retake_from_test'] === 0 && (int) $updated['payload']['delivery']['allow_after_deadline'] === 1,
    '開始前の配信の設定を編集で変えられる(送らなかった設定はそのまま)');
check(call_handler('edu_d_handle_create', ['title' => 'x', 'delivery_type' => 'awareness_quiz', 'target_type' => 'all',
    'shuffle_options' => 'yes'])['code'] === 400, '設定は true/false 以外を拒む');

// ================================================================ A-2 テスト中は教材を閉じる
$locked = optDelivery(['title' => '教材を閉じる', 'material_id' => $materialId, 'lock_material_during_test' => 1]);
$withQuestions($locked);
$tokenL = str_repeat('d', 32);
optAssign($locked, optTarget('lock@example.test'), $tokenL);
$beforeTest = takeGet('take_handle_start', $tokenL);
check($beforeTest['payload']['material'] !== null && $beforeTest['payload']['material_locked'] === false
    && $beforeTest['payload']['delivery']['lock_material_during_test'] === true, 'A-2: テストを始める前は教材を返す');
$begin = takePost('take_handle_begin_test', $tokenL);
check($begin['code'] === 200 && $begin['payload']['material_locked'] === true, 'A-2: テストを始めると教材を閉じる');
$duringTest = takeGet('take_handle_start', $tokenL);
check($duringTest['payload']['material'] === null && $duringTest['payload']['material_locked'] === true,
    'A-2: テストを始めた後は開き直しても教材を返さない(API で止める)');
$imageCode = 0;
try {
    take_page_image_file($tokenL, 1);
} catch (Tet2TestExit $e) {
    $imageCode = $e->httpCode;
}
check($imageCode === 403, 'A-2: テストの間は教材のページの画像も 403');
takePost('take_handle_submit', $tokenL, ['answers' => []]);
$afterSubmit = takeGet('take_handle_start', $tokenL);
check($afterSubmit['payload']['material'] !== null && $afterSubmit['payload']['material_locked'] === false,
    'A-2: 提出した後(次の回を始める前)は教材を見直せる');
// 閉じない配信は、テストを始めても教材を返す
$open = optDelivery(['title' => '教材を閉じない', 'material_id' => $materialId]);
$withQuestions($open);
optAssign($open, optTarget('open@example.test'), str_repeat('e', 32));
takePost('take_handle_begin_test', str_repeat('e', 32));
check(takeGet('take_handle_start', str_repeat('e', 32))['payload']['material'] !== null, 'A-2: 既定(見直せる)の配信は、テスト中も教材を返す');
$openImage = 0;
try {
    take_page_image_file(str_repeat('e', 32), 1);
} catch (Tet2TestExit $e) {
    $openImage = $e->httpCode;
}
check($openImage === 404, 'A-2: 既定の配信のページの画像はテスト中も閉じない(文字の教材なのでページがなく 404)');

// ================================================================ A-3 期限後の受講
$late = optDelivery(['title' => '期限後も受講できる', 'deadline' => '2026-01-01', 'allow_after_deadline' => 1, 'pass_score' => 50]);
$withQuestions($late);
$lateTarget = optTarget('late@example.test');
$lateWaiting = optTarget('late-waiting@example.test');
optAssign($late, $lateTarget, str_repeat('f', 32), '2026-01-01 23:59:59');
optAssign($late, $lateWaiting, str_repeat('9', 32), '2026-01-01 23:59:59');
$closed = optDelivery(['title' => '期限後は受講できない', 'deadline' => '2026-01-01']);
$withQuestions($closed);
optAssign($closed, $lateTarget, str_repeat('8', 32), '2026-01-01 23:59:59');

check(takeGet('take_handle_start', str_repeat('8', 32))['code'] === 410, 'A-3: 既定の配信は期限の後に受講できない(410)');
$lateStart = takeGet('take_handle_start', str_repeat('f', 32));
check($lateStart['code'] === 200 && $lateStart['payload']['delivery']['after_deadline'] === true,
    'A-3: 期限後の受講を認める配信は期限の後も受講でき、画面に期限後と知らせる');
$lateSubmit = takePost('take_handle_submit', str_repeat('f', 32), ['answers' => [
    ['question_id' => $q1, 'answer' => [2]], ['question_id' => $q2, 'answer' => [0, 3]],
]]);
check($lateSubmit['payload']['result']['completed'] === true, 'A-3: 期限の後に完了できる');
$_GET = ['id' => (string) $late];
$people = call_handler('edu_rep_handle_delivery_people', [], 'viewer');
$latePerson = array_column($people['payload']['people'], null, 'target_id')[$lateTarget];
check($latePerson['passed'] === true && $latePerson['on_time'] === false && $latePerson['late'] === true,
    'A-3: 期限の後の完了はレポートで期限後(期限内合格に入らない)');
check($people['payload']['summary']['passed'] === 1 && $people['payload']['summary']['on_time_passed'] === 0,
    'A-3: 配信の期限内合格の数に入れない');
$_GET = ['id' => (string) $late];
$depts = call_handler('edu_rep_handle_delivery_depts', [], 'viewer');
check($depts['payload']['departments'][0]['on_time_pass_rate'] === 0.0 && $depts['payload']['departments'][0]['pass_rate'] === 50.0,
    'A-3: 部署ごとの期限内合格率にも入れない');
$todoTitles = array_column(LearnerPortal::todos($tenantId, $lateWaiting), 'title');
check(in_array('期限後も受講できる', $todoTitles, true), 'A-3: マイページの ToDo に期限後も受講できる配信を出す');
$closedTodo = array_column(LearnerPortal::todos($tenantId, $lateTarget), 'title');
check(!in_array('期限後は受講できない', $closedTodo, true), 'A-3: 既定の配信は期限切れで ToDo に出さない');
$waitingGrade = array_column(LearnerPortal::grades($tenantId, $lateWaiting)['deliveries'], null, 'title')['期限後も受講できる'];
check($waitingGrade['take_url'] !== null && $waitingGrade['expired'] === false && $waitingGrade['past_deadline'] === true,
    'A-3: マイページの成績から期限後も受講のリンクを開ける');

// ================================================================ A-4 テストから受け直す
$retake = optDelivery(['title' => 'テストから受け直す', 'material_id' => $materialId, 'retake_from_test' => 1]);
$withQuestions($retake);
$retakeTarget = optTarget('retake@example.test');
optAssign($retake, $retakeTarget, str_repeat('7', 32));
check(takeGet('take_handle_start', str_repeat('7', 32))['payload']['delivery']['start_at_test'] === false,
    'A-4: 初回は教材から始める');
takePost('take_handle_submit', str_repeat('7', 32), ['answers' => [['question_id' => $q1, 'answer' => [0]]]]);
$retakeStart = takeGet('take_handle_start', str_repeat('7', 32));
check($retakeStart['payload']['delivery']['start_at_test'] === true && $retakeStart['payload']['delivery']['retake_from_test'] === true,
    'A-4: 不合格の後はテストから始める');
$noRetake = optDelivery(['title' => '教材から受け直す', 'material_id' => $materialId]);
$withQuestions($noRetake);
optAssign($noRetake, $retakeTarget, str_repeat('6', 32));
takePost('take_handle_submit', str_repeat('6', 32), ['answers' => [['question_id' => $q1, 'answer' => [0]]]]);
check(takeGet('take_handle_start', str_repeat('6', 32))['payload']['delivery']['start_at_test'] === false,
    'A-4: 既定の配信は不合格の後も教材から');

// ================================================================ A-5 マイページ
$gradeA = LearnerPortal::grades($tenantId, $learnerA);
$gA = $gradeA['deliveries'][0];
check($gA['delivered_at'] !== null && $gA['correct_count'] === 2 && $gA['question_count'] === 2, 'A-5: 成績に配信日時と「正解 n/m」');
Db::run("UPDATE edu_deliveries SET scheduled_at = '2026-09-01 09:00:00' WHERE id = ?", [$shuffled]);
check(LearnerPortal::grades($tenantId, $learnerA)['deliveries'][0]['delivered_at'] === '2026-09-01 09:00:00', 'A-5: 予約の配信は予約の日時を配信日時にする');
$gradeB = LearnerPortal::grades($tenantId, $retakeTarget);
check($gradeB['summary'] === ['assigned' => 2, 'completed' => 0, 'completion_rate' => 0.0]
    && $gradeA['summary']['completion_rate'] === 100.0, 'A-5: 自分の受講完了率');
check(array_column($gradeB['deliveries'], 'correct_count', 'title')['テストから受け直す'] === 0, 'A-5: 不合格の回の正解の数');

check(takeGet('take_handle_start', str_repeat('7', 32))['payload']['portal_url'] === null, 'A-5: マイページのアカウントがない人には戻るリンクを返さない');
Db::run("INSERT INTO users (tenant_id, email, password_hash, role, status, password_pending, target_id) VALUES (1, 'retake@example.test', 'x', 'learner', 'active', 1, ?)", [$retakeTarget]);
check(takeGet('take_handle_start', str_repeat('7', 32))['payload']['portal_url'] === null, 'A-5: パスワードを設定していないアカウントには返さない');
Db::run("UPDATE users SET password_pending = 0 WHERE email = 'retake@example.test'");
check(takeGet('take_handle_start', str_repeat('7', 32))['payload']['portal_url'] === 'my.php', 'A-5: マイページのアカウントがある人には戻るリンクを返す');
Db::run("INSERT INTO users (tenant_id, email, password_hash, role, status) VALUES (1, 'LOCK@example.test', 'x', 'viewer', 'active')");
check(LearnerPortal::hasAccount($tenantId, (int) Db::one("SELECT id FROM targets WHERE email = 'lock@example.test'")['id']),
    'A-5: 同じメールアドレスの管理画面のユーザもマイページのアカウントとして数える');
check(LearnerPortal::ACCOUNT_ADMIN_ROLES === LearnerAuth::ADMIN_ROLES, 'A-5: アカウントの判定の役割は LearnerAuth と同じ');

check(LearnerPortal::contact($tenantId) === null, 'A-5: 問い合わせ先は未設定なら出さない');
check(call_handler('edu_d_handle_contact_update', ['edu_contact' => '情報システム部'], 'operator')['code'] === 403,
    'A-5: オペレータは問い合わせ先を変えられない');
$saved = call_handler('edu_d_handle_contact_update', ['edu_contact' => " 情報システム部\r\n内線 1234 "], 'tenant_admin');
check($saved['code'] === 200 && LearnerPortal::contact($tenantId) === "情報システム部\n内線 1234", 'A-5: 組織管理者は問い合わせ先を設定できる');
check(call_handler('edu_d_handle_contact_update', ['edu_contact' => str_repeat('あ', 501)], 'tenant_admin')['code'] === 400, 'A-5: 501文字以上は拒む');
$_GET = [];
$read = call_handler('edu_d_handle_contact_get', [], 'viewer');
check($read['payload']['edu_contact'] === "情報システム部\n内線 1234" && $read['payload']['can_edit'] === false, 'A-5: 閲覧者は読めるが変えられない');
check(call_handler('edu_d_handle_contact_update', ['edu_contact' => ''], 'tenant_admin')['code'] === 200 && LearnerPortal::contact($tenantId) === null,
    'A-5: 空にすると出さない');

echo "ALL TESTS PASSED\n";
