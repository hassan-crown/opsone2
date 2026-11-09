<?php

namespace App\Ai\Inbox\Jobs;

use App\Ai\Inbox\IntentClassifier;
use App\Ai\Inbox\ReplySuggester;
use App\Models\Inbox\Conversation;
use App\Models\Inbox\ConversationMessage;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * AI-4 on the queue, dispatched after the inbound message is committed (InboundRecorder): classify it, then — when the
 * conversation has an assignee who may reply, the window is open and nothing is suspicious — draft a reply for that
 * assignee with their own grants. The draft waits in their composer; nothing is sent. One attempt: the gateway already
 * retries the provider, and a failure only means «no suggestion».
 */
final class ClassifyInboundMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public readonly int $messageId)
    {
        $this->afterCommit();
    }

    public function handle(IntentClassifier $classifier, ReplySuggester $suggester): void
    {
        $message = ConversationMessage::query()->find($this->messageId);
        if ($message === null || $message->direction !== ConversationMessage::IN) {
            return;
        }

        $suspicious = $classifier->classify($message);

        $conversation = Conversation::query()->with('customer')->find($message->conversation_id);
        $assignee = $conversation?->assignee_id === null ? null : User::query()->find($conversation->assignee_id);
        if ($conversation === null || $assignee === null || $suspicious || $conversation->ai_flagged_at !== null) {
            return;
        }
        // Only for the newest customer message: a burst of messages gets one draft, for the last one.
        $newest = $conversation->messages()->where('direction', ConversationMessage::IN)->orderByDesc('occurred_at')->orderByDesc('id')->value('id');
        if ($newest !== $message->id || trim((string) $message->body) === '') {
            return;
        }

        $suggester->suggest($assignee, $conversation);
    }
}
