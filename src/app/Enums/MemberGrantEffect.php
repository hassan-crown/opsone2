<?php

namespace App\Enums;

/**
 * Per-member override of role template grants.
 */
enum MemberGrantEffect: string
{
    case Allow = 'allow';
    case Deny = 'deny';
}
