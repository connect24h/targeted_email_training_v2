<?php
declare(strict_types=1);

/**
 * 毎月の自動の配信(F1)と、同じ系列の出題済みの設問の除外(F2)の回帰テスト。
 */

require_once __DIR__ . '/helpers.php';

tet2_test_boot();
require_once __DIR__ . '/../lib/EduMailer.php';
require_once __DIR__ . '/../lib/EduScheduler.php';
require_once __DIR__ . '/../lib/EduDeliveryLauncher.php';
load_api('edu_deliveries');

$mailCalls = 0;
EduMailer::useTransport(static function () use (&$mailCalls): bool {
    $mailCalls++;
    return true;
});
$tz = new DateTimeZone('Asia/Tokyo');
$at = static fn(string $s): DateTimeImmutable => new DateTimeImmutable($s, $tz);

// ---- 次の回の日時 ----
check(EduDeliverySeries::nextOccurrence(1, '09:00', $at('2026-10-01 09:00:00'))->format('Y-m-d H:i:s') === '2026-10-01 09:00:00',
    'F1-1: 同じ日時ちょうどなら、その日時が次の回');
check(EduDeliverySeries::nextOccurrence(1, '08:00', $at('2026-10-01 09:00:00'))->format('Y-m-d H:i:s') === '2026-11-01 08:00:00',
    'F1-1: 今月の日時を過ぎていれば翌月');
check(EduDeliverySeries::nextOccurrence(10, '07:30', $at('2026-12-15 00:00:00'))->format('Y-m-d H:i:s') === '2027-01-10 07:30:00',
    'F1-1: 12月の次は翌年の1月');

// ---- 設問(5問)と系列 ----
$categoryId = Db::insert(
    "INSERT INTO edu_categories (tenant_id, name, slug, is_active, is_shared) VALUES (NULL, '月例', 'aw-monthly', 1, 1)"
);
$questionIds = [];
for ($i = 1; $i <= 5; $i++) {
    $questionIds[] = Db::insert(
        "INSERT INTO edu_questions (tenant_id, category_id, title, options, correct_answer, difficulty, is_active, is_shared)
         VALUES (NULL, ?, ?, '[\"A\",\"B\"]', '[0]', 1, 1, 1)",
        [$categoryId, '月例の設問' . $i]
    );
}

$base = [
    'title' => '月例の小問', 'delivery_type' => 'awareness_quiz', 'target_type' => 'all',
    'question_count' => 2, 'category_ids' => [$categoryId], 'randomize' => false,
];

// ---- API: 系列の作成、一覧、停止 ----
$r = call_handler('edu_d_handle_series_create', $base + ['day_of_month' => 1, 'time_of_day' => '09:00', 'deadline_days' => 7], 'operator');
check($r['code'] === 201, 'F1-2: 毎月の配信の系列を作成できる');
$series = $r['payload']['series'];
check((int) $series['is_active'] === 1 && preg_match('/^\d{4}-\d{2}-01 09:00:00$/', (string) $series['next_run_at']) === 1,
    'F1-2: 次の回の日時を毎月の日と時刻から決める');
$settings = json_decode((string) $series['settings'], true);
check(($settings['send_invites'] ?? null) === 0 && ($settings['question_count'] ?? null) === 2,
    'F1-2: 元になる配信の設定を保存する(案内メールは既定で送らない)');
check(array_column($GLOBALS['__TET2_TEST_AUDIT'], 'action') === ['edu_delivery_series.create'], 'F1-2: 系列の作成を監査ログに残す');

foreach ([
    ['day_of_month' => 29, 'time_of_day' => '09:00'],
    ['day_of_month' => 1, 'time_of_day' => '25:00'],
    ['day_of_month' => 1, 'time_of_day' => '09:00', 'deadline_days' => 0],
    ['day_of_month' => 1, 'time_of_day' => '09:00', 'end_date' => '2000-01-01'],
] as $bad) {
    $r = call_handler('edu_d_handle_series_create', $base + $bad, 'operator');
    check($r['code'] === 400, 'F1-3: 不正な繰り返しの規則を拒否する ' . json_encode($bad));
}
$r = call_handler('edu_d_handle_series_create', ['target_type' => 'risk'] + $base + ['day_of_month' => 1, 'time_of_day' => '09:00'], 'operator');
check($r['code'] === 400, 'F1-3: 訓練の結果による対象は毎月の配信にできない');
$r = call_handler('edu_d_handle_series_create', $base + ['day_of_month' => 1, 'time_of_day' => '09:00', 'question_ids' => [$questionIds[0]]], 'operator');
check($r['code'] === 400, 'F1-3: 設問の明示の指定は毎月の配信にできない(回ごとに選び直すため)');

