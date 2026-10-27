<?php

namespace App\Orders;

use App\Access\Exceptions\InvalidTransition;
use App\Enums\CustomOrderStatus;
use App\Enums\OrderKind;
use App\Enums\ReadyOrderStatus;
use App\Models\Order;

/**
 * Guard for M-04/M-05 transitions: a code the machine does not define, or one not allowed from the current state,
 * is refused before any write. Each transition Action (P5+) calls check() on the locked row first.
 */
final class OrderTransitions
{
    /**
     * @return CustomOrderStatus|ReadyOrderStatus|null the defined target (null when the action chooses among targets)
     */
    public static function check(Order $order, string $code): CustomOrderStatus|ReadyOrderStatus|null
    {
        $table = $order->kind === OrderKind::Custom ? CustomOrderStatus::transitions() : ReadyOrderStatus::transitions();
        $definition = $table[$code] ?? null;

        if ($definition === null) {
            throw new InvalidTransition($order->kind->machine(), $code, 'undefined');
        }
        if (! in_array($order->state(), $definition['from'], true)) {
            throw new InvalidTransition($order->kind->machine(), $code, 'not_from_'.$order->status);
        }

        return $definition['to'];
    }
}
