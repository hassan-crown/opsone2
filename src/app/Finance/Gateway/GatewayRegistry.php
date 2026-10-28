<?php

namespace App\Finance\Gateway;

/**
 * The facility's configured online payment gateways. Empty in v1 (FIN-07 is a P8 integration): the webhook route
 * answers 404 and no online payment can start.
 */
final class GatewayRegistry
{
    /** @var array<string, PaymentGateway> */
    private array $gateways = [];

    public function add(PaymentGateway $gateway): void
    {
        $this->gateways[$gateway->key()] = $gateway;
    }

    public function get(string $key): ?PaymentGateway
    {
        return $this->gateways[$key] ?? null;
    }
}
