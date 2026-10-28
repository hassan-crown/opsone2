<?php

namespace App\Actions\StateMachines\M15Return;

use App\Access\Access;
use App\Access\Exceptions\InvalidTransition;
use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Enums\ReturnStatus;
use App\Enums\StateMachine;
use App\Models\Returns\OrderReturn;
use App\Models\Returns\OrderReturnLine;
use App\Models\User;
use App\Returns\Contracts\SettlementFollowUp;
use App\Returns\ReturnAccess;
use App\Returns\ReturnLock;
use App\Returns\ReturnProgress;
use App\Returns\ReturnWrite;

/**
 * M-15-T03 withdraw (P-102; or the requester with P-42 / P-53 before the decision): before any receipt the return is
 * cancelled and holds nothing; after a partial receipt only the accepted quantity not yet received is released — what
 * was received or inspected and every movement stay (`nothing_to_release` when nothing is still expected). The
 * expected settlement is capped to the value still held.
 */
final class T03WithdrawReturn
{
    public function __construct(
        private readonly ReturnWrite $write,
        private readonly TransitionRecorder $transitions,
        private readonly SettlementFollowUp $followUp,
    ) {}

    public function handle(User $actor, OrderReturn $return, string $reason, ?int $lockVersion, ?string $idempotencyKey, string $source = TransitionRecorder::SOURCE_WEB): OrderReturn
    {
        $this->write->run('withdraw', $actor, $idempotencyKey, ['return' => $return->id, 'reason' => $reason, 'lock_version' => $lockVersion], function () use ($actor, $return, $reason, $lockVersion, $source) {
            $locked = ReturnLock::lock($return, $lockVersion, 'T03');
            $from = $locked->status;
            $ownRequest = $from === ReturnStatus::Requested && $locked->requested_by === $actor->id && ReturnAccess::canRequest($actor, $locked->order);
            if (! $ownRequest) {
                Access::authorize($actor, Permission::P102, $locked);
            }
            $reason = T00RequestReturn::reason($reason, 'reason');

            $released = 0;
            foreach (OrderReturnLine::query()->where('order_return_id', $locked->id)->lockForUpdate()->get() as $line) {
                $free = $from === ReturnStatus::Requested ? 0 : $line->outstandingMilli();
                $released += $free;
                if ($free > 0) {
                    $line->forceFill(['accepted_milli' => $line->received_milli])->save();
                }
            }
            if ($from !== ReturnStatus::Requested && $released === 0) {
                throw new InvalidTransition(StateMachine::M15, 'T03', 'nothing_to_release');
            }

            $to = $from === ReturnStatus::Requested ? ReturnStatus::Cancelled : ReturnProgress::derive($locked);
            $value = $to === ReturnStatus::Cancelled ? $locked->value_minor : ReturnProgress::value($locked);
            $fill = ['cancel_reason' => $reason, 'cancelled_by' => $actor->id, 'cancelled_at' => now()];
            if ($to !== ReturnStatus::Cancelled && $value !== null) {
                $fill += ['value_minor' => $value, 'settlement_minor' => min((int) $locked->settlement_minor, $value)];
            }
            $locked->forceFill($fill)->save();
            ReturnLock::bump($locked, $to);

            $this->transitions->record(StateMachine::M15, 'T03', $locked, $from, $to, $actor, $source, $reason, ['number' => $locked->number, 'released_milli' => $released]);
            Audit::record(AuditAction::ReturnWithdrawn, $locked, $actor, after: ['number' => $locked->number, 'status' => $to->value, 'released_milli' => $released], reason: $reason, source: $source, branchIds: [$locked->order->branch_id], label: $locked->number);
            if ($to === ReturnStatus::Cancelled) {
                $this->followUp->close($locked->order, 'order_return', $locked->id, $actor);
            }

            return ['return_id' => $locked->id];
        });

        return $return->refresh();
    }
}
