<?php

namespace App\Enums;

enum CustomerKind: string
{
    case Person = 'person';
    case Organization = 'organization';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
