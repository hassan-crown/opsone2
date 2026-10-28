<?php

namespace App\Actions\StateMachines\M39Dispute;

use App\Access\Access;
use App\Access\Exceptions\InvalidTransition;
use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Enums\DisputeStatus;
use App\Enums\StateMachine;
use App\Finance\FinanceLock;
use App\Models\Finance\FinancialDispute;
use App\Models\Order;
use App\Models\User;
use App\Support\StaleRecord;

/**
 * Shared steps of an M-39 transition (P-85): lock the order and its finance head, authorize, lock the dispute, check
 * its version and its state; then move it and record the transition.
 */
final class DisputeStep
{
    /**
     * @return array{0: Order, 1: FinancialDispute}
     */
    public static function lock(User $actor, FinancialDispute $dispute, ?int $lockVersion, string $code): array
    {
        $order = FinanceLock::order($dispute->order_id, StateMachine::M39, $code);
        Access::authorize($actor, Permission::P85, $order);
        FinanceLock::touch(FinanceLock::head($order));
        $fresh = FinancialDispute::query()->lockForUpdate()->findOrFail($dispute->id);
        if ($lockVersion !== null && $fresh->lock_version !== $lockVersion) {
            throw new StaleRecord($fresh->lock_version);
        }
        if (! in_array($fresh->status, DisputeStatus::transitions()[$code]['from'], true)) {
            throw new InvalidTransition(StateMachine::M39, $code, 'not_from_'.$fresh->status->value);
        }
        $fresh->setRelation('order', $order);

        return [$order, $fresh];
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    public static function move(TransitionRecorder $transitions, FinancialDispute $dispute, string $code, array $fields, User $actor, string $source, ?string $reason): void
    {
        $from = $dispute->status;
        $to = DisputeStatus::transitions()[$code]['to'];
        $dispute->forceFill([...$fields, 'status' => $to, 'lock_version' => $dispute->lock_version + 1])->save();
        $transitions->record(StateMachine::M39, $code, $dispute, $from, $to, $actor, $source, $reason);
    }
}
