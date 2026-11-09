<?php

namespace App\Ai\Sales;

use App\Actions\StateMachines\M16Appointment\T00ProposeAppointment;
use App\Actions\StateMachines\M16Appointment\T02ConfirmAppointment;
use App\Inbox\InboxAccess;
use App\Models\Appointment;
use App\Models\Inbox\Conversation;
use App\Models\User;
use App\Orders\OrderAccess;
use App\Support\LockingTransaction;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Books one of the offered measurement slots after a staff member confirms it (never by the model, never on its own).
 *
 * The slot must be one SlotFinder still offers for the conversation's branch; the booking itself is the existing M-16
 * pair — T00 (propose, P-16/P-54 over the order, members validated) then T02 (confirm, atomic conflict check) — in one
 * transaction, so a refusal (a clash, the policy «block», a missing reason) leaves nothing behind. No conflict reason is
 * ever passed from here: an overlap is refused, not forced. Appointments belong to an order, so a contact with no open
 * order cannot be booked until the lead becomes one.
 */
final class LeadBooking
{
    public function __construct(
        private readonly SlotFinder $slots,
        private readonly T00ProposeAppointment $propose,
        private readonly T02ConfirmAppointment $confirm,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function book(User $actor, Conversation $conversation, int $orderId, string $startsAt, int $memberId, string $address): Appointment
    {
        if (! InboxAccess::canOpen($actor, $conversation)) {
            throw new AuthorizationException;
        }
        if ($conversation->branch_id === null || $conversation->customer_id === null) {
            throw ValidationException::withMessages(['slot' => __('inbox.ai.lead.errors.needs_order')]);
        }
        // Only an order of this conversation's customer, visible to the staff member.
        $order = OrderAccess::visible($actor)->where('orders.customer_id', $conversation->customer_id)
            ->where('orders.branch_id', $conversation->branch_id)->find($orderId);
        if ($order === null) {
            throw ValidationException::withMessages(['order_id' => __('inbox.ai.lead.errors.order')]);
        }

        $slot = $this->slots->validate((int) $conversation->branch_id, CarbonImmutable::parse($startsAt), $memberId);
        if ($slot === null) {
            throw ValidationException::withMessages(['slot' => __('inbox.ai.lead.errors.slot_gone')]);
        }

        return LockingTransaction::run(function () use ($actor, $order, $slot, $address) {
            $created = $this->propose->handle($actor, $order, [
                'kind' => 'measurement',
                'starts_at' => $slot->startsAt->toIso8601String(),
                'ends_at' => $slot->endsAt->toIso8601String(),
                'address' => $address,
                'member_ids' => [$slot->memberId],
            ])['appointment'];

            return $this->confirm->handle($actor, $created, 'message');
        });
    }
}
