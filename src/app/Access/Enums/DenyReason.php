<?php

namespace App\Access\Enums;

enum DenyReason: string
{
    case UnknownPermission = 'unknown_permission';
    case Audience = 'audience';
    case License = 'license';
    case InactiveAccount = 'inactive_account';
    case NoGrant = 'no_grant';
    case OutOfScope = 'out_of_scope';
    case RecordState = 'record_state';
}
