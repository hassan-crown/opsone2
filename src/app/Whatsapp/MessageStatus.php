<?php

namespace App\Whatsapp;

enum MessageStatus: string
{
    case Queued = 'queued';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Read = 'read';
    case Failed = 'failed';

    /** Forward-only: a late or replayed status never moves a message backwards. */
    public function rank(): int
    {
        return match ($this) {
            self::Queued => 0,
            self::Failed => 1,
            self::Sent => 2,
            self::Delivered => 3,
            self::Read => 4,
        };
    }
}
