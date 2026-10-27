<?php

namespace App\Actions\StateMachines\M03Interest;

use App\Access\Authorizer;
use App\Access\Exceptions\InvalidTransition;
use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Enums\InterestCloseReason;
use App\Enums\InterestStatus;
use App\Enums\StateMachine;
use App\Models\Interest;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * M-03-T03: close an open interest with a reason (P-09). No pretend sale is created.
 */
final class T03CloseInterest
{
    public function __construct(private readonly Authorizer $access, private readonly TransitionRecorder $transitions) {}

    public function handle(User $actor, Interest $interest, InterestCloseReason $reason, ?string $note = null, string $source = TransitionRecorder::SOURCE_WEB): Interest
    {
        $this->access->authorize($actor, Permission::P09, $interest);

        return DB::transaction(function () use ($actor, $interest, $reason, $note, $source) {
            $locked = Interest::query()->lockForUpdate()->findOrFail($interest->id);
            if (! $locked->isOpen()) {
                throw new InvalidTransition(StateMachine::M03, 'T03', 'not_open');
            }

            $from = $locked->status;
            $locked->forceFill(['status' => InterestStatus::Closed, 'close_reason' => $reason, 'close_note' => $note, 'closed_at' => now()])->save();
            $this->transitions->record(StateMachine::M03, 'T03', $locked, $from, InterestStatus::Closed, $actor, $source, $note, ['reason' => $reason->value]);
            Audit::record(
                AuditAction::InterestClosed, $locked, $actor,
                before: ['status' => $from], after: ['status' => InterestStatus::Closed, 'close_reason' => $reason], reason: $note, source: $source,
            );

            return $locked;
        });
    }
}
