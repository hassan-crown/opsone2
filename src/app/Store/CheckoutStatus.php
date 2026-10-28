<?php

namespace App\Store;

/**
 * A store checkout attempt, following its ready order (M-05): pending while the payment is open, paid with the
 * confirmed purchase, expired / abandoned when released without money, needs_attention when a person must decide.
 */
enum CheckoutStatus: string
{
    case PendingPayment = 'pending_payment';
    case Paid = 'paid';
    case Expired = 'expired';
    case Abandoned = 'abandoned';
    case NeedsAttention = 'needs_attention';

    public function isOpen(): bool
    {
        return $this === self::PendingPayment;
    }
}
