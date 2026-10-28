<?php

namespace App\Inventory;

/**
 * M-24 «حجز المخزون».
 */
enum ReservationStatus: string
{
    case Active = 'active';
    case PartiallyIssued = 'partially_issued';
    case Issued = 'issued';
    case Released = 'released';
    case SettledMixed = 'settled_mixed';

    public function isOpen(): bool
    {
        return $this === self::Active || $this === self::PartiallyIssued;
    }
}
