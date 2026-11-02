<?php

namespace App\Actions\Rules;

use App\Access\Access;
use App\Access\Permission;
use App\Models\User;

/**
 * Who reads and who changes the business rules (ADR-0037): changing needs P-104 (owner by default); reading also
 * P-02 holders, so a manager sees the rules that drive their work. A support identity never changes them (ADR-0008).
 */
final class RulesAccess
{
    public static function canView(User $user): bool
    {
        return Access::can($user, Permission::P104) || Access::can($user, Permission::P02);
    }

    public static function canEdit(User $user): bool
    {
        return Access::can($user, Permission::P104);
    }

    public static function authorizeView(User $user): void
    {
        abort_unless(self::canView($user), 403);
    }

    public static function authorize(User $user): void
    {
        Access::authorize($user, Permission::P104);
    }
}
