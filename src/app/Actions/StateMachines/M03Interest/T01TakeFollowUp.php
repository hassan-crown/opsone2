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
 * M-03-T01: take over following up a new interest (P-09). The actor becomes its owner/assignee.
 */
final class T01TakeFollowUp
{
    public function __construct(private readonly Authorizer $access, private readonly TransitionRecorder $transitions) {}

    public function handle(User $actor, Interest $interest, string $source = TransitionRecorder::SOURCE_WEB): Interest
    {
        $this->access->authorize($actor, Permission::P09, $interest);

        return DB::transaction(function () use ($actor, $interest, $source) {
            $locked = Interest::query()->lockForUpdate()->findOrFail($interest->id);
            if ($locked->status !== InterestStatus::New) {
                throw new InvalidTransition(StateMachine::M03, 'T01', 'not_new');
            }

            $locked->forceFill(['status' => InterestStatus::Following, 'assignee_id' => $actor->id])->save();
            $this->transitions->record(StateMachine::M03, 'T01', $locked, InterestStatus::New, InterestStatus::Following, $actor, $source);
            Audit::record(
                AuditAction::InterestFollowed, $locked, $actor,
                before: ['status' => InterestStatus::New], after: ['status' => InterestStatus::Following, 'assignee_id' => $actor->id], source: $source,
            );

            return $locked;
        });
    }
}
