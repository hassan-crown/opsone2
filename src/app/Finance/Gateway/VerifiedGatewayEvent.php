<?php

namespace App\Finance\Gateway;

use Carbon\CarbonImmutable;

/**
 * The outcome of a provider notification after the gateway verified its signature (`PaymentGateway::verify()` is its
 * only producer) — or of the gateway's own authenticated re-fetch of the payment (`PaymentGateway::fetch()`).
 * `outcome`: succeeded | failed | pending | unresolved | cancelled. `occurredAt` is the provider's time of the
 * payment when it gives one (SAL-03: a purchase made inside a link's validity keeps its sale even if the
 * notification is late).
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
        public ?CarbonImmutable $occurredAt = null,
    ) {}
}
