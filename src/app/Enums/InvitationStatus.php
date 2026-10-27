<?php

namespace App\Enums;

/**
 * M-01 staff invitation.
 */
enum InvitationStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Expired = 'expired';
    case Cancelled = 'cancelled';
}
