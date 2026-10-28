<?php

namespace App\Enums;

enum InterestStatus: string
{
    case New = 'new';
    case Following = 'following';
    case Converted = 'converted';
    case Closed = 'closed';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
