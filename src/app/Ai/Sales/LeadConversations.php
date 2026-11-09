<?php

namespace App\Ai\Sales;

use App\Ai\Inbox\InboxIntent;
use App\Models\Inbox\Conversation;
use App\Models\Inbox\ConversationMessage;

/**
 * Which conversations count as a new lead: the contact is not a customer yet, or the last classified customer message
 * is «new customer» (AI-4 intent).
 */
final class LeadConversations
{
    public static function isNewLead(Conversation $conversation): bool
    {
        if ($conversation->customer_id === null) {
            return true;
        }
        $intent = $conversation->messages()->where('direction', ConversationMessage::IN)->whereNotNull('ai_intent')
            ->orderByDesc('occurred_at')->orderByDesc('id')->value('ai_intent');

        return $intent === InboxIntent::NewCustomer->value;
    }
}
