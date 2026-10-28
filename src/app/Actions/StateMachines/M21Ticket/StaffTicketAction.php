<?php

namespace App\Actions\StateMachines\M21Ticket;

use App\Access\Access;
use App\Access\Permission;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Models\Tickets\Ticket;
use App\Models\User;
use App\Support\LockingTransaction;
use App\Tickets\TicketAccess;
use App\Tickets\TicketMessages;
use App\Tickets\TicketNotices;
use App\Tickets\TicketTransitions;
use Closure;

/**
 * Base of the staff ticket Actions: lock the row, authorize P-59 on the LOCKED ticket (scope facts are the live ones),
 * run the work in one transaction. A refused step leaves no message, no transition and no notice.
 */
abstract class StaffTicketAction
{
    public const SOURCE = 'web';

    public function __construct(
        protected readonly TicketTransitions $transitions,
        protected readonly TicketMessages $messages,
        protected readonly TicketNotices $notices,
    ) {}

    /**
     * @template T
     *
     * @param  Closure(Ticket): T  $work
     * @return T
     */
    protected function locked(User $actor, Ticket $ticket, Closure $work): mixed
    {
        // Cheap pre-check on the loaded row: an unauthorized actor never takes the lock.
        Access::authorize($actor, Permission::P59, $ticket);

        return LockingTransaction::run(function () use ($actor, $ticket, $work) {
            $locked = Ticket::query()->lockForUpdate()->with(['customer', 'order'])->findOrFail($ticket->id);
            Access::authorize($actor, Permission::P59, $locked);

            return $work($locked);
        });
    }

    protected function assignTo(Ticket $ticket, ?int $userId, ?User $actor, string $reason): void
    {
        if ($ticket->assignee_id === $userId) {
            return;
        }
        Audit::record(AuditAction::TicketAssigned, $ticket, $actor,
            before: ['assignee_id' => $ticket->assignee_id], after: ['assignee_id' => $userId], reason: $reason,
            source: self::SOURCE, branchIds: [$ticket->branch_id], label: $ticket->number);
        $ticket->assignee_id = $userId;
        $ticket->save();
        if ($userId !== null) {
            $this->notices->staff(User::query()->find($userId), $actor, TicketNotices::ASSIGNED, $ticket, "ticket:{$ticket->id}:assigned:{$userId}:".$ticket->lock_version);
        }
    }

    protected static function eligible(Ticket $ticket, ?int $userId): bool
    {
        return TicketAccess::isEligible($userId, $ticket);
    }
}
