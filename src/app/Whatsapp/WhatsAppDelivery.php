<?php

namespace App\Whatsapp;

use App\Models\Customer;
use App\Models\CustomerMessage;
use App\Models\OutboxMessage;
use App\Notifications\Channels\WhatsApp\WhatsAppChannel;
use App\Notifications\Channels\WhatsApp\WhatsAppException;
use App\Notifications\Channels\WhatsApp\WhatsAppTemplateMessage;
use App\Notifications\Outbox;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Delivers one WhatsApp outbox message (called by OutboxDeliverer after it claimed the row). Consent and the
 * facility's switches are re-checked now, not only when the message was queued. Whatever WhatsApp cannot do —
 * channel off, customer opted out meanwhile, driver unconfigured, recipient not on WhatsApp, retries exhausted,
 * the provider reporting the send failed — falls back to the same text by SMS, once (the SMS key is derived from
 * the WhatsApp key).
 */
final class WhatsAppDelivery
{
    public function __construct(
        private readonly WhatsAppChannel $channel,
        private readonly WhatsAppSettings $settings,
        private readonly Outbox $outbox,
    ) {}

    /**
     * @return bool true when the template was handed to the provider; false when the message fell back to SMS
     *
     * @throws WhatsAppException retryable provider failure (the outbox retries with backoff)
     */
    public function deliver(OutboxMessage $outbox): bool
    {
        $payload = $outbox->payload ?? [];
        $log = $this->log($payload);
        $event = $log?->event;
        $customer = $log === null ? null : Customer::query()->find($log->customer_id);

        if ($log === null || $event === null || $customer === null) {
            return $this->fallback($outbox, 'whatsapp_unavailable');
        }
        if ($log->provider_message_id !== null) {
            // An earlier attempt reached the provider but did not get to mark the outbox row: never send twice.
            $log->advance(MessageStatus::Sent);

            return true;
        }
        if (! $this->settings->allows($event) || ! $this->channel->isConfigured() || ! CustomerMessenger::consents($customer)) {
            return $this->fallback($outbox, 'whatsapp_off');
        }

        try {
            $id = $this->channel->sendTemplate(new WhatsAppTemplateMessage(
                $outbox->recipient,
                (string) $payload['template'],
                (string) $payload['language'],
                array_values(array_map('strval', (array) ($payload['parameters'] ?? []))),
            ));
        } catch (WhatsAppException $e) {
            if ($e->retryable) {
                throw $e;
            }
            Log::warning('WhatsApp refused', ['outbox_id' => $outbox->id, 'code' => $e->providerCode]);

            return $this->fallback($outbox, 'whatsapp_'.($e->providerCode ?? 'refused'));
        }

        $log->forceFill(['provider_message_id' => $id])->save();
        $log->advance(MessageStatus::Sent);

        return true;
    }

    /**
     * Sends the same text by SMS instead. Safe to call more than once (SMS key, log row state).
     */
    public function fallback(OutboxMessage $outbox, string $reason): bool
    {
        $payload = $outbox->payload ?? [];
        $log = $this->log($payload);

        DB::transaction(function () use ($outbox, $payload, $log, $reason) {
            $text = (string) ($payload['sms_text'] ?? '');
            if ($log !== null && $log->status !== MessageStatus::Delivered && $log->status !== MessageStatus::Read) {
                $log->forceFill(['channel' => 'sms', 'fell_back' => true, 'status' => MessageStatus::Queued, 'error' => mb_substr($reason, 0, 64), 'failed_at' => null])->save();
            }
            if ($text !== '') {
                $this->outbox->enqueueSms($outbox->recipient, $text, preg_replace('/:wa$/', '', $outbox->idempotency_key).':sms', $log?->id);
            }
        });

        return false;
    }

    /**
     * The provider accepted the message but later reported it failed (webhook): the customer still gets the text by
     * SMS, once. The outbox payload is long gone, so the text comes from the log row.
     */
    public function fallbackAfterReportedFailure(CustomerMessage $log, string $error): void
    {
        $customer = Customer::query()->find($log->customer_id);
        if ($customer === null || $log->channel !== 'whatsapp' || in_array($log->status, [MessageStatus::Delivered, MessageStatus::Read], true)) {
            return;
        }

        DB::transaction(function () use ($log, $customer, $error) {
            $log->forceFill(['channel' => 'sms', 'fell_back' => true, 'status' => MessageStatus::Queued, 'error' => mb_substr($error, 0, 64), 'failed_at' => null])->save();
            $this->outbox->enqueueSms($customer->mobile, (string) $log->body, $log->idempotency_key.':sms', $log->id);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function log(array $payload): ?CustomerMessage
    {
        $id = $payload['customer_message_id'] ?? null;

        return is_int($id) ? CustomerMessage::query()->find($id) : null;
    }
}
