<?php

namespace App\Ai\Inbox;

use App\Ai\AiSuggestions;
use App\Ai\AiTask;
use App\Models\Ai\AiSuggestion;
use App\Models\Inbox\Conversation;
use App\Models\Inbox\ConversationMessage;
use App\Models\User;
use LogicException;

/**
 * The inbox side of a reply suggestion (AI-4): what the composer shows, and how a staff member's decision is recorded.
 * A suggestion belongs to the staff member it was built for (its context followed their grants), so nobody else sees
 * or decides it. Sending never happens here: the reply goes through ReplySender like any other, and only afterwards is
 * the suggestion marked accepted (as is) or edited (with what was actually sent).
 */
final class InboxSuggestions
{
    public function __construct(
        private readonly AiSuggestions $suggestions,
        private readonly ReplySuggester $suggester,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function panel(Conversation $conversation, User $viewer): array
    {
        $available = $this->suggester->available() && ReplySuggester::mayReply($viewer, $conversation);
        $open = $available ? ReplySuggester::open($viewer, $conversation) : null;
        $lastIntent = $conversation->messages()->where('direction', ConversationMessage::IN)->whereNotNull('ai_intent')
            ->orderByDesc('occurred_at')->orderByDesc('id')->value('ai_intent');

        return [
            'available' => $available,
            'flagged' => $conversation->ai_flagged_at !== null,
            'intent' => $lastIntent,
            'suggestion' => $open === null ? null : [
                'id' => $open->id,
                'reply' => (string) ($open->payload['reply'] ?? ''),
                'facts' => array_values((array) ($open->payload['facts'] ?? [])),
                'created_at' => $open->created_at?->toIso8601String(),
            ],
        ];
    }

    /**
     * The staff member's own open suggestion in this conversation, or null (foreign, decided, expired).
     */
    public function find(User $user, Conversation $conversation, int $id): ?AiSuggestion
    {
        $open = ReplySuggester::open($user, $conversation);

        return $open !== null && $open->id === $id ? $open : null;
    }

    /**
     * After the reply was queued through ReplySender: accepted as is, or edited with the text actually sent.
     */
    public function sent(User $user, Conversation $conversation, int $suggestionId, ConversationMessage $message): void
    {
        $suggestion = $this->find($user, $conversation, $suggestionId);
        if ($suggestion === null || $message->type !== 'text' || $message->ai_suggestion_id !== null) {
            return;
        }

        try {
            $this->suggestions->accept($suggestion, $user, ['reply' => trim((string) $message->body)] + array_diff_key($suggestion->payload, ['reply' => true]));
        } catch (LogicException) {
            return;
        }
        $message->forceFill(['ai_suggestion_id' => $suggestion->id])->save();
    }

    public function reject(User $user, Conversation $conversation, int $suggestionId): bool
    {
        $suggestion = $this->find($user, $conversation, $suggestionId);
        if ($suggestion === null || $suggestion->task !== AiTask::ReplySuggest->value) {
            return false;
        }

        try {
            $this->suggestions->reject($suggestion, $user);
        } catch (LogicException) {
            return false;
        }

        return true;
    }
}
