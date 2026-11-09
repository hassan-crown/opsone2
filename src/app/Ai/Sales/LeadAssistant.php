<?php

namespace App\Ai\Sales;

use App\Access\Access;
use App\Access\Permission;
use App\Ai\AiFeature;
use App\Ai\AiGateway;
use App\Ai\Inbox\ReplySuggester;
use App\Enums\CustomOrderStatus;
use App\Enums\ReadyOrderStatus;
use App\Models\Inbox\Conversation;
use App\Models\Order;
use App\Models\User;
use App\Orders\OrderAccess;

/**
 * What the inbox screen shows for a new lead (AI-7a): the staff member's own draft and extracted fields, and the
 * measurement slots computed from the calendar. Empty (`available: false`) unless the feature is on and licensed, the
 * staff member may reply here, and the conversation is a new lead.
 */
final class LeadAssistant
{
    public function __construct(private readonly AiGateway $gateway, private readonly SlotFinder $slots) {}

    /**
     * @return array<string, mixed>
     */
    public function panel(Conversation $conversation, User $viewer): array
    {
        $available = $this->gateway->available(AiFeature::Sales)
            && ReplySuggester::mayReply($viewer, $conversation)
            && LeadConversations::isNewLead($conversation);
        if (! $available) {
            return ['available' => false];
        }

        $open = LeadQualifier::open($viewer, $conversation);
        // Slots (and the staff ids behind them) are for those who may book; others get no slots at all.
        $canBook = Access::can($viewer, Permission::P54);
        $slots = $canBook && $conversation->branch_id !== null ? $this->slots->find((int) $conversation->branch_id) : [];
        $lead = LeadApplier::existing($conversation);
        $orders = $this->bookableOrders($conversation, $viewer);

        return [
            'available' => true,
            'can_accept' => Access::can($viewer, Permission::P09),
            'suggestion' => $open === null ? null : [
                'id' => $open->id,
                'reply' => (string) ($open->payload['reply'] ?? ''),
                'fields' => (array) ($open->payload['fields'] ?? []),
            ],
            'interest' => $lead === null ? null : ['name' => $lead->name, 'need' => $lead->need],
            'slots' => array_map(fn (Slot $s) => $s->toArray(), $slots),
            // The sentence staff may paste into the reply; the times are the server's, never the model's.
            'slots_text' => $slots === [] ? null : __('inbox.ai.lead.slots_intro')."\n".implode("\n", array_map(fn (Slot $s) => '- '.$s->label, $slots)),
            'orders' => $orders,
        ];
    }

    /**
     * @return list<array{id: int, number: string}>
     */
    private function bookableOrders(Conversation $conversation, User $viewer): array
    {
        if ($conversation->customer_id === null || $conversation->branch_id === null || ! Access::can($viewer, Permission::P54)) {
            return [];
        }
        $closed = [CustomOrderStatus::Cancelled->value, CustomOrderStatus::Closed->value, ReadyOrderStatus::Delivered->value];

        return array_values(OrderAccess::visible($viewer)->where('orders.customer_id', $conversation->customer_id)->where('orders.branch_id', $conversation->branch_id)
            ->whereNotIn('orders.status', $closed)->orderByDesc('orders.id')->limit(5)->get()
            ->map(fn (Order $o) => ['id' => (int) $o->id, 'number' => (string) $o->number])->all());
    }
}
