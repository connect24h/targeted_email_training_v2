<?php
declare(strict_types=1);

/** 月次・四半期automationの次回予定をAsia/Tokyo基準で計算する。 */
final class CampaignAutomationSchedule
{
    public function __construct(private DateTimeZone $timezone = new DateTimeZone('Asia/Tokyo'))
    {
    }

    public function next(array $rule, ?DateTimeImmutable $now = null): array
    {
        $this->validate($rule);
        $now = ($now ?? new DateTimeImmutable('now', $this->timezone))->setTimezone($this->timezone);
        $candidate = $this->candidateDate($rule, $now);
        $window = $this->window($candidate, $rule);
        if ($window['end'] < $now) {
            $candidate = $this->advance($candidate, (string) $rule['frequency']);
            $window = $this->window($candidate, $rule);
        }
        $leadDays = (int) $rule['generation_lead_days'];
        return [
            'occurrence_key' => $this->occurrenceKey($candidate, (string) $rule['frequency']),
            'send_window_start_at' => $window['start']->format('Y-m-d H:i:s'),
            'send_window_end_at' => $window['end']->format('Y-m-d H:i:s'),
            'next_due_at' => $window['start']->modify("-{$leadDays} days")->format('Y-m-d H:i:s'),
        ];
    }

    public function selectSendAt(array $occurrence, ?callable $randomizer = null): string
    {
        $start = $this->parseDateTime($occurrence['send_window_start_at'] ?? null);
        $end = $this->parseDateTime($occurrence['send_window_end_at'] ?? null);
        $minimum = $start->getTimestamp();
        $maximum = $end->getTimestamp();
        $selected = $randomizer === null
            ? random_int($minimum, $maximum)
            : $randomizer($minimum, $maximum);
        if (!is_int($selected) || $selected < $minimum || $selected > $maximum) {
            throw new InvalidArgumentException('random選択値が送信window外です');
        }
        return (new DateTimeImmutable('@' . $selected))->setTimezone($this->timezone)->format('Y-m-d H:i:s');
    }

    private function validate(array $rule): void
    {
        if (!in_array($rule['frequency'] ?? null, ['monthly', 'quarterly'], true)) {
            throw new InvalidArgumentException('frequencyが不正です');
        }
        $day = $rule['day_of_month'] ?? null;
        $lead = $rule['generation_lead_days'] ?? null;
        if (!is_int($day) || $day < 1 || $day > 28) {
            throw new InvalidArgumentException('day_of_monthが不正です');
        }
        if (!is_int($lead) || $lead < 0 || $lead > 90) {
            throw new InvalidArgumentException('generation_lead_daysが不正です');
        }
        $this->validateWindow($rule);
    }

    private function validateWindow(array $rule): void
    {
        $mode = $rule['time_mode'] ?? null;
        $start = $rule['send_window_start'] ?? null;
        $end = $rule['send_window_end'] ?? null;
        if (!in_array($mode, ['fixed', 'random_window'], true) || !$this->validTime($start)) {
            throw new InvalidArgumentException('送信時刻設定が不正です');
        }
        if ($mode === 'random_window' && (!$this->validTime($end) || $end <= $start)) {
            throw new InvalidArgumentException('random windowが不正です');
        }
    }

    private function validTime(mixed $time): bool
    {
        if (!is_string($time) || preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/', $time) !== 1) {
            return false;
        }
        return true;
    }

    private function candidateDate(array $rule, DateTimeImmutable $now): DateTimeImmutable
    {
        $month = (int) $now->format('n');
        if ($rule['frequency'] === 'quarterly') {
            $month = intdiv($month - 1, 3) * 3 + 1;
        }
        $date = sprintf('%04d-%02d-%02d', (int) $now->format('Y'), $month, (int) $rule['day_of_month']);
        return new DateTimeImmutable($date, $this->timezone);
    }

    /** @return array{start:DateTimeImmutable,end:DateTimeImmutable} */
    private function window(DateTimeImmutable $date, array $rule): array
    {
        $datePart = $date->format('Y-m-d');
        $start = new DateTimeImmutable($datePart . ' ' . $rule['send_window_start'], $this->timezone);
        $endTime = $rule['time_mode'] === 'fixed'
            ? $rule['send_window_start']
            : $rule['send_window_end'];
        return [
            'start' => $start,
            'end' => new DateTimeImmutable($datePart . ' ' . $endTime, $this->timezone),
        ];
    }

    private function advance(DateTimeImmutable $candidate, string $frequency): DateTimeImmutable
    {
        return $candidate->modify($frequency === 'quarterly' ? '+3 months' : '+1 month');
    }

    private function occurrenceKey(DateTimeImmutable $date, string $frequency): string
    {
        if ($frequency === 'monthly') {
            return $date->format('Y-m');
        }
        $quarter = intdiv((int) $date->format('n') - 1, 3) + 1;
        return $date->format('Y') . '-Q' . $quarter;
    }

    private function parseDateTime(mixed $value): DateTimeImmutable
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException('予定日時が不正です');
        }
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, $this->timezone);
        if ($parsed === false || $parsed->format('Y-m-d H:i:s') !== $value) {
            throw new InvalidArgumentException('予定日時が不正です');
        }
        return $parsed;
    }
}
