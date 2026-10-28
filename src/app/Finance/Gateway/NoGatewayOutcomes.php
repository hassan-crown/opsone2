<?php

namespace App\Finance\Gateway;

use App\Enums\PaymentStatus;
use App\Models\Finance\Payment;
use App\Models\Order;

/**
 * Default: a verified result changes only the payment and its money.
 */
final class NoGatewayOutcomes implements GatewayOutcomes
{
    public function applied(Payment $payment, Order $order, PaymentStatus $from, VerifiedGatewayEvent $event, bool $late): void {}
}
