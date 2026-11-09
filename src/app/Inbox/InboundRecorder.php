<?php

namespace App\Inbox;

use App\Ai\Inbox\IntentClassifier;
use App\Ai\Inbox\Jobs\ClassifyInboundMessage;
use App\Customers\Phone;
use App\Inbox\Jobs\FetchInboundMedia;
use App\Models\Inbox\Conversation;
use App\Models\Inbox\ConversationMessage;
use App\Whatsapp\Inbox\MediaCheck;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Writes one inbound WhatsApp message into the inbox (AI-3), called by the signed webhook after its signature check.
 *
 * Idempotent: Meta re-delivers webhooks, so the message id (`wamid…`) is unique in `conversation_messages`; a replay is
 * recognised and changes nothing (no second row, no second unread count, no second media download). The window is
 * opened from Meta's own timestamp of the message, never from a time in the future. With the AI inbox feature on, the
 * message is classified on the queue after commit (AI-4); nothing here waits for a model.
 */
final class InboundRecorder
{
    /** Types stored with their text; media types carry an optional caption. Reactions and system events are ignored. */
    private const IGNORED = ['reaction', 'system', 'ephemeral', 'request_welcome'];

    public function __construct(
        private readonly InboxSettings $settings,
        private readonly ConversationCustomers $customers,
        private readonly IntentClassifier $classifier,
    ) {}

    /**
     * @param  array<string, mixed>  $inbound  one `messages[]` item
     * @param  array<string, mixed>  $value  the change value (for the contact's profile name)
     * @return bool whether a new message was stored
     */
    public function record(array $inbound, array $value): bool
    {
        if (! $this->settings->enabled()) {
            return false;
        }

        $id = (string) ($inbound['id'] ?? '');
        $type = (string) ($inbound['type'] ?? '');
        $from = Phone::normalize('+'.ltrim((string) ($inbound['from'] ?? ''), '+'), false);
        if ($id === '' || mb_strlen($id) > 191 || $from === null || in_array($type, self::IGNORED, true)) {
            return false;
        }
        if (ConversationMessage::query()->where('provider_message_id', $id)->exists()) {
            return false;
        }

        [$kind, $body, $mediaId, $mime] = $this->content($inbound, $type);
        $occurredAt = $this->occurredAt($inbound['timestamp'] ?? null);
        $profile = $this->profileName($value, (string) ($inbound['from'] ?? ''));
        $mediaStatus = null;
        if ($mediaId !== null) {
            $mediaStatus = $this->acceptableType($mime) ? ConversationMessage::MEDIA_PENDING : ConversationMessage::MEDIA_REJECTED;
        }

        try {
            $message = DB::transaction(function () use ($id, $from, $kind, $body, $mediaId, $mime, $mediaStatus, $occurredAt, $profile) {
                $conversation = $this->conversationFor($from, $profile);

                $message = $conversation->messages()->create([
                    'direction' => ConversationMessage::IN,
                    'type' => $kind,
                    'provider_message_id' => $id,
                    'body' => $body,
                    'status' => ConversationMessage::RECEIVED,
                    'media_provider_id' => $mediaId,
                    'media_mime' => $mime,
                    'media_status' => $mediaStatus,
                    'error' => $mediaStatus === ConversationMessage::MEDIA_REJECTED ? 'type' : null,
                    'occurred_at' => $occurredAt,
                ]);

                $conversation->forceFill([
                    'last_inbound_at' => $conversation->last_inbound_at === null || $occurredAt->isAfter($conversation->last_inbound_at) ? $occurredAt : $conversation->last_inbound_at,
                    'last_message_at' => $conversation->last_message_at === null || $occurredAt->isAfter($conversation->last_message_at) ? $occurredAt : $conversation->last_message_at,
                    'unread_count' => $conversation->unread_count + 1,
                    'contact_name' => $profile ?? $conversation->contact_name,
                ])->save();

                return $message;
            });
        } catch (UniqueConstraintViolationException) {
            // The same message arriving twice at the same moment: the first one won.
            return false;
        }

        if ($mediaStatus === ConversationMessage::MEDIA_PENDING) {
            FetchInboundMedia::dispatch($message->id);
        }
        // AI-4: classified (and maybe drafted for the assignee) on the queue after commit, never here.
        if ($this->classifier->available()) {
            ClassifyInboundMessage::dispatch($message->id);
        }

        return true;
    }

    private function conversationFor(string $waId, ?string $profile): Conversation
    {
        $conversation = Conversation::query()->where('wa_id', $waId)->lockForUpdate()->first();
        if ($conversation !== null) {
            return $conversation;
        }

        try {
            return DB::transaction(fn () => Conversation::query()->create([
                'wa_id' => $waId,
                'contact_name' => $profile,
                ...$this->customers->initialFacts($waId),
            ]));
        } catch (UniqueConstraintViolationException) {
            return Conversation::query()->where('wa_id', $waId)->lockForUpdate()->firstOrFail();
        }
    }

    /**
     * @param  array<string, mixed>  $inbound
     * @return array{0: string, 1: string|null, 2: string|null, 3: string|null} kind, text, media id, declared MIME
     */
    private function content(array $inbound, string $type): array
    {
        $text = fn (mixed $v): ?string => is_string($v) && trim($v) !== '' ? mb_substr($v, 0, 4096) : null;

        return match ($type) {
            'text' => ['text', $text(data_get($inbound, 'text.body')), null, null],
            'button' => ['text', $text(data_get($inbound, 'button.text')), null, null],
            'interactive' => ['text', $text(data_get($inbound, 'interactive.button_reply.title') ?? data_get($inbound, 'interactive.list_reply.title')), null, null],
            'image', 'audio', 'video', 'document', 'sticker' => [
                $type,
                $text(data_get($inbound, $type.'.caption')),
                is_string(data_get($inbound, $type.'.id')) ? (string) data_get($inbound, $type.'.id') : null,
                is_string(data_get($inbound, $type.'.mime_type')) ? MediaCheck::base((string) data_get($inbound, $type.'.mime_type')) : null,
            ],
            default => ['unsupported', null, null, null],
        };
    }

    private function acceptableType(?string $mime): bool
    {
        return $mime !== null && array_key_exists($mime, (array) config('whatsapp.inbox.media_types'));
    }

    private function occurredAt(mixed $timestamp): CarbonInterface
    {
        $now = now();
        if (! is_numeric($timestamp)) {
            return $now;
        }
        $at = $now->setTimestamp((int) $timestamp);

        return $at->isAfter($now) ? $now : $at;
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function profileName(array $value, string $from): ?string
    {
        foreach ((array) ($value['contacts'] ?? []) as $contact) {
            if ((string) data_get($contact, 'wa_id') === ltrim($from, '+')) {
                $name = trim((string) data_get($contact, 'profile.name', ''));

                return $name === '' ? null : mb_substr($name, 0, 150);
            }
        }

        return null;
    }
}
