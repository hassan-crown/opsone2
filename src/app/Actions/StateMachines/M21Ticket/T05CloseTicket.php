<?php

namespace App\Actions\StateMachines\M21Ticket;

use App\Models\Tickets\Ticket;
use App\Models\User;

/**
 * M-21-T05 close by staff (P-59): only a resolved ticket closes; the conversation and files stay. The customer's own
 * close is `Portal\CustomerTickets::close`.
 */
final class T05CloseTicket extends StaffTicketAction
{
    public function handle(User $actor, Ticket $ticket): Ticket
    {
        return $this->locked($actor, $ticket, function (Ticket $t) use ($actor) {
            $this->transitions->apply($t, 'T05', $actor, self::SOURCE);
            $this->notices->customer($t, 'closed', "ticket:{$t->id}:closed:{$t->lock_version}");

            return $t;
        });
    }
}
