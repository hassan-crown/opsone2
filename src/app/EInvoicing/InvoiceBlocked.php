<?php

namespace App\EInvoicing;

use RuntimeException;

/**
 * An invoice that cannot be issued (yet): e-invoicing off, seller profile incomplete, no priced content, buyer data
 * missing for a standard invoice, nothing left to credit. `reason` is a translation key suffix (`einvoicing.blocked.*`).
 */
final class InvoiceBlocked extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct("E-invoice blocked: {$reason}.");
    }
}
