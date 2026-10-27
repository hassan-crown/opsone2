<?php

namespace App\Notifications\Channels;

use Illuminate\Support\Facades\Log;

/**
 * Development driver: records that an SMS would have been sent, without its text (it may carry a code).
 */
final class LogSmsChannel implements SmsChannel
{
    public function isConfigured(): bool
    {
        return app()->environment('local', 'testing');
    }

    public function send(string $to, string $text): void
    {
        Log::info('SMS (log driver)', ['to' => substr($to, 0, 4).'****', 'length' => mb_strlen($text)]);
    }
}
