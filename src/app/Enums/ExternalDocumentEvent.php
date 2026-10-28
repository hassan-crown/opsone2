<?php

namespace App\Enums;

/**
 * The order event an external document covers: a payment, a refund, the delivery, or the order as a whole.
 */
enum ExternalDocumentEvent: string
{
    case Payment = 'payment';
    case Refund = 'refund';
    case Delivery = 'delivery';
    case Order = 'order';
}
