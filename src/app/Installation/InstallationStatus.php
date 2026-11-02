<?php

namespace App\Installation;

use App\Models\Installation\InstallationVisit;

/**
 * The installation status managers see in the calendar and on the order (ADR-0036). «Not started» has no visit row.
 */
enum InstallationStatus: string
{
    case NotStarted = 'not_started';
    case InProgress = 'in_progress';
    case Finished = 'finished';
    case FinishedWithRemarks = 'finished_with_remarks';

    public static function of(?InstallationVisit $visit): self
    {
        return $visit === null ? self::NotStarted : $visit->status;
    }
}
