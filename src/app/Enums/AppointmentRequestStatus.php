<?php

namespace App\Enums;

/**
 * A customer appointment request (ADR-0039): new → assigned (someone calls the customer) → scheduled (an appointment
 * was booked from it) or closed with a reason. Scheduled and closed are final.
 */
enum AppointmentRequestStatus: string
{
    case New = 'new';
    case Assigned = 'assigned';
    case Scheduled = 'scheduled';
    case Closed = 'closed';

    public function isOpen(): bool
    {
        return $this === self::New || $this === self::Assigned;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
