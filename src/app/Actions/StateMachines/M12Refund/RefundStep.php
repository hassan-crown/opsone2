<?php

namespace App\Actions\StateMachines\M12Refund;

use App\Access\Access;
use App\Access\Exceptions\InvalidTransition;
use App\Access\Permission;
use App\Enums\RefundStatus;
use App\Enums\StateMachine;
use App\Finance\FinanceLock;
use App\Models\Finance\OrderFinance;
use App\Models\Finance\Refund;
use App\Models\Order;
use App\Models\User;
use App\Support\StaleRecord;

/**
 * Shared steps of an M-12 transition on an existing refund: lock the order (and its finance head) first, authorize the
 * transition's own permission on the order (none for the provider service), lock the refund, check its version and
 * that the transition leaves from its state.
 */
final class RefundStep
{
    /**
     * @return array{0: Order, 1: Refund, 2: OrderFinance}
     */
    public static function lock(?User $actor, Refund $refund, ?Permission $permission, ?int $lockVersion, string $code): array
    {
        $order = FinanceLock::order($refund->order_id, StateMachine::M12, $code);
        if ($actor !== null && $permission !== null) {
            Access::authorize($actor, $permission, $order);
        }
        $head = FinanceLock::head($order);
        $fresh = Refund::query()->lockForUpdate()->findOrFail($refund->id);
        if ($lockVersion !== null && $fresh->lock_version !== $lockVersion) {
            throw new StaleRecord($fresh->lock_version);
        }
        if (! in_array($fresh->status, RefundStatus::transitions()[$code]['from'], true)) {
            throw new InvalidTransition(StateMachine::M12, $code, 'not_from_'.$fresh->status->value);
        }
        $fresh->setRelation('order', $order);

        return [$order, $fresh, $head];
    }

    /**
     * Moves the refund to the transition's target state and bumps its version (the caller saves other fields first).
     *
     * @param  array<string, mixed>  $fields
     */
    public static function move(Refund $refund, string $code, array $fields = []): RefundStatus
    {
        $from = $refund->status;
        $refund->forceFill([...$fields, 'status' => RefundStatus::transitions()[$code]['to'], 'lock_version' => $refund->lock_version + 1])->save();

        return $from;
    }
}
