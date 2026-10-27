<?php

namespace App\Enums;

/**
 * M-22 user membership.
 */
enum MembershipStatus: string
{
    case Active = 'active';
    case Disabled = 'disabled';
}
