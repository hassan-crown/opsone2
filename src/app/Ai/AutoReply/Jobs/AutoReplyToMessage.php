<?php

namespace App\Ai\AutoReply\Jobs;

use App\Ai\AutoReply\AutoReplier;
use App\Models\Inbox\ConversationMessage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * AI-6 on the queue, dispatched after an inbound message is committed (InboundRecorder) while the automatic reply is
 * on. One attempt: the gateway already retries the provider, and a failure only means «staff answer».
 */
final class AutoReplyToMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public readonly int $messageId)
    {
        $this->afterCommit();
    }

    public function handle(AutoReplier $replier): void
    {
        $message = ConversationMessage::query()->find($this->messageId);
        if ($message !== null) {
            $replier->handle($message);
        }
    }
}