$r = call_handler('edu_d_handle_series_list', [], 'viewer');
check($r['code'] === 200 && count($r['payload']['series']) === 1, 'F1-4: 系列の一覧を返す');
$r = call_handler('edu_d_handle_series_stop', ['id' => (int) $series['id']], 'operator');
check($r['code'] === 200 && (int) $r['payload']['series']['is_active'] === 0, 'F1-4: 系列を停止できる');
$otherSeries = Db::insert(
    "INSERT INTO edu_delivery_series (tenant_id, title, settings, day_of_month, time_of_day, next_run_at)
     VALUES (2, '他社', '{}', 1, '09:00', '2026-10-01 09:00:00')"
);
$r = call_handler('edu_d_handle_series_stop', ['id' => $otherSeries], 'operator');
check($r['code'] === 404, 'F1-4: 他テナントの系列は停止できない');
Db::run('UPDATE edu_delivery_series SET is_active = 0 WHERE id = ?', [$otherSeries]);

// ---- CLI: 次の回の日時が来た系列から配信を作り、次の実行で開始する ----
function seriesInsert(string $nextRunAt, ?string $endDate, array $settings): int
{
    return Db::insert(
        "INSERT INTO edu_delivery_series
         (tenant_id, title, settings, day_of_month, time_of_day, deadline_days, next_run_at, end_date, created_by)
         VALUES (1, '月例', ?, 1, '09:00', 7, ?, ?, 1)",
        [json_encode($settings), $nextRunAt, $endDate]
    );
}
function seriesDeliveries(int $seriesId): array
{
    return Db::all('SELECT * FROM edu_deliveries WHERE series_id = ? ORDER BY id', [$seriesId]);
}
function deliveryQuestions(int $deliveryId): array
{
    return array_map('intval', array_column(
        Db::all('SELECT question_id FROM edu_delivery_questions WHERE delivery_id = ? ORDER BY sort_order', [$deliveryId]),
        'question_id'
    ));
}

$settings = [
    'title' => '月例の小問', 'delivery_type' => 'awareness_quiz', 'question_count' => 2,
    'category_ids' => json_encode([$categoryId]), 'difficulty_range' => null, 'randomize' => 0,
    'pass_score' => null, 'material_id' => null, 'target_type' => 'all', 'target_group_id' => null,
    'triggered_by' => 'manual', 'phish_campaign_id' => null, 'feedback_mode' => 'immediate', 'send_invites' => 0,
    'target_ids' => [],
];
$seriesId = seriesInsert('2026-10-01 09:00:00', null, $settings);

$first = EduScheduler::run($at('2026-10-01 09:30:00'));
$rounds = seriesDeliveries($seriesId);
check(count($rounds) === 1 && $first['series_created'] === 1, 'F1-5: 次の回の日時が来た系列から配信を1つ作る');
check($rounds[0]['status'] === 'scheduled' && $rounds[0]['scheduled_at'] === '2026-10-01 09:00:00',
    'F1-5: 作った配信は予約の状態で、予約の日時はその回の日時');
check($rounds[0]['deadline'] === '2026-10-08', 'F1-5: その回の締切は設定の日数(7日)の後');
check(str_contains((string) $rounds[0]['title'], '2026年10月'), 'F1-5: 回のタイトルに年月を付ける');
check((string) Db::one('SELECT next_run_at FROM edu_delivery_series WHERE id = ?', [$seriesId])['next_run_at'] === '2026-11-01 09:00:00',
    'F1-5: 次の回の日時を翌月に進める');
$audit = Db::one("SELECT user_id FROM audit_log WHERE action = 'edu_scheduler.series_create' ORDER BY id DESC LIMIT 1");
check($audit !== null && $audit['user_id'] === null, 'F1-5: 系列からの作成を監査ログ(user_id NULL)に残す');

