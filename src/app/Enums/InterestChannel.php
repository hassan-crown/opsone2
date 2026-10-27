<?php

namespace App\Enums;

enum InterestChannel: string
{
    case Phone = 'phone';
    case Whatsapp = 'whatsapp';
    case Email = 'email';
    case WalkIn = 'walk_in';
    case Website = 'website';
    case Other = 'other';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
