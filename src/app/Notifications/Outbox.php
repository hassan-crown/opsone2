<?php

namespace App\Notifications;

use App\Jobs\DeliverOutboxMessage;
use App\Models\OutboxMessage;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Transactional outbox. `enqueue*` writes the message row inside the caller's transaction (it commits or rolls
 * back with the business change) and dispatches the delivery job only after commit. The idempotency key makes a
 * repeated enqueue a no-op.
 */
final class Outbox
{
    /**
     * @param  list<string>  $lines
     * @param  array{label: string, url: string}|null  $action
     */
    public function enqueueMail(string $to, string $title, array $lines, ?array $action, string $locale, string $idempotencyKey): ?OutboxMessage
    {
        return $this->enqueue(OutboxChannel::Mail, $to, 'message', [
            'title' => $title,
            'lines' => $lines,
            'action' => $action,
            'locale' => $locale,
            'dir' => $locale === 'ar' ? 'rtl' : 'ltr',
        ], $idempotencyKey);
    }

    public function enqueueSms(string $to, string $text, string $idempotencyKey): ?OutboxMessage
    {
        return $this->enqueue(OutboxChannel::Sms, $to, 'text', ['text' => $text], $idempotencyKey);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function enqueue(OutboxChannel $channel, string $to, string $kind, array $payload, string $key): ?OutboxMessage
    {
        try {
            $message = OutboxMessage::query()->firstOrCreate(
                ['idempotency_key' => $key],
                ['channel' => $channel, 'recipient' => $to, 'kind' => $kind, 'payload' => $payload, 'status' => OutboxStatus::Pending],
            );
        } catch (UniqueConstraintViolationException) {
            return null;
        }

        if (! $message->wasRecentlyCreated) {
            return null;
        }

        DeliverOutboxMessage::dispatch($message->id);

        return $message;
    }
}
