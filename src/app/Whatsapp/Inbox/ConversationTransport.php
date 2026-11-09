<?php

namespace App\Whatsapp\Inbox;

use App\Notifications\Channels\WhatsApp\WhatsAppException;
use App\Notifications\Channels\WhatsApp\WhatsAppTemplateMessage;

/**
 * The inbox's line to WhatsApp (AI-3): free-text replies inside the 24-hour window, approved templates outside it, and
 * downloading the media a contact sent. Chosen by WHATSAPP_DRIVER like the notification channel (ADR-0030).
 */
interface ConversationTransport
{
    public function isConfigured(): bool;

    /**
     * @return string the provider's message id
     *
     * @throws WhatsAppException
     */
    public function sendText(string $to, string $body): string;

    /**
     * @return string the provider's message id
     *
     * @throws WhatsAppException
     */
    public function sendTemplate(WhatsAppTemplateMessage $message): string;

    /**
     * Downloads one received file, refusing it before the download when the declared type or size is not allowed.
     *
     * @param  array<string, string>  $allowedTypes  MIME => extension
     *
     * @throws MediaRejected when the type or size is not allowed (never retried)
     * @throws WhatsAppException when the provider refuses or cannot be reached
     */
    public function fetchMedia(string $mediaId, array $allowedTypes, int $maxBytes): MediaFile;
}
