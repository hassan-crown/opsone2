<?php

namespace App\Actions\StateMachines\M21Ticket;

use App\Models\Tickets\Ticket;
use App\Models\Tickets\TicketMessage;
use App\Models\User;

/**
 * M-21-T04 resolve (P-59): a clear description of the handling, shown to the customer as the last public message. A
 * ticket is never a financial settlement or a delivery by itself: nothing outside the ticket changes.
 */
final class T04ResolveTicket extends StaffTicketAction
{
    public function handle(User $actor, Ticket $ticket, string $resolution): Ticket
    {
        return $this->locked($actor, $ticket, function (Ticket $t) use ($actor, $resolution) {
            $this->transitions->apply($t, 'T04', $actor, self::SOURCE);
            $t->forceFill(['resolution' => $resolution])->save();
            $message = $this->messages->add($t, TicketMessage::PUBLIC, $actor, $resolution);
            $this->notices->customer($t, 'resolved', "ticket:{$t->id}:message:{$message->id}");

            return $t;
        });
    }
}
