<?php

use App\Http\Controllers\EInvoicing\EInvoicingController;
use Illuminate\Support\Facades\Route;

/*
| ZATCA e-invoicing (ADR-0033): the owner's settings, onboarding, submission list with retry, manual notes; document
| PDF/XML for the owner and P-37 on the order. Every route authorizes server-side (ZatcaAccess).
*/

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('settings/einvoicing', [EInvoicingController::class, 'edit'])->name('einvoicing.edit');
    Route::put('settings/einvoicing', [EInvoicingController::class, 'update'])->name('einvoicing.update');
    Route::post('settings/einvoicing/onboard', [EInvoicingController::class, 'onboard'])->middleware('throttle:5,1')->name('einvoicing.onboard');
    Route::post('settings/einvoicing/onboard/resume', [EInvoicingController::class, 'resume'])->middleware('throttle:5,1')->name('einvoicing.onboard.resume');
    Route::post('einvoices/{invoice}/retry', [EInvoicingController::class, 'retry'])->whereNumber('invoice')->name('einvoicing.retry');
    Route::post('einvoices/{invoice}/notes', [EInvoicingController::class, 'note'])->whereNumber('invoice')->middleware('throttle:records-create')->name('einvoicing.notes');
    Route::post('orders/{order}/einvoice', [EInvoicingController::class, 'issue'])->whereNumber('order')->middleware('throttle:records-create')->name('einvoicing.issue');
    Route::get('einvoices/{invoice}/pdf', [EInvoicingController::class, 'pdf'])->whereNumber('invoice')->name('einvoicing.pdf');
    Route::get('einvoices/{invoice}/xml', [EInvoicingController::class, 'xml'])->whereNumber('invoice')->name('einvoicing.xml');
});
