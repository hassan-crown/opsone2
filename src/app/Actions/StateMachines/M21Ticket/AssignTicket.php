<?php

namespace App\Actions\StateMachines\M21Ticket;

use App\Access\Exceptions\InvalidTransition;
use App\Enums\StateMachine;
use App\Enums\TicketStatus;
use App\Models\Tickets\Ticket;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Assign or reassign the responsible person (P-59, no status change): an active member who holds P-59 over the ticket
 * (assigning never widens anyone's access). A closed ticket is reopened before it is reassigned.
 */
final class AssignTicket extends StaffTicketAction
{
    public function handle(User $actor, Ticket $ticket, int $assigneeId): Ticket
    {
        return $this->locked($actor, $ticket, function (Ticket $t) use ($actor, $assigneeId) {
            if ($t->status === TicketStatus::Closed) {
                throw new InvalidTransition(StateMachine::M21, 'assign', 'not_from_closed');
            }
            if (! self::eligible($t, $assigneeId)) {
                throw ValidationException::withMessages(['assignee_id' => __('tickets.validation.assignee')]);
            }
            $this->assignTo($t, $assigneeId, $actor, 'assign');

            return $t;
        });
    }
}