$second = EduScheduler::run($at('2026-10-01 09:30:00'));
$rounds = seriesDeliveries($seriesId);
check(count($rounds) === 1 && $second['series_created'] === 0, 'F1-6: 同じ日時で再実行しても配信は増えない(冪等)');
check($rounds[0]['status'] === 'running' && $second['launched'] === 1, 'F1-6: 作った配信は次の実行で開始される');
$round1 = deliveryQuestions((int) $rounds[0]['id']);
check($round1 === [$questionIds[0], $questionIds[1]], 'F1-6: 1回目は条件どおりに2問');

// ---- F2: 同じ系列の過去の回で出した設問を外す ----
EduScheduler::run($at('2026-11-01 09:30:00'));
EduScheduler::run($at('2026-11-01 09:30:00'));
$rounds = seriesDeliveries($seriesId);
$round2 = deliveryQuestions((int) $rounds[1]['id']);
check(count($round2) === 2 && array_intersect($round1, $round2) === [], 'F2-1: 2回目は1回目の設問を外して出す');

EduScheduler::run($at('2026-12-01 09:30:00'));
EduScheduler::run($at('2026-12-01 09:30:00'));
$rounds = seriesDeliveries($seriesId);
$round3 = deliveryQuestions((int) $rounds[2]['id']);
check($round3 === [$questionIds[4], $questionIds[0]], 'F2-2: 候補が足りなければ、古い回で出した設問から順に戻す');

$picked = EduQuestionPicker::pick(
    ['category_ids' => json_encode([$categoryId]), 'difficulty_range' => null, 'question_count' => 2, 'randomize' => 0],
    1,
    [$questionIds[0]]
);
check($picked === [$questionIds[1], $questionIds[2]], 'F2-3: EduQuestionPicker は除外の引数の設問を候補から外す');
$other = Db::insert(
    "INSERT INTO edu_deliveries (tenant_id, title, status, delivery_type, question_count, category_ids, randomize, target_type, triggered_by)
     VALUES (1, '系列の外', 'draft', 'awareness_quiz', 2, ?, 0, 'all', 'manual')",
    [json_encode([$categoryId])]
);
check(EduDeliveryLauncher::resolveQuestions(Db::one('SELECT * FROM edu_deliveries WHERE id = ?', [$other]), 1)
    === [$questionIds[0], $questionIds[1]], 'F2-4: 系列の外の配信は除外しない');

// ---- 止まっていた間の回はまとめて作らず、直近の1回だけ作る ----
$catchUp = seriesInsert('2026-06-01 09:00:00', null, $settings);
EduScheduler::run($at('2026-10-01 09:30:00'));
$rounds = seriesDeliveries($catchUp);
check(count($rounds) === 1 && $rounds[0]['scheduled_at'] === '2026-10-01 09:00:00', 'F1-7: 止まっていた間の回は直近の1回だけ作る');
check((string) Db::one('SELECT next_run_at FROM edu_delivery_series WHERE id = ?', [$catchUp])['next_run_at'] === '2026-11-01 09:00:00',
    'F1-7: 次の回の日時は今より後に進める');

// ---- 終了日を過ぎる回は作らず、系列を止める ----
$ending = seriesInsert('2026-10-01 09:00:00', '2026-10-31', $settings);
EduScheduler::run($at('2026-10-01 09:30:00'));
check(count(seriesDeliveries($ending)) === 1, 'F1-8: 終了日までの回は作る');
check((int) Db::one('SELECT is_active FROM edu_delivery_series WHERE id = ?', [$ending])['is_active'] === 0,
    'F1-8: 次の回が終了日を過ぎるなら系列を止める');
$stopped = Db::one("SELECT user_id FROM audit_log WHERE action = 'edu_scheduler.series_end' ORDER BY id DESC LIMIT 1");
check($stopped !== null && $stopped['user_id'] === null, 'F1-8: 系列の終了を監査ログに残す');

check($mailCalls === 0, 'F1-9: send_invites=0 の系列は EduMailer の送信を1回も呼ばない');
EduMailer::useTransport(null);
echo "ALL TESTS PASSED\n";
