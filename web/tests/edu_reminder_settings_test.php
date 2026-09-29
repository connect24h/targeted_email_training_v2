<?php
declare(strict_types=1);

/**
 * 自動の催促の設定(段D の D1、G34)のテスト。合成 DB と .test ドメインだけ。メールは送信口の差し替えで数える。
 *
 *   SC-*  予定の計算(EduReminder::isDue): 既定は今までと同じ(3日ごと、期限まで)、期限の何日前から、何日ごと、
 *         期限の後も送る(上限の回数まで)、上限の回数
 *   RUN-* EduReminder::run が配信の設定どおりに送り、回数を数える。設定のない配信は今までと同じ
 *   API-* 設定の保存(作成、編集、開始後の変更)、検証、権限、CSRF、テナントの分離
 */
require_once __DIR__ . '/helpers.php';
tet2_test_boot();
putenv('TET2_EDU_MAIL_DISABLE=1');
putenv('TET2_REMIND_INTERVAL_DAYS');
require_once __DIR__ . '/../lib/EduMailer.php';
require_once __DIR__ . '/../lib/EduReminder.php';
require_once __DIR__ . '/../lib/EduDeliveryLauncher.php';
require_once __DIR__ . '/../lib/EduDeliverySeries.php';
load_api('edu_deliveries');

$GLOBALS['__MAILS'] = [];
EduMailer::useTransport(static function (string $to, string $subject, string $body): bool {
    $GLOBALS['__MAILS'][] = $to;
    return true;
});
function takeMails(): array
{
    $m = $GLOBALS['__MAILS'];
    $GLOBALS['__MAILS'] = [];
    sort($m);
    return $m;
}

// ---------------------------------------------------------------------------
// 予定の計算
// ---------------------------------------------------------------------------
$now = '2026-10-10 09:00:00';
$def = ['deadline' => null, 'start_days' => null, 'interval_days' => 3, 'after_deadline' => false, 'max_count' => null];
$due = static fn(array $s, ?string $last, int $count = 0): bool => EduReminder::isDue($s + $def, $last, $count, $now);

check($due([], null) && $due([], '2026-10-07 09:00:00') && !$due([], '2026-10-07 09:00:01') && !$due([], '2026-10-09 09:00:00'),
    'SC-1: 既定は3日ごと(前回からちょうど3日で送り、3日たたなければ送らない)。期限なしでも送る');
check($due(['deadline' => '2026-10-10 09:00:00'], null) && !$due(['deadline' => '2026-10-10 08:59:59'], null),
    'SC-2: 既定は期限まで(期限を過ぎたら送らない)');
check(!$due(['deadline' => '2026-10-10'], null) && $due(['deadline' => '2026-10-11'], null),
    'SC-3: 日付だけの期限は、以前の SQL と同じくその日の 0 時を過ぎたら送らない(既定の動きを変えない)');
check(!$due(['deadline' => '2026-10-15 17:00:00', 'start_days' => 3], null) && $due(['deadline' => '2026-10-13 09:00:00', 'start_days' => 3], null)
    && $due(['deadline' => '2026-10-12 17:00:00', 'start_days' => 3], null) && $due(['start_days' => 3], null),
    'SC-4: 期限の3日前から送る(それより前は送らない。期限なしの配信には効かない)');
check($due(['interval_days' => 1], '2026-10-09 09:00:00') && !$due(['interval_days' => 7], '2026-10-04 09:00:00')
    && $due(['interval_days' => 7], '2026-10-03 09:00:00'), 'SC-5: 何日ごとを配信で決められる');
$after = ['deadline' => '2026-10-05 17:00:00', 'after_deadline' => true, 'max_count' => 3];
check($due($after, '2026-10-07 09:00:00', 2) && !$due($after, '2026-10-07 09:00:00', 3) && !$due($after, '2026-10-08 09:00:00', 2),
    'SC-6: 期限の後も送る時は、上限の回数まで(間隔も守る)');
check(!$due(['deadline' => '2026-10-05 17:00:00', 'after_deadline' => true, 'max_count' => null], null),
    'SC-7: 上限の回数がなければ、期限の後は送らない(送り続けない)');
check(!$due(['max_count' => 2], null, 2) && $due(['max_count' => 2], null, 1), 'SC-8: 上限の回数は期限の前にも効く');

