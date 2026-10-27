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
use InvalidArgumentException;

/**
 * M-02-T01: save or defer a wizard step (P-02). The step's inputs are saved by their own actions; this records
 * whether the step is `done` or `skipped`, and a skip never completes it.
 */
final class T01SaveSetupStep
{
    public function __construct(
        private readonly Authorizer $access,
        private readonly TransitionRecorder $transitions,
    ) {}

    public function handle(User $actor, string $step, string $state): Facility
    {
        if (! in_array($step, SetupSteps::ALL, true) || ! in_array($state, ['done', 'skipped'], true)) {
            throw new InvalidArgumentException('Unknown setup step or state.');
        }

        $facility = Facility::currentOrFail();
        $this->access->authorize($actor, Permission::P02, $facility);

        return DB::transaction(function () use ($actor, $step, $state, $facility) {
            $locked = Facility::query()->lockForUpdate()->findOrFail($facility->id);

            if ($locked->setup_status === SetupStatus::Completed) {
                throw new InvalidTransition(StateMachine::M02, 'T01', 'completed');
            }

            $steps = $locked->setup_steps ?? [];
            if (($steps[$step] ?? null) === $state) {
                return $locked;
            }
            $steps[$step] = $state;
            $locked->forceFill(['setup_steps' => $steps])->save();

            $this->transitions->record(
                StateMachine::M02, 'T01', $locked, SetupStatus::Incomplete, SetupStatus::Incomplete, $actor,
                TransitionRecorder::SOURCE_WEB, null, ['step' => $step, 'state' => $state],
            );

            return $locked;
        });
    }
}
