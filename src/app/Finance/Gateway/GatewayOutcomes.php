<?php

namespace App\Finance\Gateway;

use App\Enums\PaymentStatus;
use App\Models\Finance\Payment;
use App\Models\Order;

/**
 * What else follows a verified provider result, inside the same transaction and under the order's lock (the store
 * confirms or re-reserves its ready order, consumes the coupon and settles the basket link — ADR-0023). Called once
 * per applied change, and for a late success after a local failure or cancellation (`$late`); never for a replay.
 */
interface GatewayOutcomes
{
    public function applied(Payment $payment, Order $order, PaymentStatus $from, VerifiedGatewayEvent $event, bool $late): void;
}
