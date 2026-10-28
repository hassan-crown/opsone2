<?php

namespace App\Finance\Gateway;

use App\Models\Finance\Payment;
use Illuminate\Http\Request;

/**
 * An online payment provider for the facility's own merchant account (FIN-07, P-69; OpsOne never holds merchant
 * money). Optional and not implemented in v1 (P8 chooses the provider). The browser return page never confirms
 * anything: a payment is confirmed only by `GatewayPayments::applyWebhook()`, which asks the gateway to verify the
 * provider's signed notification first — there is no other way to build a `VerifiedGatewayEvent` into money.
 */
interface PaymentGateway
{
    /** Stable key used in the webhook URL and in `payments.gateway`. */
    public function key(): string;

    /**
     * Starts a checkout for the pending payment; returns the provider page to send the customer to. The merchant
     * reference (`GatewayPayments::merchantReference()`) must be sent so the notification can name the payment.
     */
    public function checkoutUrl(Payment $payment, string $merchantReference, string $returnUrl): string;

    /**
     * Verifies the provider's notification (signature, timestamp, merchant account) and reads its outcome.
     *
     * @throws InvalidGatewaySignature when it cannot be trusted
     */
    public function verify(Request $request): VerifiedGatewayEvent;
}
