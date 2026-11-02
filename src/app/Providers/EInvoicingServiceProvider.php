<?php

namespace App\Providers;

use App\EInvoicing\EInvoiceListeners;
use App\Finance\PaymentConfirmed;
use App\Finance\Settlement\RefundExecuted;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * ZATCA e-invoicing (ADR-0033): automatic invoices on confirmed payments and credit notes on executed refunds.
 */
class EInvoicingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(PaymentConfirmed::class, [EInvoiceListeners::class, 'onPayment']);
        Event::listen(RefundExecuted::class, [EInvoiceListeners::class, 'onRefund']);
    }
}
