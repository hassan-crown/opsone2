<?php

namespace App\Notifications;

use App\Jobs\DeliverOutboxMessage;
use App\Models\OutboxMessage;
use App\Notifications\Channels\EmailChannel;
use App\Notifications\Channels\SmsChannel;
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
    ) {}

    public function deliver(int $id): void
    {
        $message = $this->claim($id);

        if ($message === null) {
            return;
        }

        try {
            if ($message->channel === OutboxChannel::Sms) {
                if (! $this->sms->isConfigured()) {
                    $message->forceFill(['status' => OutboxStatus::Skipped, 'payload' => null, 'claimed_at' => null, 'last_error' => 'sms_not_configured'])->save();

                    return;
                }
                $this->sms->send($message->recipient, (string) ($message->payload['text'] ?? ''));
            } else {
                $this->email->send($message);
            }
        } catch (Throwable $e) {
            $this->fail($message, $e);

            return;
        }

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

    private function scrub(string $text): string
    {
        return preg_replace('/\S+@\S+/', '[email]', $text) ?? '';
    }
}
