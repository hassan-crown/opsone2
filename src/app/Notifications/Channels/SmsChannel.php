<?php

namespace App\Notifications\Channels;

/**
 * Outbound SMS (Q-24). The provider is chosen later; until a real driver is bound the log driver serves
 * development only and production reports "not configured". WhatsApp is a separate channel (ADR-0030) whose fallback is SMS.
 */
interface SmsChannel
{
    public function isConfigured(): bool;

    /**
     * @throws \Throwable when the provider refuses or cannot be reached (the outbox retries)
     */
    public function send(string $to, string $text): void;
}
