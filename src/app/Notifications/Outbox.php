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

    /**
     * @param  int|null  $customerMessageId  the customer log row whose status follows this delivery
     */
    public function enqueueSms(string $to, string $text, string $idempotencyKey, ?int $customerMessageId = null): ?OutboxMessage
    {
        return $this->enqueue(OutboxChannel::Sms, $to, 'text', ['text' => $text, 'customer_message_id' => $customerMessageId], $idempotencyKey);
    }

    /**
     * A WhatsApp template message (ADR-0030). The payload carries the template, language, ordered parameters, the
     * SMS text to fall back to and the customer log row.
     *
     * @param  array<string, mixed>  $payload
     */
    public function enqueueWhatsApp(string $to, array $payload, string $idempotencyKey): ?OutboxMessage
    {
        return $this->enqueue(OutboxChannel::Whatsapp, $to, 'template', $payload, $idempotencyKey);
    }

    /**
     * Reporting or clearance of one signed e-invoice with ZATCA (ADR-0032). The key carries the invoice UUID (and the
     * retry number when the owner retries a failed submission), so the same submission is never queued twice.
     */
    public function enqueueEInvoice(int $invoiceId, string $idempotencyKey): ?OutboxMessage
    {
        return $this->enqueue(OutboxChannel::Zatca, 'zatca', 'submission', ['e_invoice_id' => $invoiceId], $idempotencyKey);
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
