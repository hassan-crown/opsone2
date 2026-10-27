<?php

namespace App\Enums;

enum InterestCloseReason: string
{
    case Price = 'price';
    case Competitor = 'competitor';
    case NoReply = 'no_reply';
    case Later = 'later';
    case Duplicate = 'duplicate';
    case Other = 'other';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
