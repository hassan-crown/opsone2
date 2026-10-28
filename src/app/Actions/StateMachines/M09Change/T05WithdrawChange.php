<?php

namespace App\Actions\StateMachines\M09Change;

use App\Access\Access;
use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Agreements\AgreementLock;
use App\Agreements\ChangeApplication;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Enums\ChangeStatus;
use App\Enums\StateMachine;
use App\Models\Agreements\AgreementChange;
use App\Models\User;
use App\Support\LockingTransaction;
use App\Workflow\Transition;
use App\Workflow\WorkGuard;

/**
 * M-09-T05 (P-36): withdraw the proposal only. The agreement in force, any manufacturing and any collection stay as
 * they are; the held work is released.
 */
final class T05WithdrawChange
{
    public function __construct(
        private readonly TransitionRecorder $transitions,
        private readonly ChangeApplication $application,
    ) {}

    public function handle(User $actor, AgreementChange $change, string $reason, ?int $lockVersion, string $source = TransitionRecorder::SOURCE_WEB): AgreementChange
    {
        $reason = WorkGuard::reason($reason);

        return LockingTransaction::run(function () use ($actor, $change, $reason, $lockVersion, $source) {
            [$order, $change] = AgreementLock::change($change, $lockVersion, 'T05');
            Access::authorize($actor, Permission::P36, $order);
            $from = $change->status;
            Transition::check(StateMachine::M09, ChangeStatus::transitions(), $from, 'T05');

            $change->forceFill(['status' => ChangeStatus::Cancelled, 'open_key' => null, 'cancelled_reason' => $reason, 'lock_version' => $change->lock_version + 1])->save();
            $this->transitions->record(StateMachine::M09, 'T05', $change, $from, ChangeStatus::Cancelled, $actor, $source, $reason, ['order_id' => $order->id]);
            Audit::record(AuditAction::ChangeWithdrawn, $order, $actor, after: ['change' => $change->number], reason: $reason, source: $source, label: $order->number);
            $this->application->release($order, $change, $actor);

            return $change;
        });
    }
}
