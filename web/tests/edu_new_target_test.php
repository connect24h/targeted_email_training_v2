<?php
declare(strict_types=1);

/**
 * 新入社員への自動の出題(F5)の回帰テスト。
 * triggered_by='new_target' の running の配信に、登録(targets.created_at)から N 日以内の対象者を入れる。
 */

require_once __DIR__ . '/helpers.php';

tet2_test_boot();
require_once __DIR__ . '/../lib/EduMailer.php';
require_once __DIR__ . '/../lib/EduScheduler.php';
load_api('edu_deliveries');

$mails = [];
EduMailer::useTransport(static function (string $to) use (&$mails): bool {
    $mails[] = $to;
    return true;
});
$now = new DateTimeImmutable('2026-10-01 09:00:00', new DateTimeZone('Asia/Tokyo'));

$categoryId = Db::insert(
    "INSERT INTO edu_categories (tenant_id, name, slug, is_active, is_shared) VALUES (NULL, '入社', 'aw-newcomer', 1, 1)"
);
Db::insert(
    "INSERT INTO edu_questions (tenant_id, category_id, title, options, correct_answer, difficulty, is_active, is_shared)
     VALUES (NULL, ?, '入社の日の設問', '[\"A\",\"B\"]', '[0]', 1, 1, 1)",
    [$categoryId]
);
Db::run("UPDATE targets SET created_at = '2020-01-01 00:00:00'");

function ntTarget(string $email, string $createdAt, array $overrides = []): int
{
    $d = array_merge(['status' => 'active', 'is_test' => 0, 'position' => null], $overrides);
    return Db::insert(
        'INSERT INTO targets (tenant_id, email, name, status, is_test, position_category, created_at) VALUES (1, ?, ?, ?, ?, ?, ?)',
        [$email, $email, $d['status'], $d['is_test'], $d['position'], $createdAt]
    );
}

function ntAssigned(int $deliveryId): array
{
    return array_map('intval', array_column(
        Db::all('SELECT target_id FROM edu_assignments WHERE delivery_id = ? ORDER BY target_id', [$deliveryId]),
        'target_id'
    ));
}

// ---- API: 登録から N 日以内の日数を持たせる ----
$base = ['title' => '入社の日の小問', 'delivery_type' => 'awareness_quiz', 'target_type' => 'all', 'triggered_by' => 'new_target'];
$r = call_handler('edu_d_handle_create', $base + ['new_target_days' => 30], 'operator');
check($r['code'] === 201, 'F5-1: triggered_by=new_target の配信を作れる');
check((int) $r['payload']['delivery']['new_target_days'] === 30 && $r['payload']['delivery']['triggered_by'] === 'new_target',
    'F5-1: 登録から N 日以内の日数を保存する');
$apiDelivery = (int) $r['payload']['delivery']['id'];
foreach ([[], ['new_target_days' => 0], ['new_target_days' => 366]] as $bad) {
    $r = call_handler('edu_d_handle_create', $base + $bad, 'operator');
    check($r['code'] === 400, 'F5-2: 日数がないか範囲外(1〜365)なら拒否する ' . json_encode($bad));
}
foreach (['risk', 'individual'] as $type) {
    $r = call_handler('edu_d_handle_create', ['target_type' => $type, 'target_ids' => [1]] + $base + ['new_target_days' => 30], 'operator');
    check($r['code'] === 400, 'F5-3: 新入社員の配信は ' . $type . ' と組み合わせない');
}
$r = call_handler('edu_d_handle_create', ['title' => '手動', 'delivery_type' => 'awareness_quiz', 'target_type' => 'all', 'new_target_days' => 30], 'operator');
check($r['code'] === 201 && $r['payload']['delivery']['new_target_days'] === null, 'F5-4: 新入社員の配信でなければ日数を保存しない');

// 開始の時点で新入社員がいなくても開始でき、以後は CLI が投入する
$r = call_handler('edu_d_handle_launch', ['id' => $apiDelivery], 'operator');
check($r['code'] === 200 && (int) $r['payload']['assigned'] === 0, 'F5-5: 新入社員がまだいなくても開始できる');

