<?php

namespace App\Whatsapp\Inbox;

use App\Notifications\Channels\WhatsApp\WhatsAppException;
use App\Notifications\Channels\WhatsApp\WhatsAppTemplateMessage;

/**
 * Test driver: keeps what was sent in memory, serves media registered by the test and can be told to fail.
 */
final class FakeConversationTransport implements ConversationTransport
{
    /** @var list<array{to: string, body: string}> */
    public array $texts = [];

    /** @var list<WhatsAppTemplateMessage> */
    public array $templates = [];

    /** @var array<string, array{bytes: string, mime: string, size?: int}> */
    public array $media = [];

    /** @var list<string> */
    public array $downloaded = [];

    public bool $configured = true;

    public ?WhatsAppException $failure = null;

    private int $sequence = 0;

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function sendText(string $to, string $body): string
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }
        $this->texts[] = ['to' => $to, 'body' => $body];

        return 'wamid.inbox'.++$this->sequence;
    }

    public function sendTemplate(WhatsAppTemplateMessage $message): string
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }
        $this->templates[] = $message;

        return 'wamid.inbox'.++$this->sequence;
    }

    public function fetchMedia(string $mediaId, array $allowedTypes, int $maxBytes): MediaFile
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }
        $file = $this->media[$mediaId] ?? throw new WhatsAppException('Unknown media.', false, '404');
        MediaCheck::declared($file['mime'], $file['size'] ?? strlen($file['bytes']), $allowedTypes, $maxBytes);
        $this->downloaded[] = $mediaId;

        return MediaCheck::content($file['bytes'], $file['mime'], $allowedTypes, $maxBytes);
    }
}
