<?php

namespace App\Whatsapp\Inbox;

use App\Notifications\Channels\WhatsApp\WhatsAppException;
use App\Notifications\Channels\WhatsApp\WhatsAppTemplateMessage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Local default (WHATSAPP_DRIVER=log): nothing leaves the server. Records the kind of message and its length only —
 * never the text, the parameters or the number.
 */
final class LogConversationTransport implements ConversationTransport
{
    public function isConfigured(): bool
    {
        return true;
    }

    public function sendText(string $to, string $body): string
    {
        Log::info('WhatsApp inbox (log driver): text reply', ['length' => mb_strlen($body)]);

        return 'wamid.log-'.Str::uuid();
    }

    public function sendTemplate(WhatsAppTemplateMessage $message): string
    {
        Log::info('WhatsApp inbox (log driver): template reply', ['template' => $message->template, 'params' => count($message->parameters)]);

        return 'wamid.log-'.Str::uuid();
    }

    public function fetchMedia(string $mediaId, array $allowedTypes, int $maxBytes): MediaFile
    {
        throw new WhatsAppException('The log driver cannot download media.', false, 'not_configured');
    }
}
