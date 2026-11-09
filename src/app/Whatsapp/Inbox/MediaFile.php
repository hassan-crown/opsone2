<?php

namespace App\Whatsapp\Inbox;

final readonly class MediaFile
{
    public function __construct(public string $bytes, public string $mime) {}
}
