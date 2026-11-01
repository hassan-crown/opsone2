<?php

namespace App\Notifications\Channels\WhatsApp;

use RuntimeException;

final class WhatsAppException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $retryable, public readonly ?string $providerCode = null)
    {
        parent::__construct($message);
    }
}
