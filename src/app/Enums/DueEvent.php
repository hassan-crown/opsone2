<?php

namespace App\Enums;

/**
 * When a due falls due (FIN-02: a date or a clear event, never a free formula): at the agreement's approval, when the
 * flow stage holding the payment check with the due's reference starts, at the order's first confirmed delivery (P7), or on a date.
 */
enum DueEvent: string
{
    case Agreement = 'agreement';
    case PaymentStage = 'payment_stage';
    case Delivery = 'delivery';
    case Date = 'date';
}
