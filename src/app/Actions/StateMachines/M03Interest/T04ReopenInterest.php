<?php

namespace App\Actions\StateMachines\M03Interest;

use App\Access\Authorizer;
use App\Access\Exceptions\InvalidTransition;
use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Enums\InterestStatus;
use App\Enums\StateMachine;
use App\Models\Interest;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * M-03-T04: resume following up a closed interest, with a reason and an owner (P-09).
 * A converted interest never reopens, so a previous conversion is never hidden.
 */
final class T04ReopenInterest
{
    public function __construct(private readonly Authorizer $access, private readonly TransitionRecorder $transitions) {}

    public function handle(User $actor, Interest $interest, string $reason, ?int $assigneeId = null, string $source = TransitionRecorder::SOURCE_WEB): Interest
    {
        $this->access->authorize($actor, Permission::P09, $interest);

        return DB::transaction(function () use ($actor, $interest, $reason, $assigneeId, $source) {
            $locked = Interest::query()->lockForUpdate()->findOrFail($interest->id);
            if ($locked->status !== InterestStatus::Closed) {
                throw new InvalidTransition(StateMachine::M03, 'T04', 'not_closed');
            }

            $assignee = $assigneeId ?? $actor->id;
            $locked->forceFill(['status' => InterestStatus::Following, 'assignee_id' => $assignee, 'close_reason' => null, 'close_note' => null, 'closed_at' => null])->save();
            $this->transitions->record(StateMachine::M03, 'T04', $locked, InterestStatus::Closed, InterestStatus::Following, $actor, $source, $reason);
            Audit::record(
                AuditAction::InterestReopened, $locked, $actor,
                before: ['status' => InterestStatus::Closed], after: ['status' => InterestStatus::Following, 'assignee_id' => $assignee], reason: $reason, source: $source,
            );

            return $locked;
        });
    }
}
