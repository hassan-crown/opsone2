<?php

namespace App\Enums;

/**
 * Kinds of money journals (ADR-0014). Every kind except a collection, a refund (and their reversals) moves money
 * between places of the same payment and sums to zero.
 */
enum JournalKind: string
{
    case Collection = 'collection';
    case Allocation = 'allocation';
    case Deallocation = 'deallocation';
    case Transfer = 'transfer';
    case Reversal = 'reversal';
    // P6c: money that really left to the customer (an executed refund, M-12), always from the order's credit.
    case Refund = 'refund';
}
