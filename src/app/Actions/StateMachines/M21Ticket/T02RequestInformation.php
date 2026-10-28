<?php

namespace App\Actions\StateMachines\M21Ticket;

use App\Models\Tickets\Ticket;
use App\Models\Tickets\TicketMessage;
use App\Models\User;
use Illuminate\Http\UploadedFile;

/**
 * M-21-T02 request information (P-59): a clear public question for the customer; the ticket waits for them. Files
 * attached here reach the customer, so internal files belong to internal notes only.
 */
final class T02RequestInformation extends StaffTicketAction
{
    /**
     * @param  list<UploadedFile>  $files
     */
    public function handle(User $actor, Ticket $ticket, string $question, array $files = []): Ticket
    {
        return $this->locked($actor, $ticket, function (Ticket $t) use ($actor, $question, $files) {
            $this->transitions->apply($t, 'T02', $actor, self::SOURCE);
            $message = $this->messages->add($t, TicketMessage::PUBLIC, $actor, $question, $files);
            $this->notices->customer($t, 'info', "ticket:{$t->id}:message:{$message->id}");

            return $t;
        });
    }
}
