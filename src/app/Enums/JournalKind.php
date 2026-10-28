<?php

namespace App\Enums;

/**
 * Kinds of money journals (ADR-0014). Every kind except a collection (and its reversal) moves money between places
 * of the same payment and sums to zero.
 */
enum JournalKind: string
{
    case Collection = 'collection';
    case Allocation = 'allocation';
    case Deallocation = 'deallocation';
    case Transfer = 'transfer';
    case Reversal = 'reversal';
}
