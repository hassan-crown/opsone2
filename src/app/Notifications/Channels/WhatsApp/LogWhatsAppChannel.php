<?php

namespace App\Notifications\Channels\WhatsApp;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Development driver: records that a template would have been sent — template name and parameter count only,
 * never the parameters (names, amounts, links) or the full number.
 */
final class LogWhatsAppChannel implements WhatsAppChannel
{
    public function isConfigured(): bool
    {
        return app()->environment('local', 'testing');
    }

    public function sendTemplate(WhatsAppTemplateMessage $message): string
    {
        Log::info('WhatsApp (log driver)', [
            'to' => substr($message->to, 0, 4).'****',
            'template' => $message->template,
            'language' => $message->language,
            'parameters' => count($message->parameters),
        ]);

        return 'log.'.Str::uuid()->toString();
    }
}
