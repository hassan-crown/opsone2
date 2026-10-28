<?php

namespace App\Store;

/**
 * M-31 «رابط سلة الجاهز».
 */
enum LinkStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case CheckingOut = 'checking_out';
    case Used = 'used';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    public function isOpen(): bool
    {
        return $this === self::Active || $this === self::CheckingOut;
    }
}
