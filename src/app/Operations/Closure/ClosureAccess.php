<?php

namespace App\Operations\Closure;

use App\Access\Access;
use App\Access\Permission;
use App\Models\Membership;
use App\Models\User;

/**
 * Closure readiness and the full export are the facility owner's (Q-53 a): the owner membership AND P-62 through the
 * Gate, so a disabled owner, a support identity or a license without export loses it. Read-only license phases keep it
 * (NFR-16: export works until the end of retention).
 */
final class ClosureAccess
{
    public static function allows(?User $user): bool
    {
        if ($user === null || $user->is_support) {
            return false;
        }

        $membership = Membership::query()->where('user_id', $user->id)->first();

        return $membership !== null && $membership->isOwner() && Access::can($user, Permission::P62);
    }
}
