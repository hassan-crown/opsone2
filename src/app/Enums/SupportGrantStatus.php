<?php

namespace App\Enums;

/**
 * M-38 OpsOne support access (proposed, Q-25): requested → active → expired | revoked; requested → rejected | expired.
 */
enum SupportGrantStatus: string
{
    case Requested = 'requested';
    case Active = 'active';
    case Expired = 'expired';
    case Revoked = 'revoked';
    case Rejected = 'rejected';
}
