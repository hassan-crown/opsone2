<?php

namespace App\Portal;

use App\Access\TransitionRecorder;
use App\Agreements\CustomerActor;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Enums\StateMachine;
use App\Enums\TicketStatus;
use App\Enums\TicketType;
use App\Files\Rules\AllowedAttachment;
use App\Models\Customer;
use App\Models\Tickets\Ticket;
use App\Models\Tickets\TicketMessage;
use App\Support\GaplessSequence;
use App\Support\LockingTransaction;
use App\Tickets\TicketAccess;
use App\Tickets\TicketMessages;
use App\Tickets\TicketNotices;
use App\Tickets\TicketTransitions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * What the customer does with their tickets (P-71, SHR-08): open one — general or linked to one of THEIR orders —
 * (M-21-T00), reply (M-21-T03 while it waits for them; a reply to a resolved or closed ticket reopens that same
 * ticket, T06) and close a resolved one (T05). Every ticket is re-checked as theirs under its lock; a foreign order id
 * is a validation error that reveals nothing, and nothing is written.
 */
final class CustomerTickets
{
    public function __construct(
        private readonly GaplessSequence $numbers,
        private readonly TransitionRecorder $transitions,
        private readonly TicketTransitions $flow,
        private readonly TicketMessages $messages,
        private readonly TicketNotices $notices,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(TicketType::class)],
            'subject' => ['required', 'string', 'max:160'],
            'body' => ['required', 'string', 'max:5000'],
            'order_id' => ['nullable', 'integer'],
            'client_key' => ['nullable', 'string', 'max:64'],
            'files' => ['nullable', 'array', 'max:'.TicketMessages::MAX_FILES],
            'files.*' => ['file', new AllowedAttachment],
        ];
    }

    /**
     * @param  array{type: string, subject: string, body: string, order_id?: int|string|null, client_key?: string|null}  $data
     * @param  list<UploadedFile>  $files
     */
    public function open(CustomerActor $actor, array $data, array $files = []): Ticket
    {
        $customer = Customer::query()->findOrFail($actor->customerId);
        $orderId = $data['order_id'] ?? null;
        $order = null;
        if ($orderId !== null && $orderId !== '') {
            $order = PortalAccess::orders($customer)->whereKey((int) $orderId)->first();
            if ($order === null) {
                throw ValidationException::withMessages(['order_id' => __('tickets.validation.order')]);
            }
        }
        $key = ($data['client_key'] ?? null) ?: null;

        return LockingTransaction::run(function () use ($customer, $order, $data, $files, $key) {
            if ($key !== null && ($existing = Ticket::query()->where('customer_id', $customer->id)->where('client_key', $key)->first()) !== null) {
                return $existing;
            }
            $ticket = Ticket::query()->create([
                'number' => $this->numbers->format('ticket', 'TK', 6),
                'customer_id' => $customer->id, 'order_id' => $order?->id, 'branch_id' => $order->branch_id ?? $customer->branch_id,
                'type' => $data['type'], 'subject' => $data['subject'], 'status' => TicketStatus::New,
                'client_key' => $key, 'last_customer_activity_at' => now(),
            ]);
            $ticket->setRelation('customer', $customer);
            $ticket->setRelation('order', $order);
            $ticket->forceFill(['assignee_id' => TicketAccess::defaultAssignee($ticket)])->save();

            $this->messages->add($ticket, TicketMessage::PUBLIC, null, $data['body'], $files);
            $this->transitions->record(StateMachine::M21, 'T00', $ticket, null, TicketStatus::New, null, PortalSession::SOURCE, null, [
                'ticket' => $ticket->number, 'order_id' => $order?->id, 'type' => $ticket->type->value,
            ]);
            Audit::record(AuditAction::TicketOpened, $ticket, null, after: [
                'ticket' => $ticket->number, 'type' => $ticket->type->value, 'order' => $order?->number, 'files' => count($files),
            ], source: PortalSession::SOURCE, branchIds: [$ticket->branch_id], label: $ticket->number);
            $this->notices->staff($ticket->assignee, null, TicketNotices::OPENED, $ticket, "ticket:{$ticket->id}:opened");

            return $ticket;
        });
    }

    /**
     * @param  list<UploadedFile>  $files
     */
    public function reply(CustomerActor $actor, Ticket $ticket, string $body, array $files = []): TicketMessage
    {
        Validator::make(['body' => $body], ['body' => ['required', 'string', 'max:5000']])->validate();

        return LockingTransaction::run(function () use ($actor, $ticket, $body, $files) {
            $locked = $this->own($actor, $ticket);
            $from = $locked->status;
            match ($from) {
                TicketStatus::AwaitingCustomer => $this->flow->apply($locked, 'T03', null, PortalSession::SOURCE),
                TicketStatus::Resolved, TicketStatus::Closed => $this->flow->apply($locked, 'T06', null, PortalSession::SOURCE, 'customer_reply'),
                default => null,
            };
            $message = $this->messages->add($locked, TicketMessage::PUBLIC, null, $body, $files);
            $locked->forceFill(['last_customer_activity_at' => now()])->save();
            if (in_array($from, [TicketStatus::Resolved, TicketStatus::Closed], true) && ! TicketAccess::isEligible($locked->assignee_id, $locked)) {
                $locked->forceFill(['assignee_id' => TicketAccess::defaultAssignee($locked)])->save();
            }
            Audit::record(AuditAction::TicketReplied, $locked, null, after: ['message' => $message->id, 'files' => count($files)],
                source: PortalSession::SOURCE, branchIds: [$locked->branch_id], label: $locked->number);
            $this->notices->staff($locked->assignee, null, TicketNotices::CUSTOMER_REPLIED, $locked, "ticket:{$locked->id}:message:{$message->id}");

            return $message;
        });
    }

    public function close(CustomerActor $actor, Ticket $ticket): Ticket
    {
        return LockingTransaction::run(function () use ($actor, $ticket) {
            $locked = $this->own($actor, $ticket);
            $this->flow->apply($locked, 'T05', null, PortalSession::SOURCE, 'closed_by_customer');

            return $locked;
        });
    }

    private function own(CustomerActor $actor, Ticket $ticket): Ticket
    {
        $locked = Ticket::query()->lockForUpdate()->with(['customer', 'order', 'assignee'])->findOrFail($ticket->id);
        if ($locked->customer_id !== $actor->customerId) {
            throw new AuthorizationException;
        }

        return $locked;
    }
}
