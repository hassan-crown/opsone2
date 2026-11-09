<?php

namespace App\Whatsapp\Inbox;

use RuntimeException;

/** A received file whose type or size the inbox does not accept. `reason` is a short code: type | size. */
final class MediaRejected extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct("WhatsApp media rejected ({$reason}).");
    }
}
