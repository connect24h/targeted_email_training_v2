<?php
declare(strict_types=1);

/**
 * edu_deliveries API の回帰テスト。
 * ED-1..ED-11, ER-3, ER-4 のシナリオをカバー。
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../lib/EduQuestionPicker.php';
require_once __DIR__ . '/../lib/EduMailer.php';

tet2_test_boot();
require_once __DIR__ . '/../lib/EduDeliveryLauncher.php';

// メールは送信口を差し替えて数える(TET2_EDU_MAIL_DISABLE に頼らない)。
$mails = [];
EduMailer::useTransport(static function (string $to) use (&$mails): bool {
    $mails[] = $to;
    return true;
});
load_api('edu_deliveries');

$tenantId = current_user()['tenant_id'];
$actor    = current_user();

// ---- シード: カテゴリ・設問・対象者・グループを用意 ----

// カテゴリ
Db::run(
    "INSERT INTO edu_categories (tenant_id, name, slug, is_active, is_shared) VALUES (?, 'ED テスト用カテゴリ', 'ed-test-cat-" . getmypid() . "', 1, 0)",
    [$tenantId]
);
$catId = (int) Db::one(
    'SELECT id FROM edu_categories WHERE tenant_id = ? ORDER BY id DESC LIMIT 1',
    [$tenantId]
)['id'];

// 設問(difficulty=2)
Db::run(
    "INSERT INTO edu_questions (tenant_id, category_id, title, question_type, options, correct_answer, difficulty, is_active, is_shared)
     VALUES (?, ?, 'ED テスト設問', 'single_choice', '[\"A\",\"B\"]', '[0]', 2, 1, 0)",
    [$tenantId, $catId]
);
$questionId = (int) Db::one(
    'SELECT id FROM edu_questions WHERE category_id = ? ORDER BY id DESC LIMIT 1',
    [$catId]
)['id'];

// 対象者: テナント内に active target が必要(all ターゲット用)
$target = Db::one("SELECT id FROM targets WHERE tenant_id = ? AND status = 'active' LIMIT 1", [$tenantId]);
if ($target === null) {
    Db::run(
        "INSERT INTO targets (tenant_id, email, name, status) VALUES (?, 'ed_test@example.com', 'ED テスト対象', 'active')",
        [$tenantId]
    );
}

// グループ(group target_type テスト用)
$group = Db::one('SELECT id FROM groups WHERE tenant_id = ? LIMIT 1', [$tenantId]);
if ($group === null) {
    Db::run("INSERT INTO groups (tenant_id, name, kind) VALUES (?, 'ED テストグループ', 'custom')", [$tenantId]);
    $group = Db::one('SELECT id FROM groups WHERE tenant_id = ? ORDER BY id DESC LIMIT 1', [$tenantId]);
}
$groupId = (int) $group['id'];

// 個別配信にはテスト宛先も明示選択できる。全対象者には含めない。
Db::run(
    "INSERT INTO targets (tenant_id, tenant_no, email, name, status, is_test)
     VALUES (?, 9999, 'ed-test-recipient@example.test', 'ED テスト宛先', 'active', 1)",
    [$tenantId]
);
$testTargetId = (int) Db::one(
    "SELECT id FROM targets WHERE tenant_id = ? AND is_test = 1 ORDER BY id DESC LIMIT 1",
    [$tenantId]
)['id'];
$realTargetId = (int) Db::one(
    "SELECT id FROM targets WHERE tenant_id = ? AND status = 'active' AND is_test = 0 ORDER BY id LIMIT 1",
    [$tenantId]
)['id'];
Db::run(
    "INSERT INTO edu_materials (tenant_id, title, slides, is_active)
     VALUES (?, 'ED テスト教材', '[{\"title\":\"教材\",\"body\":\"本文\"}]', 1)",
    [$tenantId]
);
$materialId = (int) Db::one('SELECT id FROM edu_materials WHERE tenant_id = ? ORDER BY id DESC LIMIT 1', [$tenantId])['id'];

// ---- ED-1: elearning + all + pass_score=70 → 201 ----
$r = call_handler('edu_d_handle_create', [
    'title'         => 'ED-1 elearning テスト',
    'delivery_type' => 'elearning',
    'target_type'   => 'all',
    'pass_score'    => 70,
    'material_id'   => $materialId,
], 'operator');
check($r['code'] === 201, 'ED-1: elearning + all + pass_score=70 → 201');
check(($r['payload']['delivery']['delivery_type'] ?? '') === 'elearning', 'ED-1: delivery_type が保存される');
check((int) ($r['payload']['delivery']['pass_score'] ?? 0) === 70, 'ED-1: pass_score=70 が保存される');
check((int) ($r['payload']['delivery']['material_id'] ?? 0) === $materialId, 'ED-1: スライド教材を保存する');
$ed1Id = (int) $r['payload']['delivery']['id'];
check(($r['payload']['delivery']['feedback_mode'] ?? '') === 'after_submit', 'ED-FB-1: eラーニングの既定は提出後にまとめて答え合わせ');
$fb = call_handler('edu_d_handle_create', [
    'title' => 'ED-FB 小問', 'delivery_type' => 'awareness_quiz', 'target_type' => 'all',
], 'operator');
check(($fb['payload']['delivery']['feedback_mode'] ?? '') === 'immediate', 'ED-FB-2: 小問の既定は1問ごとの答え合わせ');
$fb = call_handler('edu_d_handle_create', [
    'title' => 'ED-FB 指定', 'delivery_type' => 'elearning', 'target_type' => 'all', 'pass_score' => 60, 'feedback_mode' => 'immediate',
], 'operator');
check(($fb['payload']['delivery']['feedback_mode'] ?? '') === 'immediate', 'ED-FB-3: 答え合わせの時機を指定できる');
$fb = call_handler('edu_d_handle_create', [
    'title' => 'ED-FB 不正', 'delivery_type' => 'elearning', 'target_type' => 'all', 'pass_score' => 60, 'feedback_mode' => 'later',
], 'operator');
check($fb['code'] === 400, 'ED-FB-4: 不正な答え合わせの時機を拒否する');

// ---- ED-2: elearning + all + no pass_score → 400 ----
$r = call_handler('edu_d_handle_create', [
    'title'         => 'ED-2 elearning no pass_score',
    'delivery_type' => 'elearning',
    'target_type'   => 'all',
], 'operator');
check($r['code'] === 400, 'ED-2: elearning without pass_score → 400');

// ---- ED-6: awareness_quiz + all → 201 ----
$r = call_handler('edu_d_handle_create', [
    'title'         => 'ED-6 awareness_quiz テスト',
    'delivery_type' => 'awareness_quiz',
    'target_type'   => 'all',
], 'operator');
check($r['code'] === 201, 'ED-6: awareness_quiz + all → 201');
$ed6Id = (int) $r['payload']['delivery']['id'];

// ---- ED-7: individual は通常対象者とテスト宛先を明示保存する ----
$r = call_handler('edu_d_handle_create', [
    'title'         => 'ED-7 個別配信テスト',
    'delivery_type' => 'elearning',
    'target_type'   => 'individual',
    'target_ids'    => [$realTargetId, $testTargetId],
    'pass_score'    => 80,
], 'operator');
check($r['code'] === 201, 'ED-7: individual + target_ids → 201');
$ed7Id = (int) $r['payload']['delivery']['id'];
$ed7 = edu_d_assert_owned($ed7Id, $tenantId);
check(edu_d_resolve_targets($ed7, $tenantId) === [$realTargetId, $testTargetId],
    'ED-7: 通常対象者とテスト宛先を個別対象として解決する');

$r = call_handler('edu_d_handle_create', [
    'title'         => 'ED-7b 個別対象なし',
    'delivery_type' => 'elearning',
    'target_type'   => 'individual',
    'target_ids'    => [],
    'pass_score'    => 80,
], 'operator');
check($r['code'] === 400, 'ED-7b: individual はtarget_ids必須');

$r = call_handler('edu_d_handle_create', [
    'title'         => 'ED-7c 他テナント対象',
    'delivery_type' => 'elearning',
    'target_type'   => 'individual',
    'target_ids'    => [3],
    'pass_score'    => 80,
], 'operator');
check($r['code'] === 404, 'ED-7c: 他テナント対象者を拒否する');

$allTargets = edu_d_resolve_targets(edu_d_assert_owned($ed1Id, $tenantId), $tenantId);
check(!in_array($testTargetId, $allTargets, true), 'ED-7d: 全対象者からテスト宛先を除外する');

// ---- ED-8: invalid delivery_type → 400 ----
$r = call_handler('edu_d_handle_create', [
    'title'         => 'ED-8 不正タイプ',
    'delivery_type' => 'invalid_type',
    'target_type'   => 'all',
], 'operator');
check($r['code'] === 400, 'ED-8: invalid delivery_type → 400');

// ---- ED-9: invalid target_type → 400 ----
$r = call_handler('edu_d_handle_create', [
    'title'         => 'ED-9 不正ターゲット',
    'delivery_type' => 'awareness_quiz',
    'target_type'   => 'invalid_target',
], 'operator');
check($r['code'] === 400, 'ED-9: invalid target_type → 400');

// ---- ED-10: difficulty_range=[1,3] → 201 ----
$r = call_handler('edu_d_handle_create', [
    'title'            => 'ED-10 difficulty_range テスト',
    'delivery_type'    => 'awareness_quiz',
    'target_type'      => 'all',
    'difficulty_range' => [1, 3],
], 'operator');
check($r['code'] === 201, 'ED-10: difficulty_range=[1,3] → 201');

// ---- ED-11: difficulty_range=[3,1] (min>max) → 400 ----
$r = call_handler('edu_d_handle_create', [
    'title'            => 'ED-11 不正 difficulty_range',
    'delivery_type'    => 'awareness_quiz',
    'target_type'      => 'all',
    'difficulty_range' => [3, 1],
], 'operator');
check($r['code'] === 400, 'ED-11: difficulty_range=[3,1] (min>max) → 400');

// ---- ER-3: update draft → 200 ----
$r = call_handler('edu_d_handle_update', [
    'id'        => $ed1Id,
    'pass_score' => 80,
], 'operator');
check($r['code'] === 200, 'ER-3: update draft delivery → 200');
check((int) ($r['payload']['delivery']['pass_score'] ?? 0) === 80, 'ER-3: pass_score が更新される');

// ---- ER-4: update running → 409 ----
// ED-6 の配信を手動で running に変更して編集を試みる
Db::run("UPDATE edu_deliveries SET status = 'running' WHERE id = ?", [$ed6Id]);
$r = call_handler('edu_d_handle_update', [
    'id'    => $ed6Id,
    'title' => '起動済み更新試み',
], 'operator');
check($r['code'] === 409, 'ER-4: update running delivery → 409');

// --- 受講トークンの有効期限は配信の deadline から決める ---
// 検証コードは edu_take.php にあったのに、セットする側が無く実測では全件 NULL
// (受講リンクが事実上無期限)だった。締切が機能していなかったのを閉じる。
check(edu_d_token_expiry(['deadline' => '2026-08-31']) === '2026-08-31 23:59:59',
    'ER-5: 日付のみの締切はその日いっぱいを有効期限にする');
check(edu_d_token_expiry(['deadline' => '2026-08-31 18:00:00']) === '2026-08-31 18:00:00',
    'ER-5: 時刻付きの締切はそのまま使う');
check(edu_d_token_expiry(['deadline' => null]) === null, 'ER-5: 締切がなければ無期限');
check(edu_d_token_expiry(['deadline' => '  ']) === null, 'ER-5: 空白だけの締切は無期限として扱う');
check(edu_d_token_expiry([]) === null, 'ER-5: deadline 列が無い配信でも落ちない');

// launch の transaction closure でも配信情報を参照でき、割当と期限設定を完了する。
// 案内メールを送る設定(send_invites=1)の配信だけ、宛先解決からメール成功件数まで通す。
check((int) Db::one('SELECT send_invites FROM edu_deliveries WHERE id = ?', [$ed7Id])['send_invites'] === 0,
    'SI-1: 案内メールは既定で送らない設定になる');
Db::run("UPDATE edu_deliveries SET deadline = '2030-01-02 03:04:05', send_invites = 1 WHERE id = ?", [$ed7Id]);
$r = call_handler('edu_d_handle_launch', ['id' => $ed7Id], 'operator');
check($r['code'] === 200, 'ED-12: 個別配信をlaunchできる');
check((int) ($r['payload']['assigned'] ?? 0) === 2, 'ED-12: 指定した2名だけを割り当てる');
check((int) ($r['payload']['mail_sent'] ?? 0) === 2, 'ED-12: 新規割当2名のメール送信成功数を返す');
check(count($mails) === 2, 'ED-12: send_invites=1 の配信は EduMailer で2通送る');
$r = call_handler('edu_d_handle_launch', ['id' => $ed7Id], 'operator');
check($r['code'] === 409, 'ED-12: 開始済みの配信は再び開始できない');

// ---- 案内メールの既定は送らない。手動の開始もこの値に従う ----
$r = call_handler('edu_d_handle_create', [
    'title' => 'SI メールなし', 'delivery_type' => 'awareness_quiz', 'target_type' => 'all',
], 'operator');
check((int) ($r['payload']['delivery']['send_invites'] ?? -1) === 0, 'SI-2: 作成時の既定は送らない(send_invites=0)');
$mails = [];
$r = call_handler('edu_d_handle_launch', ['id' => (int) $r['payload']['delivery']['id']], 'operator');
check($r['code'] === 200 && (int) $r['payload']['assigned'] >= 1, 'SI-2: メールなしの配信も開始できる');
check((int) $r['payload']['mail_sent'] === 0 && $mails === [], 'SI-2: send_invites=0 の手動の開始では EduMailer を呼ばない');
$r = call_handler('edu_d_handle_create', [
    'title' => 'SI メールあり', 'delivery_type' => 'awareness_quiz', 'target_type' => 'all', 'send_invites' => true,
], 'operator');
check((int) ($r['payload']['delivery']['send_invites'] ?? 0) === 1, 'SI-3: 案内メールを送る設定を保存できる');
$r = call_handler('edu_d_handle_create', [
    'title' => 'SI 不正', 'delivery_type' => 'awareness_quiz', 'target_type' => 'all', 'send_invites' => 'yes',
], 'operator');
check($r['code'] === 400, 'SI-4: send_invites は真偽値か 0/1 だけ受け付ける');

// ---- F0: 作成時に予約の日時を入れると予約(scheduled)になる ----
$r = call_handler('edu_d_handle_create', [
    'title' => 'SC 予約', 'delivery_type' => 'awareness_quiz', 'target_type' => 'all',
    'scheduled_at' => '2030-04-01T09:00', 'deadline' => '2030-04-15',
], 'operator');
check($r['code'] === 201 && ($r['payload']['delivery']['status'] ?? '') === 'scheduled', 'SC-1: 予約の日時があると status=scheduled で作る');
check(($r['payload']['delivery']['scheduled_at'] ?? '') === '2030-04-01 09:00:00', 'SC-1: 予約の日時を秒まで揃えて保存する');
check(($r['payload']['delivery']['deadline'] ?? '') === '2030-04-15', 'SC-1: 締切を保存する');
$scheduledId = (int) $r['payload']['delivery']['id'];
$r = call_handler('edu_d_handle_create', [
    'title' => 'SC 不正', 'delivery_type' => 'awareness_quiz', 'target_type' => 'all', 'scheduled_at' => 'あした',
], 'operator');
check($r['code'] === 400, 'SC-2: 日時として読めない予約を拒否する');
$r = call_handler('edu_d_handle_create', [
    'title' => 'SC 逆転', 'delivery_type' => 'awareness_quiz', 'target_type' => 'all',
    'scheduled_at' => '2030-04-20 09:00', 'deadline' => '2030-04-15',
], 'operator');
check($r['code'] === 400, 'SC-3: 締切が予約の日時より前なら拒否する');
$r = call_handler('edu_d_handle_update', ['id' => $scheduledId, 'scheduled_at' => '2030-04-10 09:00'], 'operator');
check($r['code'] === 200 && ($r['payload']['delivery']['scheduled_at'] ?? '') === '2030-04-10 09:00:00', 'SC-4: 予約中の配信は日時を変えられる');
$r = call_handler('edu_d_handle_update', ['id' => $scheduledId, 'scheduled_at' => '2030-05-01 10:00'], 'operator');
check($r['code'] === 400, 'SC-4: 締切より後へ予約を動かすことは拒否する');
$r = call_handler('edu_d_handle_update', ['id' => $scheduledId, 'scheduled_at' => '2030-05-01 10:00', 'deadline' => '2030-05-15'], 'operator');
check($r['code'] === 200 && ($r['payload']['delivery']['scheduled_at'] ?? '') === '2030-05-01 10:00:00',
    'SC-4: 予約中の配信は日時を変えられる');
$r = call_handler('edu_d_handle_create', [
    'title' => 'SC 下書き', 'delivery_type' => 'awareness_quiz', 'target_type' => 'all',
], 'operator');
$draftId = (int) $r['payload']['delivery']['id'];
$r = call_handler('edu_d_handle_update', ['id' => $draftId, 'scheduled_at' => '2030-06-01 09:00'], 'operator');
check(($r['payload']['delivery']['status'] ?? '') === 'scheduled', 'SC-5: 下書きに予約の日時を入れると予約になる');
$r = call_handler('edu_d_handle_update', ['id' => $draftId, 'send_invites' => true], 'operator');
check((int) ($r['payload']['delivery']['send_invites'] ?? 0) === 1, 'SC-6: 案内メールの設定を編集で変えられる');
$mails = [];
$r = call_handler('edu_d_handle_launch', ['id' => $scheduledId], 'operator');
check($r['code'] === 200 && ($r['payload']['status'] ?? '') === 'running', 'SC-7: 予約中の配信を画面から今すぐ開始できる');
check($mails === [], 'SC-7: 案内メールなしの予約の配信はメールを送らない');
$launchedAssignments = Db::all(
    'SELECT target_id, status, token_expiry FROM edu_assignments WHERE delivery_id = ? ORDER BY target_id',
    [$ed7Id]
);
check(array_column($launchedAssignments, 'target_id') === [$realTargetId, $testTargetId],
    'ED-12: launch対象が個別指定から増減しない');
check(array_column($launchedAssignments, 'status') === ['assigned', 'assigned'],
    'ED-12: 新規割当はassignedで開始する');
check(array_column($launchedAssignments, 'token_expiry') === ['2030-01-02 03:04:05', '2030-01-02 03:04:05'],
    'ED-12: 配信deadlineを全受講トークンへ設定する');

// --- triggered_by と target_type の整合性 ---
// phishing_failure は EduAutoEnroll が対象を自動決定するので risk 限定。
// 他と組み合わせると手動確定した対象と自動投入が二重に走る。
$r = call_handler('edu_d_handle_create', [
    'title' => '自動連携と全員配信の併用', 'delivery_type' => 'awareness_quiz',
    'target_type' => 'all', 'triggered_by' => 'phishing_failure',
], 'operator');
check($r['code'] === 400, 'ER-5: phishing_failure と target_type=all の併用を拒否する');

$r = call_handler('edu_d_handle_create', [
    'title' => '自動連携', 'delivery_type' => 'awareness_quiz',
    'target_type' => 'risk', 'triggered_by' => 'phishing_failure',
], 'operator');
check($r['code'] === 201, 'ER-5: phishing_failure と target_type=risk は作成できる');
check(($r['payload']['delivery']['triggered_by'] ?? '') === 'phishing_failure',
    'ER-5: triggered_by が保存される(ハードコードされていない)');

$r = call_handler('edu_d_handle_create', [
    'title' => '不正なトリガー', 'delivery_type' => 'awareness_quiz',
    'target_type' => 'risk', 'triggered_by' => 'unknown_trigger',
], 'operator');
check($r['code'] === 400, 'ER-5: 未知の triggered_by を拒否する');

EduMailer::useTransport(null);
echo "ALL TESTS PASSED\n";
