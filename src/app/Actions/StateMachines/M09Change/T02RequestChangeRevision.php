<?php

namespace App\Actions\StateMachines\M09Change;

use App\Access\Access;
use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Agreements\AgreementLock;
use App\Agreements\ChangeApplication;
use App\Enums\ChangeStatus;
use App\Enums\StateMachine;
use App\Models\Agreements\AgreementChange;
use App\Models\User;
use App\Support\LockingTransaction;
use App\Workflow\Transition;
use App\Workflow\WorkGuard;

/**
 * M-09-T02 by staff (P-36): the change needs a correction (for example the customer said so by phone). The earlier
 * agreement stays in force and the affected work stays held. The customer's own request comes through
 * CustomerDecisions.
 */
final class T02RequestChangeRevision
{
    public function __construct(private readonly ChangeApplication $application) {}

    public function handle(User $actor, AgreementChange $change, string $reason, ?int $lockVersion, string $source = TransitionRecorder::SOURCE_WEB): AgreementChange
    {
        $reason = WorkGuard::reason($reason);

        return LockingTransaction::run(function () use ($actor, $change, $reason, $lockVersion, $source) {
            [$order, $change] = AgreementLock::change($change, $lockVersion, 'T02');
            Access::authorize($actor, Permission::P36, $order);
            Transition::check(StateMachine::M09, ChangeStatus::transitions(), $change->status, 'T02');
            $this->application->requestRevision($order, $change, $reason, $actor, $source);

            return $change;
        });
    }
}
