<?php

namespace App\Agreements;

/**
 * The customer acting from their own account (P-67). The customer portal (P8) authenticates the customer (OTP) and
 * checks their access to the order before building this; staff access to a customer file never produces one.
 */
final readonly class CustomerActor
{
    public function __construct(public int $customerId, public string $mobile) {}
}
