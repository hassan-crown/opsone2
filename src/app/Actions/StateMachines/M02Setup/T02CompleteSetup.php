<?php

namespace App\Actions\StateMachines\M02Setup;

use App\Access\Authorizer;
use App\Access\Exceptions\InvalidTransition;
use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Enums\SetupStatus;
use App\Enums\StateMachine;
use App\Models\Facility;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * M-02-T02: complete the setup (P-02). Required steps must be done; deferred optional steps stay available from
 * the permanent settings pages. The wizard is closed for good (T03 refuses to reopen it).
 */
final class T02CompleteSetup
{
    public function __construct(
        private readonly Authorizer $access,
        private readonly TransitionRecorder $transitions,
    ) {}

    public function handle(User $actor): Facility
    {
        $facility = Facility::currentOrFail();
        $this->access->authorize($actor, Permission::P02, $facility);

        return DB::transaction(function () use ($actor, $facility) {
            $locked = Facility::query()->lockForUpdate()->findOrFail($facility->id);

            if ($locked->setup_status === SetupStatus::Completed) {
                throw new InvalidTransition(StateMachine::M02, 'T02', 'completed');
            }

            $states = SetupSteps::states($locked);
            foreach (SetupSteps::REQUIRED as $step) {
                if ($states[$step] !== 'done') {
                    throw new InvalidTransition(StateMachine::M02, 'T02', 'required_step_pending');
                }
            }

            $locked->forceFill(['setup_status' => SetupStatus::Completed, 'setup_completed_at' => now()])->save();

            $this->transitions->record(
                StateMachine::M02, 'T02', $locked, SetupStatus::Incomplete, SetupStatus::Completed, $actor, TransitionRecorder::SOURCE_WEB,
            );

            return $locked;
        });
    }
}
