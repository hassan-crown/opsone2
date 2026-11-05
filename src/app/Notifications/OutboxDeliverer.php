<?php

namespace App\Notifications;

use App\EInvoicing\EInvoiceSubmitter;
use App\Jobs\DeliverOutboxMessage;
use App\Models\CustomerMessage;
use App\Models\OutboxMessage;
use App\Models\Procurement\PurchaseOrderSend;
use App\Notifications\Channels\EmailChannel;
use App\Notifications\Channels\SmsChannel;
use App\Whatsapp\MessageStatus;
use App\Whatsapp\WhatsAppDelivery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Delivers one outbox message. The claim is an atomic status update, so two workers (or a retry racing the
 * original) never send the same message twice; a sent message is never touched again. A failure is recorded and
 * retried with backoff — it never reaches the transaction that created the message.
 */
final class OutboxDeliverer
{
    public const MAX_ATTEMPTS = 6;

    /** Seconds before attempt 2, 3, ... */
    public const BACKOFF = [30, 120, 600, 1800, 7200];

    /** A claim older than this is treated as a crashed worker and may be taken over. */
    public const CLAIM_TIMEOUT_MINUTES = 15;

    public function __construct(
        private readonly EmailChannel $email,
        private readonly SmsChannel $sms,
        private readonly WhatsAppDelivery $whatsapp,
        private readonly EInvoiceSubmitter $einvoices,
    ) {}

    public function deliver(int $id): void
    {
        $message = $this->claim($id);

        if ($message === null) {
            return;
        }

        try {
            if ($message->channel === OutboxChannel::Zatca) {
                // Settled (reported, cleared, rejected or failed) on the invoice itself; a retryable failure throws.
                $this->einvoices->deliver($message);
            } elseif ($message->channel === OutboxChannel::Whatsapp) {
                // Whether it was sent or fell back to SMS, this row is done; a retryable failure throws.
                $this->whatsapp->deliver($message);
            } elseif ($message->channel === OutboxChannel::Sms) {
                if (! $this->sms->isConfigured()) {
                    $this->follow($message, MessageStatus::Failed, 'sms_not_configured');
                    $message->forceFill(['status' => OutboxStatus::Skipped, 'payload' => null, 'claimed_at' => null, 'last_error' => 'sms_not_configured'])->save();

                    return;
                }
                $this->sms->send($message->recipient, (string) ($message->payload['text'] ?? ''));
                $this->follow($message, MessageStatus::Sent);
            } else {
                $this->email->send($message);
            }
        } catch (Throwable $e) {
            $this->fail($message, $e);

            return;
        }

        $this->mirror($message, PurchaseOrderSend::SENT);

        $message->forceFill([
            'status' => OutboxStatus::Sent,
            'sent_at' => now(),
            'payload' => null,
            'claimed_at' => null,
            'next_attempt_at' => null,
            'last_error' => null,
        ])->save();
    }

    /**
     * Messages due for another attempt whose job was lost (queue flushed, worker killed, delay not honoured).
     */
    public function redispatchDue(): int
    {
        $ids = OutboxMessage::query()
            ->where(function ($q) {
                $q->whereIn('status', [OutboxStatus::Pending->value, OutboxStatus::Failed->value])
                    ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
                    ->where('updated_at', '<=', now()->subMinute());
            })
            ->orWhere(fn ($q) => $q->where('status', OutboxStatus::Sending->value)->where('claimed_at', '<', now()->subMinutes(self::CLAIM_TIMEOUT_MINUTES)))
            ->limit(200)
            ->pluck('id');

        foreach ($ids as $id) {
            DeliverOutboxMessage::dispatch((int) $id);
        }

        return $ids->count();
    }

    private function claim(int $id): ?OutboxMessage
    {
        $claimed = DB::table('outbox_messages')
            ->where('id', $id)
            ->where(function ($q) {
                $q->whereIn('status', [OutboxStatus::Pending->value, OutboxStatus::Failed->value])
                    ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
                    ->orWhere(fn ($q) => $q->where('status', OutboxStatus::Sending->value)->where('claimed_at', '<', now()->subMinutes(self::CLAIM_TIMEOUT_MINUTES)));
            })
            ->update([
                'status' => OutboxStatus::Sending->value,
                'claimed_at' => now(),
                'attempts' => DB::raw('attempts + 1'),
                'updated_at' => now(),
            ]);

        return $claimed === 1 ? OutboxMessage::query()->find($id) : null;
    }

    private function fail(OutboxMessage $message, Throwable $e): void
    {
        $dead = $message->attempts >= self::MAX_ATTEMPTS;
        if ($dead) {
            // A WhatsApp message that could not be delivered still reaches the customer, by SMS; an e-invoice is
            // marked failed for the owner to retry.
            if ($message->channel === OutboxChannel::Zatca) {
                $this->einvoices->exhausted($message);
            } elseif ($message->channel === OutboxChannel::Whatsapp) {
                $this->whatsapp->fallback($message, 'whatsapp_retries_exhausted');
            } else {
                $this->follow($message, MessageStatus::Failed, 'delivery_failed');
                $this->mirror($message, PurchaseOrderSend::FAILED);
            }
        }
        $delay = self::BACKOFF[min($message->attempts, count(self::BACKOFF)) - 1];

        $message->forceFill([
            'status' => $dead ? OutboxStatus::Dead : OutboxStatus::Failed,
            'claimed_at' => null,
            'next_attempt_at' => $dead ? null : now()->addSeconds($delay),
            'last_error' => mb_substr($e::class.': '.$this->scrub($e->getMessage()), 0, 300),
        ])->save();

        // Class and attempt only: provider messages can echo credentials or addresses.
        Log::warning('Outbound message failed', ['outbox_id' => $message->id, 'channel' => $message->channel->value, 'attempt' => $message->attempts, 'exception' => $e::class, 'dead' => $dead]);

        if (! $dead) {
            DeliverOutboxMessage::dispatch($message->id)->delay(now()->addSeconds($delay));
        }
    }

    /**
     * Mirrors an SMS delivery into the customer's message log.
     */
    private function follow(OutboxMessage $message, MessageStatus $status, ?string $error = null): void
    {
        $id = $message->payload['customer_message_id'] ?? null;
        if (is_int($id)) {
            CustomerMessage::query()->find($id)?->advance($status, $error);
        }
    }

    /**
     * Mirrors a purchase-order email's final status onto its send log (ADR-0038).
     */
    private function mirror(OutboxMessage $message, string $status): void
    {
        if ($message->kind === 'purchase_order') {
            PurchaseOrderSend::query()->where('outbox_message_id', $message->id)
                ->update(['status' => $status, 'sent_at' => $status === PurchaseOrderSend::SENT ? now() : null, 'updated_at' => now()]);
        }
    }

    private function scrub(string $text): string
    {
        return preg_replace('/\S+@\S+/', '[email]', $text) ?? '';
    }
}
