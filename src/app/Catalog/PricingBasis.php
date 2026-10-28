<?php

namespace App\Catalog;

/**
 * How a custom product's reference price is read (COM-04). The agreed price is set in the quote, not here.
 */
enum PricingBasis: string
{
    case LinearMeter = 'linear_meter';
    case SquareMeter = 'square_meter';
    case Piece = 'piece';
    case Estimate = 'estimate';
}
