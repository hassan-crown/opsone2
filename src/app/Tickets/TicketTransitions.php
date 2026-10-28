<?php

namespace App\Tickets;

use App\Access\Exceptions\InvalidTransition;
use App\Access\TransitionRecorder;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Enums\StateMachine;
use App\Enums\TicketStatus;
use App\Models\Tickets\Ticket;
use App\Models\User;

/**
 * The single place a ticket changes status (M-21): the move is validated against the transition table BEFORE any write,
 * then the row, the transition log and the audit trail change together in the caller's transaction.
 */
final class TicketTransitions
{
    public function __construct(private readonly TransitionRecorder $transitions) {}

    /**
     * @param  array<string, mixed>  $context
     */
    public function apply(Ticket $ticket, string $code, ?User $actor, string $source, ?string $reason = null, array $context = []): Ticket
    {
        $def = TicketStatus::transitions()[$code];
        $from = $ticket->status;
        if (! in_array($from, $def['from'], true)) {
            throw new InvalidTransition(StateMachine::M21, $code, 'not_from_'.$from->value);
        }

        $ticket->status = $def['to'];
        match ($def['to']) {
            TicketStatus::Resolved => $ticket->resolved_at = now(),
            TicketStatus::Closed => $ticket->closed_at = now(),
            TicketStatus::InProgress => [$ticket->resolved_at, $ticket->closed_at] = [null, null],
            default => null,
        };
        $ticket->lock_version++;
        $ticket->save();

        $this->transitions->record(StateMachine::M21, $code, $ticket, $from, $def['to'], $actor, $source, $reason, ['ticket' => $ticket->number] + $context);
        Audit::record(AuditAction::TicketStatusChanged, $ticket, $actor, before: ['status' => $from->value], after: ['status' => $def['to']->value, 'transition' => $code],
            reason: $reason, source: $source, branchIds: [$ticket->branch_id], label: $ticket->number);

        return $ticket;
    }
}
