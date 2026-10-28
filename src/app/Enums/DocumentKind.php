<?php

namespace App\Enums;

use App\Access\Permission;

/**
 * Documents that ask for the customer's approval (domain 04). A quote fixes the agreement, a design and a contract
 * record what the customer approved without changing its value.
 */
enum DocumentKind: string
{
    case Quote = 'quote';
    case Design = 'design';
    case Contract = 'contract';

    /** Preparing it: P-29 prices a quote, P-33 uploads a design, P-32 prepares a contract. */
    public function preparePermission(): Permission
    {
        return match ($this) {
            self::Quote => Permission::P29,
            self::Design => Permission::P33,
            self::Contract => Permission::P32,
        };
    }

    public function numberPrefix(): string
    {
        return match ($this) {
            self::Quote => 'QUO',
            self::Design => 'DSN',
            self::Contract => 'CON',
        };
    }

    /** Only a quote's validity ends (M-08-T05); a design has no period. */
    public function expires(): bool
    {
        return $this === self::Quote;
    }
}
