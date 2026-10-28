<?php

namespace App\Actions\StateMachines\M21Ticket;

use App\Models\Tickets\Ticket;
use App\Models\Tickets\TicketMessage;
use App\Models\User;
use App\Tickets\TicketAccess;
use App\Tickets\TicketNotices;

/**
 * M-21-T06 reopen by staff (P-59) for a continuing problem, with the reason (kept as an internal note and in the
 * transition). The same ticket returns to «in progress»; it is reassigned when its assignee is no longer eligible.
 */
final class T06ReopenTicket extends StaffTicketAction
{
    public function handle(User $actor, Ticket $ticket, string $reason): Ticket
    {
        return $this->locked($actor, $ticket, function (Ticket $t) use ($actor, $reason) {
            $this->transitions->apply($t, 'T06', $actor, self::SOURCE, $reason);
            $this->messages->add($t, TicketMessage::INTERNAL, $actor, $reason);
            if (! TicketAccess::isEligible($t->assignee_id, $t)) {
                $this->assignTo($t, TicketAccess::defaultAssignee($t), $actor, 'reopen');
            } else {
                $this->notices->staff($t->assignee, $actor, TicketNotices::ASSIGNED, $t, "ticket:{$t->id}:reopened:{$t->lock_version}");
            }
            $this->notices->customer($t, 'reopened', "ticket:{$t->id}:reopened:{$t->lock_version}");

            return $t;
        });
    }
}
