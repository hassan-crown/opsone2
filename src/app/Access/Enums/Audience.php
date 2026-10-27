<?php

namespace App\Access\Enums;

enum Audience: string
{
    /** Facility staff actions (roles ر1..ر8). */
    case Staff = 'staff';

    /** Any signed-in account acting on itself (P-64, P-84). */
    case Self = 'self';

    /** Facility customer portal (ع1); served by the customer guard, never by the staff Gate. */
    case Customer = 'customer';

    /** Visitors (P-72, P-83). */
    case Public = 'public';

    /** OpsOne operations and subscriber accounts (م1..م6، ش1): central app or support delegation. */
    case Platform = 'platform';
}
