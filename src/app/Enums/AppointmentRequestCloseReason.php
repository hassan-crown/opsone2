<?php

namespace App\Enums;

enum AppointmentRequestCloseReason: string
{
    case Unreachable = 'unreachable';
    case NotNeeded = 'not_needed';
    case Duplicate = 'duplicate';
    case BookedElsewhere = 'booked_elsewhere';
    case Other = 'other';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