// ---- CLI: running の配信に、登録から N 日以内の対象者を入れる ----
$newbie   = ntTarget('newbie@example.test', '2026-09-20 10:00:00');
$old      = ntTarget('old@example.test', '2026-08-31 10:00:00');
$newTest  = ntTarget('newbie-test@example.test', '2026-09-25 10:00:00', ['is_test' => 1]);
$newGone  = ntTarget('newbie-gone@example.test', '2026-09-25 10:00:00', ['status' => 'archived']);
$newExec  = ntTarget('newbie-exec@example.test', '2026-09-28 10:00:00', ['position' => '役員']);

function ntDelivery(array $overrides = []): int
{
    $d = array_merge(['status' => 'running', 'send_invites' => 0, 'deadline' => null, 'target_type' => 'all', 'target_positions' => null], $overrides);
    $id = Db::insert(
        "INSERT INTO edu_deliveries
         (tenant_id, title, status, delivery_type, question_count, randomize, target_type, target_positions,
          triggered_by, new_target_days, send_invites, deadline, created_by)
         VALUES (1, '入社の日の小問', ?, 'awareness_quiz', 1, 0, ?, ?, 'new_target', 30, ?, ?, 1)",
        [$d['status'], $d['target_type'], $d['target_positions'], $d['send_invites'], $d['deadline']]
    );
    $questionId = (int) Db::one('SELECT id FROM edu_questions ORDER BY id LIMIT 1')['id'];
    Db::run('INSERT INTO edu_delivery_questions (delivery_id, question_id, sort_order) VALUES (?, ?, 0)', [$id, $questionId]);
    return $id;
}

Db::run("UPDATE edu_deliveries SET status = 'done' WHERE id = ?", [$apiDelivery]);
$quiet    = ntDelivery();
$execOnly = ntDelivery(['target_type' => 'position', 'target_positions' => '["役員"]']);
$expired  = ntDelivery(['deadline' => '2026-09-30']);
$draft    = ntDelivery(['status' => 'draft']);
// 既に割当のある人は二重に入れない
Db::run(
    "INSERT INTO edu_assignments (tenant_id, delivery_id, target_id, access_token, status) VALUES (1, ?, ?, ?, 'completed')",
    [$quiet, $newExec, str_repeat('a', 32)]
);

$result = EduScheduler::run($now);
check(ntAssigned($quiet) === [$newbie, $newExec], 'F5-6: 登録から30日以内で active かつ is_test=0、まだ割当のない対象者を入れる');
check(ntAssigned($execOnly) === [$newExec], 'F5-7: 役職の配信なら、その役職区分の新入社員だけを入れる');
check(ntAssigned($expired) === [], 'F5-8: 締切を過ぎた配信には入れない');
check(ntAssigned($draft) === [], 'F5-9: 開始前の配信には入れない');
check($result['new_target_assigned'] === 2, 'F5-10: 結果に新入社員の割当の件数を返す');
check($mails === [], 'F5-11: send_invites=0 の配信では EduMailer の送信が1回も呼ばれない');
$audit = Db::one("SELECT user_id, detail FROM audit_log WHERE action = 'edu_scheduler.new_target_enroll' ORDER BY id LIMIT 1");
check($audit !== null && $audit['user_id'] === null, 'F5-12: 自動の投入を監査ログ(user_id NULL)に残す');
$token = Db::one('SELECT token_expiry FROM edu_assignments WHERE delivery_id = ? AND target_id = ?', [$quiet, $newbie]);
check($token !== null, 'F5-12: 割当に受講トークンを発行する');

$again = EduScheduler::run($now);
check($again['new_target_assigned'] === 0 && ntAssigned($quiet) === [$newbie, $newExec], 'F5-13: 再実行しても二重に入れない(冪等)');

// send_invites=1 の配信だけ、投入時に案内メールを送る
$loud = ntDelivery(['send_invites' => 1]);
$loudResult = EduScheduler::run($now);
check(ntAssigned($loud) === [$newbie, $newExec], 'F5-14: 案内メールありの配信にも入れる');
check(count($mails) === 2 && $loudResult['mail_sent'] === 2, 'F5-14: send_invites=1 の配信は投入した2名へ案内メールを送る');

EduMailer::useTransport(null);
echo "ALL TESTS PASSED\n";
