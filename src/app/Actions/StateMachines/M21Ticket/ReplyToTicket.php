<?php

namespace App\Actions\StateMachines\M21Ticket;

use App\Access\Exceptions\InvalidTransition;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Enums\StateMachine;
use App\Enums\TicketStatus;
use App\Models\Tickets\Ticket;
use App\Models\Tickets\TicketMessage;
use App\Models\User;
use Illuminate\Http\UploadedFile;

/**
 * A staff message (P-59), no status change: a PUBLIC reply the customer sees (while the ticket is being handled or waits
 * for them; a new ticket is taken first and a resolved/closed one reopened first) or an INTERNAL note (any state) that
 * no customer payload, notice or download ever carries.
 */
final class ReplyToTicket extends StaffTicketAction
{
    /**
     * @param  list<UploadedFile>  $files
     */
    public function handle(User $actor, Ticket $ticket, string $visibility, string $body, array $files = []): TicketMessage
    {
        return $this->locked($actor, $ticket, function (Ticket $t) use ($actor, $visibility, $body, $files) {
            $public = $visibility === TicketMessage::PUBLIC;
            if ($public && ! in_array($t->status, [TicketStatus::InProgress, TicketStatus::AwaitingCustomer], true)) {
                throw new InvalidTransition(StateMachine::M21, 'reply', 'not_from_'.$t->status->value);
            }
            $message = $this->messages->add($t, $public ? TicketMessage::PUBLIC : TicketMessage::INTERNAL, $actor, $body, $files);
            Audit::record($public ? AuditAction::TicketReplied : AuditAction::TicketNoted, $t, $actor,
                after: ['message' => $message->id, 'files' => count($files)], source: self::SOURCE, branchIds: [$t->branch_id], label: $t->number);
            if ($public) {
                $this->notices->customer($t, 'reply', "ticket:{$t->id}:message:{$message->id}");
            }

            return $message;
        });
    }
}
