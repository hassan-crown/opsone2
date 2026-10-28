<?php

namespace App\Returns;

use App\Access\Exceptions\InvalidTransition;
use App\Enums\ReturnStatus;
use App\Enums\StateMachine;
use App\Inventory\OrderStock;
use App\Models\Returns\OrderReturn;
use App\Support\StaleRecord;

/**
 * Every return write locks the order row first (the row deliveries, cancellations and other returns of the order
 * queue on), then the return row; the screen's `lock_version` is checked after both locks (409 stale_version) and the
 * transition's allowed source states after that (409 `not_from_{status}`); `null` leaves the state to the caller.
 */
final class ReturnLock
{
    public static function lock(OrderReturn $return, ?int $lockVersion, ?string $transition): OrderReturn
    {
        $order = OrderStock::lockOrder($return->order_id);
        $locked = OrderReturn::query()->lockForUpdate()->findOrFail($return->id);
        $locked->setRelation('order', $order);
        if ($lockVersion !== null && $locked->lock_version !== $lockVersion) {
            throw new StaleRecord($locked->lock_version);
        }
        if ($transition !== null && ! in_array($locked->status, ReturnStatus::transitions()[$transition]['from'], true)) {
            throw new InvalidTransition(StateMachine::M15, $transition, 'not_from_'.$locked->status->value);
        }

        return $locked;
    }

    public static function bump(OrderReturn $return, ReturnStatus $to): void
    {
        $return->forceFill(['status' => $to, 'lock_version' => $return->lock_version + 1])->save();
    }
}
