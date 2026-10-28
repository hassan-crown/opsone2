<?php

namespace App\Finance\Gateway;

/**
 * The outcome of a provider notification after the gateway verified its signature (`PaymentGateway::verify()` is its
 * only producer). `outcome`: succeeded | failed | pending | unresolved | cancelled.
 */
final readonly class VerifiedGatewayEvent
{
    public const OUTCOMES = ['succeeded', 'failed', 'pending', 'unresolved', 'cancelled'];

    public function __construct(
        public string $merchantReference,
        public string $transactionId,
        public string $outcome,
        public int $amountMinor,
        public string $currency,
    ) {}
}
