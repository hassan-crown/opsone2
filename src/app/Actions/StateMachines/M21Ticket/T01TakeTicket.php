<?php

namespace App\Actions\StateMachines\M21Ticket;

use App\Models\Tickets\Ticket;
use App\Models\User;

/**
 * M-21-T01 take (P-59): a new ticket moves to «in progress» and the actor becomes its responsible person.
 */
final class T01TakeTicket extends StaffTicketAction
{
    public function handle(User $actor, Ticket $ticket): Ticket
    {
        return $this->locked($actor, $ticket, function (Ticket $t) use ($actor) {
            $this->transitions->apply($t, 'T01', $actor, self::SOURCE);
            $this->assignTo($t, $actor->id, $actor, 'take');
            $this->notices->customer($t, 'taken', "ticket:{$t->id}:taken");

            return $t;
        });
    }
}
