<?php

namespace App\Enums;

/**
 * M-37 ownership transfer (proposed, Q-18).
 */
enum OwnershipTransferStatus: string
{
    case Requested = 'requested';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
}
