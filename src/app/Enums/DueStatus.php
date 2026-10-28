<?php

namespace App\Enums;

/**
 * Derived status of a due (never stored): what is left of it and whether its date or event arrived.
 */
enum DueStatus: string
{
    case Upcoming = 'upcoming';
    case Due = 'due';
    case Overdue = 'overdue';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
}
