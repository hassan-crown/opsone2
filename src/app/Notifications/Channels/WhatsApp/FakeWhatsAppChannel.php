<?php

namespace App\Notifications\Channels\WhatsApp;

/**
 * Test driver: keeps what was sent in memory and can be told to fail.
 */
final class FakeWhatsAppChannel implements WhatsAppChannel
{
    /** @var list<WhatsAppTemplateMessage> */
    public array $sent = [];

    public bool $configured = true;

    public ?WhatsAppException $failure = null;

    private int $sequence = 0;

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function sendTemplate(WhatsAppTemplateMessage $message): string
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }

        $this->sent[] = $message;

        return 'wamid.fake'.++$this->sequence;
    }
}
