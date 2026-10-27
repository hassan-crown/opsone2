<?php

namespace App\Enums;

enum ContactChannel: string
{
    case Whatsapp = 'whatsapp';
    case Phone = 'phone';
    case Email = 'email';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
