<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/CampaignAutomationSchedule.php';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException("FAIL: {$message}");
    }
    echo "PASS: {$message}\n";
}

function monthlyRule(array $overrides = []): array
{
    return array_merge([
        'frequency' => 'monthly',
        'day_of_month' => 15,
        'generation_lead_days' => 5,
        'time_mode' => 'fixed',
        'send_window_start' => '09:30',
        'send_window_end' => null,
    ], $overrides);
}

$timezone = new DateTimeZone('Asia/Tokyo');
$schedule = new CampaignAutomationSchedule($timezone);

echo "=== CampaignAutomationSchedule ===\n";

$occurrence = $schedule->next(monthlyRule(), new DateTimeImmutable('2026-08-08 12:00:00', $timezone));
check($occurrence['occurrence_key'] === '2026-08', '月次のoccurrence key');
check($occurrence['send_window_start_at'] === '2026-08-15 09:30:00', '月次fixed日時');
check($occurrence['send_window_end_at'] === '2026-08-15 09:30:00', 'fixedはwindow終端も同時刻');
check($occurrence['next_due_at'] === '2026-08-10 09:30:00', 'lead daysを差し引く');

$occurrence = $schedule->next(monthlyRule(), new DateTimeImmutable('2026-08-16 00:00:00', $timezone));
check($occurrence['occurrence_key'] === '2026-09', '当月予定後は翌月');

$occurrence = $schedule->next(
    monthlyRule(['day_of_month' => 28]),
    new DateTimeImmutable('2026-12-29 00:00:00', $timezone)
);
check($occurrence['occurrence_key'] === '2027-01', '月次の年境界');

$quarterly = monthlyRule([
    'frequency' => 'quarterly',
    'day_of_month' => 10,
    'generation_lead_days' => 7,
]);
$occurrence = $schedule->next($quarterly, new DateTimeImmutable('2026-08-09 00:00:00', $timezone));
check($occurrence['occurrence_key'] === '2026-Q4', '四半期は次のcalendar quarter');
check($occurrence['send_window_start_at'] === '2026-10-10 09:30:00', '四半期の送信日時');
check($occurrence['next_due_at'] === '2026-10-03 09:30:00', '四半期にもlead daysを適用');

$occurrence = $schedule->next($quarterly, new DateTimeImmutable('2026-10-11 00:00:00', $timezone));
check($occurrence['occurrence_key'] === '2027-Q1', '四半期の年境界');

$randomRule = monthlyRule([
    'generation_lead_days' => 0,
    'time_mode' => 'random_window',
    'send_window_start' => '09:00',
    'send_window_end' => '10:00',
]);
$occurrence = $schedule->next($randomRule, new DateTimeImmutable('2026-08-08 00:00:00', $timezone));
check($occurrence['send_window_start_at'] === '2026-08-15 09:00:00', 'random window開始');
check($occurrence['send_window_end_at'] === '2026-08-15 10:00:00', 'random window終了');
$selected = $schedule->selectSendAt(
    $occurrence,
    static fn(int $minimum, int $maximum): int => $minimum + intdiv($maximum - $minimum, 2)
);
check($selected === '2026-08-15 09:30:00', 'random選択値をwindow内で決定する');

$invalidRejected = false;
try {
    $schedule->next(monthlyRule(['day_of_month' => 29]), new DateTimeImmutable('2026-08-08', $timezone));
} catch (InvalidArgumentException) {
    $invalidRejected = true;
}
check($invalidRejected, 'day_of_month 29を拒否する');

echo "ALL TESTS PASSED\n";
