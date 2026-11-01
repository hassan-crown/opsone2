<?php

namespace App\Notifications\Channels\WhatsApp;

/**
 * One template message: approved name, language code and the ordered body parameters.
 */
final readonly class WhatsAppTemplateMessage
{
    /**
     * @param  list<string>  $parameters
     */
    public function __construct(
        public string $to,
        public string $template,
        public string $language,
        public array $parameters,
    ) {}
}