// ---------------------------------------------------------------------------
// EduReminder::run
// ---------------------------------------------------------------------------
$tA = Db::insert("INSERT INTO targets (tenant_id, email, name, status) VALUES (1, 'ra@example.test', 'RA', 'active')");
$tB = Db::insert("INSERT INTO targets (tenant_id, email, name, status) VALUES (1, 'rb@example.test', 'RB', 'active')");
$tC = Db::insert("INSERT INTO targets (tenant_id, email, name, status) VALUES (1, 'rc@example.test', 'RC', 'active')");
$delivery = static function (string $title, array $cols = []): int {
    $cols += ['deadline' => null];
    $names = array_keys($cols);
    return Db::insert("INSERT INTO edu_deliveries (tenant_id, title, status, delivery_type, target_type, " . implode(', ', $names) . ")
        VALUES (1, ?, 'running', 'awareness_quiz', 'individual', " . implode(', ', array_fill(0, count($names), '?')) . ')',
        array_merge([$title], array_values($cols)));
};
$assign = static fn(int $d, int $t, string $tok): int => Db::insert(
    "INSERT INTO edu_assignments (tenant_id, delivery_id, target_id, access_token, status) VALUES (1, ?, ?, ?, 'assigned')", [$d, $t, $tok]);
$dLegacy = $delivery('既定の配信', ['deadline' => date('Y-m-d H:i:s', time() + 5 * 86400)]);
$dLate = $delivery('期限後も', ['deadline' => date('Y-m-d H:i:s', time() - 86400), 'allow_after_deadline' => 1,
    'remind_after_deadline' => 1, 'remind_max_count' => 2, 'remind_interval_days' => 1]);
$dNear = $delivery('3日前から', ['deadline' => date('Y-m-d H:i:s', time() + 10 * 86400), 'remind_start_days' => 3]);
$aLegacy = $assign($dLegacy, $tA, str_repeat('a', 32));
$aLate = $assign($dLate, $tB, str_repeat('b', 32));
$assign($dNear, $tC, str_repeat('c', 32));
$r = EduReminder::run();
check(takeMails() === ['ra@example.test', 'rb@example.test'] && $r['sent'] === 2 && $r['skipped_recent'] === 1,
    'RUN-1: 設定のない配信は今までどおり送り、期限の後も送る配信は送り、期限の3日前より前の配信は送らない');
check((int) Db::one('SELECT remind_count FROM edu_assignments WHERE id = ?', [$aLegacy])['remind_count'] === 1, 'RUN-2: 送った回数を数える');
check(EduReminder::run()['sent'] === 0 && takeMails() === [], 'RUN-3: 間隔の前には送らない');
Db::run("UPDATE edu_assignments SET last_reminded_at = datetime('now','localtime','-1 days') WHERE id IN (?, ?)", [$aLegacy, $aLate]);
check(EduReminder::run()['sent'] === 1 && takeMails() === ['rb@example.test'], 'RUN-4: 1日ごとの配信は1日で送り、既定の配信は3日待つ');
Db::run("UPDATE edu_assignments SET last_reminded_at = datetime('now','localtime','-1 days') WHERE id = ?", [$aLate]);
check(EduReminder::run()['sent'] === 0 && takeMails() === [], 'RUN-5: 期限の後は上限の2回で止まる');

// ---------------------------------------------------------------------------
// API
// ---------------------------------------------------------------------------
$dDraft = Db::insert("INSERT INTO edu_deliveries (tenant_id, title, status, delivery_type, target_type) VALUES (1, '下書き', 'draft', 'awareness_quiz', 'all')");
$dOther = Db::insert("INSERT INTO edu_deliveries (tenant_id, title, status, delivery_type, target_type) VALUES (2, 'ほか', 'running', 'awareness_quiz', 'all')");
$dDone = Db::insert("INSERT INTO edu_deliveries (tenant_id, title, status, delivery_type, target_type) VALUES (1, '終わり', 'done', 'awareness_quiz', 'all')");
$body = ['id' => $dNear, 'remind_start_days' => 5, 'remind_interval_days' => 2, 'remind_after_deadline' => false, 'remind_max_count' => 4];
check(call_handler('edu_d_handle_reminder', $body, 'viewer')['code'] === 403, 'API-1: 閲覧者は催促の設定を変えられない');
$r = call_handler('edu_d_handle_reminder', $body, 'operator');
$row = Db::one('SELECT remind_start_days, remind_interval_days, remind_after_deadline, remind_max_count FROM edu_deliveries WHERE id = ?', [$dNear]);
check($r['code'] === 200 && $GLOBALS['__TET2_TEST_CSRF_CALLS'] === 1 && $row === ['remind_start_days' => 5, 'remind_interval_days' => 2,
    'remind_after_deadline' => 0, 'remind_max_count' => 4] && ($GLOBALS['__TET2_TEST_AUDIT'][0]['action'] ?? '') === 'edu_delivery.reminder_settings'
    && takeMails() === [], 'API-2: オペレータは開始後の配信の催促の設定を CSRF を通して変えられ、監査に残り、送らない');
$r = call_handler('edu_d_handle_reminder', ['id' => $dNear, 'remind_start_days' => null, 'remind_interval_days' => null,
    'remind_after_deadline' => false, 'remind_max_count' => null], 'operator');
$row = Db::one('SELECT remind_start_days, remind_interval_days, remind_max_count FROM edu_deliveries WHERE id = ?', [$dNear]);
check($r['code'] === 200 && $row === ['remind_start_days' => null, 'remind_interval_days' => null, 'remind_max_count' => null],
    'API-3: 空にすると既定(今までと同じ)に戻せる');
check(call_handler('edu_d_handle_reminder', ['id' => $dNear, 'remind_after_deadline' => true], 'operator')['code'] === 400,
    'API-4: 期限の後も送る時は上限の回数が要る');
check(call_handler('edu_d_handle_reminder', ['id' => $dNear, 'remind_after_deadline' => true, 'remind_max_count' => 2], 'operator')['code'] === 400,
    'API-5: 期限の後の受講を許さない配信では、期限の後も送る設定にできない');
check(call_handler('edu_d_handle_reminder', ['id' => $dNear, 'remind_interval_days' => 0], 'operator')['code'] === 400
    && call_handler('edu_d_handle_reminder', ['id' => $dNear, 'remind_interval_days' => 31], 'operator')['code'] === 400
    && call_handler('edu_d_handle_reminder', ['id' => $dNear, 'remind_start_days' => '3'], 'operator')['code'] === 400
    && call_handler('edu_d_handle_reminder', ['id' => $dNear, 'remind_max_count' => 21], 'operator')['code'] === 400
    && call_handler('edu_d_handle_reminder', ['id' => $dNear], 'operator')['code'] === 400, 'API-6: 数の範囲と形を確かめる');
check(call_handler('edu_d_handle_reminder', ['id' => $dOther, 'remind_interval_days' => 2], 'operator')['code'] === 404
    && Db::one('SELECT remind_interval_days FROM edu_deliveries WHERE id = ?', [$dOther])['remind_interval_days'] === null,
    'API-7: ほかのテナントの配信は変えられない');
check(call_handler('edu_d_handle_reminder', ['id' => $dDone, 'remind_interval_days' => 2], 'operator')['code'] === 409, 'API-8: 終わった配信は変えられない');
$r = call_handler('edu_d_handle_update', ['id' => $dDraft, 'allow_after_deadline' => true, 'remind_after_deadline' => true, 'remind_max_count' => 2,
    'remind_interval_days' => 5], 'operator');
$row = Db::one('SELECT allow_after_deadline, remind_after_deadline, remind_max_count, remind_interval_days FROM edu_deliveries WHERE id = ?', [$dDraft]);
check($r['code'] === 200 && $row === ['allow_after_deadline' => 1, 'remind_after_deadline' => 1, 'remind_max_count' => 2, 'remind_interval_days' => 5],
    'API-9: 下書きの編集で、期限の後の受講と一緒に催促の設定を保存できる');
check(call_handler('edu_d_handle_update', ['id' => $dDraft, 'allow_after_deadline' => false], 'operator')['code'] === 400,
    'API-10: 期限の後も催促する設定のまま、期限の後の受講を止めることはできない');
$r = call_handler('edu_d_handle_update', ['id' => $dDraft, 'title' => '題だけ変える'], 'operator');
check($r['code'] === 200 && (int) Db::one('SELECT remind_max_count FROM edu_deliveries WHERE id = ?', [$dDraft])['remind_max_count'] === 2,
    'API-11: 催促の項目を送らない編集では、催促の設定を変えない');

echo "ALL TESTS PASSED\n";
