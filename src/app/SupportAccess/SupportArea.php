<?php

namespace App\SupportAccess;

use App\Access\Permission;

/**
 * Data areas an owner can open to OpsOne support (P-79). Each maps to the screens' view permissions; support
 * gets them with facility scope but can never write (EnforceSupportAccess blocks every non-read request).
 * Audit (P-63) is readable, never writable (matrix P-80: «لا تعديل تدقيق أو صلاحية ذاتية»).
 */
enum SupportArea: string
{
    case Settings = 'settings';
    case Organization = 'organization';
    case Audit = 'audit';

    /**
     * @return list<Permission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Settings => [Permission::P01, Permission::P02],
            self::Organization => [Permission::P03, Permission::P04, Permission::P05, Permission::P06],
            self::Audit => [Permission::P63],
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $a) => $a->value, self::cases());
    }
}
