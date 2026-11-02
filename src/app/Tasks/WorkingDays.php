<?php

namespace App\Tasks;

use App\Models\WorkSettings;
use App\Workflow\AppointmentBooking;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Working-day arithmetic in the facility's time zone (ADR-0037): a working day is one of the facility's working
 * weekdays that is not a listed holiday (WorkSettings). Used for task SLAs; dues stay in calendar days.
 */
final class WorkingDays
{
    public function __construct(private readonly WorkSettings $settings) {}

    public static function current(): self
    {
        return new self(WorkSettings::current());
    }

    public function isWorkingDay(CarbonInterface $day): bool
    {
        return $this->settings->isWorkingDay($day) && ! in_array($day->toDateString(), $this->settings->holidays ?? [], true);
    }

    /**
     * The end of the working day `$days` working days after `$from` (0 = the end of the same day, or of the next
     * working day when `$from` is not one).
     */
    public function dueAt(CarbonInterface $from, int $days): CarbonImmutable
    {
        $day = CarbonImmutable::parse($from)->setTimezone(AppointmentBooking::timezone())->startOfDay();
        if ($days === 0) {
            while (! $this->isWorkingDay($day)) {
                $day = $day->addDay();
            }
        }
        for ($counted = 0; $counted < $days;) {
            $day = $day->addDay();
            if ($this->isWorkingDay($day)) {
                $counted++;
            }
        }
        [$hour, $minute] = array_map(intval(...), explode(':', $this->settings->work_ends_at));

        return $day->setTime($hour, $minute)->utc();
    }

    /**
     * Working days from `$from` to `$to` (dates, exclusive of `$from`), 0 when `$to` is not later.
     */
    public function between(CarbonInterface $from, CarbonInterface $to): int
    {
        $timezone = AppointmentBooking::timezone();
        $day = CarbonImmutable::parse($from)->setTimezone($timezone)->startOfDay();
        $end = CarbonImmutable::parse($to)->setTimezone($timezone)->startOfDay();
        $count = 0;
        while ($day < $end) {
            $day = $day->addDay();
            $count += (int) $this->isWorkingDay($day);
        }

        return $count;
    }
}
