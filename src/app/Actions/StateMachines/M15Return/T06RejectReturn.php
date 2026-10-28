<?php

namespace App\Actions\StateMachines\M15Return;

use App\Access\Access;
use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Enums\ReturnStatus;
use App\Enums\StateMachine;
use App\Models\Returns\OrderReturn;
use App\Models\User;
use App\Returns\ReturnLock;
use App\Returns\ReturnWrite;

/**
 * M-15-T06 reject (P-102): a clear reason for the customer; no stock, money or commission effect and nothing is held.
 */
final class T06RejectReturn
{
    public function __construct(private readonly ReturnWrite $write, private readonly TransitionRecorder $transitions) {}

    public function handle(User $actor, OrderReturn $return, string $reason, ?int $lockVersion, ?string $idempotencyKey, string $source = TransitionRecorder::SOURCE_WEB): OrderReturn
    {
        $this->write->run('reject', $actor, $idempotencyKey, ['return' => $return->id, 'reason' => $reason, 'lock_version' => $lockVersion], function () use ($actor, $return, $reason, $lockVersion, $source) {
            $locked = ReturnLock::lock($return, $lockVersion, 'T06');
            Access::authorize($actor, Permission::P102, $locked);
            $reason = T00RequestReturn::reason($reason, 'reason');
            $locked->forceFill(['decision_reason' => $reason, 'decided_by' => $actor->id, 'decided_at' => now()])->save();
            ReturnLock::bump($locked, ReturnStatus::Rejected);

            $this->transitions->record(StateMachine::M15, 'T06', $locked, ReturnStatus::Requested, ReturnStatus::Rejected, $actor, $source, $reason, ['number' => $locked->number]);
            Audit::record(AuditAction::ReturnRejected, $locked, $actor, after: ['number' => $locked->number], reason: $reason, source: $source, branchIds: [$locked->order->branch_id], label: $locked->number);

            return ['return_id' => $locked->id];
        });

        return $return->refresh();
    }
}
