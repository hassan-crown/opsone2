<?php

namespace App\Ai\AutoReply;

use App\Models\WorkSettings;
use App\Tasks\WorkingDays;
use App\Workflow\AppointmentBooking;
use Carbon\CarbonInterface;

/**
 * Whether a moment falls inside the facility's working hours (WorkSettings, facility time zone, holidays of the
 * current release) — for the «outside working hours only» choice of the automatic reply.
 */
final class WorkingHours
{
    public static function isOpen(CarbonInterface $at): bool
    {
        $settings = WorkSettings::current();
        $local = $at->copy()->setTimezone(AppointmentBooking::timezone());
        if (! WorkingDays::current()->isWorkingDay($local)) {
            return false;
        }
        $time = $local->format('H:i');

        return $time >= $settings->work_starts_at && $time < $settings->work_ends_at;
    }
}
