<?php

namespace App\Inbox\Jobs;

use App\Inbox\InboxSettings;
use App\Models\Inbox\ConversationMessage;
use App\Notifications\Channels\WhatsApp\WhatsAppException;
use App\Notifications\Channels\WhatsApp\WhatsAppTemplateMessage;
use App\Whatsapp\Inbox\ConversationTransport;
use App\Whatsapp\MessageStatus;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Sends one queued inbox reply (AI-3). At most once: the row is claimed atomically (queued → sending) before the call,
 * and the provider id is saved as soon as it is returned; only a retryable refusal puts it back to `queued` for another
 * attempt (backoff 30 s → 5 min, 4 attempts). The rules are re-checked at delivery: inbox on, provider configured,
 * customer not opted out, and for free text the 24-hour window still open.
 */
class SendConversationMessage implements ShouldQueue
{
    use Queueable;

    public const SENDING = 'sending';

    /** A claim older than this is a worker that died mid-call (the job timeout is 60 s). */
    public const STALE_AFTER_MINUTES = 15;

    public int $tries = 4;

    public int $timeout = 60;

    /** @var list<int> */
    public array $backoff = [30, 120, 300];

    public function __construct(public int $messageId)
    {
        $this->afterCommit();
    }

    public function handle(ConversationTransport $transport, InboxSettings $settings): void
    {
        $claimed = ConversationMessage::query()
            ->whereKey($this->messageId)
            ->where('direction', ConversationMessage::OUT)
            ->where('status', MessageStatus::Queued->value)
            ->whereNull('provider_message_id')
            ->update(['status' => self::SENDING, 'updated_at' => now()]);
        if ($claimed !== 1) {
            return;
        }

        /** @var ConversationMessage $message */
        $message = ConversationMessage::query()->with('conversation.customer')->findOrFail($this->messageId);
        $conversation = $message->conversation;

        $refusal = match (true) {
            ! $settings->enabled() => 'inbox_disabled',
            ! $transport->isConfigured() => 'not_configured',
            $conversation->customer?->whatsapp_opt_out_at !== null => 'opted_out',
            $message->type === 'text' && ! $conversation->windowOpen() => 'window_closed',
            default => null,
        };
        if ($refusal !== null) {
            $this->markFailed($message, $refusal);

            return;
        }

        try {
            $id = $message->type === 'template'
                ? $transport->sendTemplate(new WhatsAppTemplateMessage(
                    $conversation->wa_id,
                    (string) $message->template,
                    (string) $message->template_language,
                    $this->templateParameters($message),
                ))
                : $transport->sendText($conversation->wa_id, (string) $message->body);
        } catch (WhatsAppException $e) {
            if ($e->retryable && $this->attempts() < $this->tries) {
                $message->forceFill(['status' => MessageStatus::Queued->value, 'error' => $e->providerCode])->save();
                $this->release($this->backoff[min($this->attempts() - 1, count($this->backoff) - 1)]);

                return;
            }
            $this->markFailed($message, $e->retryable ? 'retries_exhausted' : (string) ($e->providerCode ?? 'refused'));

            return;
        } catch (Throwable $e) {
            report($e);
            $this->markFailed($message, 'error');

            return;
        }

        $message->forceFill(['provider_message_id' => $id, 'status' => MessageStatus::Sent->value, 'sent_at' => now(), 'error' => null])->save();
    }

    /**
     * The queue gave up (timeout, worker killed, exception outside handle): the reply must not stay «queued» or
     * «sending» forever — staff see it failed and may write again. Never re-sent: the provider may have accepted it.
     */
    public function failed(?Throwable $exception): void
    {
        ConversationMessage::query()
            ->whereKey($this->messageId)
            ->whereIn('status', [MessageStatus::Queued->value, self::SENDING])
            ->whereNull('provider_message_id')
            ->update(['status' => MessageStatus::Failed->value, 'failed_at' => now(), 'error' => 'job_failed', 'updated_at' => now()]);
    }

    /**
     * Safety net for claims whose worker died without the queue calling failed() (e.g. the process was killed): a
     * reply still `sending` after STALE_AFTER_MINUTES is marked failed, never re-sent (at most once). Scheduled.
     */
    public static function failStale(): int
    {
        return ConversationMessage::query()
            ->where('direction', ConversationMessage::OUT)
            ->where('status', self::SENDING)
            ->whereNull('provider_message_id')
            ->where('updated_at', '<', now()->subMinutes(self::STALE_AFTER_MINUTES))
            ->update(['status' => MessageStatus::Failed->value, 'failed_at' => now(), 'error' => 'stale_sending', 'updated_at' => now()]);
    }

    /**
     * @return list<string>
     */
    private function templateParameters(ConversationMessage $message): array
    {
        $decoded = json_decode((string) $message->body, true);

        return is_array($decoded) ? array_values(array_map('strval', (array) ($decoded['params'] ?? []))) : [];
    }

    private function markFailed(ConversationMessage $message, string $code): void
    {
        $message->forceFill(['status' => MessageStatus::Failed->value, 'failed_at' => now(), 'error' => mb_substr($code, 0, 64)])->save();
    }
}
