<?php

namespace App\Ai\Inbox\Jobs;

use App\Ai\AiTask;
use App\Ai\Inbox\IntentClassifier;
use App\Ai\Inbox\ReplySuggester;
use App\Models\Ai\AiUsage;
use App\Models\Inbox\Conversation;
use App\Models\Inbox\ConversationMessage;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * AI-4 on the queue, dispatched after the inbound message is committed (InboundRecorder): classify it, then — when the
 * conversation has an assignee who may reply, the window is open and nothing is suspicious — draft a reply for that
 * assignee with their own grants. The draft waits in their composer; nothing is sent. Automatic drafts are capped per
 * conversation (config ai.inbox: one per N minutes, a daily maximum). One attempt: the gateway already retries the
 * provider, and a failure only means «no suggestion».
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

        if (self::capped($conversation)) {
            return;
        }

        $suggester->suggest($assignee, $conversation);
    }

    /**
     * Whether this conversation already had a reply draft attempt too recently, or too many today (any outcome).
     */
    public static function capped(Conversation $conversation): bool
    {
        $attempts = AiUsage::query()->where('task', AiTask::ReplySuggest->value)
            ->where('subject_type', $conversation->getMorphClass())->where('subject_id', $conversation->id);

        $minutes = max(0, (int) config('ai.inbox.auto_draft_minutes', 10));
        if ($minutes > 0 && (clone $attempts)->where('created_at', '>=', now()->subMinutes($minutes))->exists()) {
            return true;
        }

        return (clone $attempts)->where('created_at', '>=', now()->subDay())->count() >= max(0, (int) config('ai.inbox.auto_draft_daily', 10));
    }
}
