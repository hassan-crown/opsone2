<?php

namespace App\Notifications\Channels\WhatsApp;

/**
 * Outbound WhatsApp (ADR-0029). Template messages only: the provider never receives free text.
 */
interface WhatsAppChannel
{
    /** Whether this driver may send in the current environment (credentials present, or a development driver). */
    public function isConfigured(): bool;

    /**
     * @return string the provider's message id (used to match delivery-status webhooks)
     *
     * @throws WhatsAppException when the provider refuses or cannot be reached; `retryable` tells the outbox
     *                           whether another attempt can help or the message must fall back to SMS now
     */
    public function sendTemplate(WhatsAppTemplateMessage $message): string;
}
