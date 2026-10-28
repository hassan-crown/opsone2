<?php

use App\Http\Controllers\Finance\CollectionReportController;
use App\Http\Controllers\Finance\FinanceHomeController;
use App\Http\Controllers\Finance\GatewayWebhookController;
use App\Http\Controllers\Finance\OrderFinanceController;
use App\Http\Controllers\Finance\PaymentController;
use App\Http\Controllers\Finance\ProofController;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;

/*
| Order money, collections, receipts and allocation (P6b, domain 05: FIN-01..07, FIN-11, INT-04, INT-07; M-10,
| M-11). Every write authorizes on the record through the central Gate. Contract: docs/p6-finance-api.md.
*/

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('orders/{order}/finance', [OrderFinanceController::class, 'show'])->whereNumber('order')->name('orders.finance');

    Route::middleware('throttle:records-create')->group(function () {
        Route::post('orders/{order}/finance/payments', [OrderFinanceController::class, 'recordPayment'])->whereNumber('order')->name('orders.finance.payments.store');
        Route::put('orders/{order}/finance/schedule', [OrderFinanceController::class, 'updateSchedule'])->whereNumber('order')->name('orders.finance.schedule');
        Route::post('orders/{order}/finance/transfers', [OrderFinanceController::class, 'transferCredit'])->whereNumber('order')->name('orders.finance.transfers');

        Route::post('finance/payments/{payment}/allocation', [PaymentController::class, 'reallocate'])->whereNumber('payment')->name('finance.payments.allocation');
        Route::post('finance/proofs/{proof}/confirm', [ProofController::class, 'confirm'])->whereNumber('proof')->name('finance.proofs.confirm');
        Route::post('finance/proofs/{proof}/correction', [ProofController::class, 'requestCorrection'])->whereNumber('proof')->name('finance.proofs.correction');
        Route::post('finance/proofs/{proof}/cancel', [ProofController::class, 'cancel'])->whereNumber('proof')->name('finance.proofs.cancel');

        Route::post('finance/reminders/preview', [FinanceHomeController::class, 'previewReminders'])->name('finance.reminders.preview');
        Route::post('finance/reminders', [FinanceHomeController::class, 'sendReminders'])->name('finance.reminders.send');
    });

    Route::get('finance', [FinanceHomeController::class, 'index'])->name('finance.index');
    Route::get('reports/collection', [CollectionReportController::class, 'show'])->name('reports.collection');
});

// Online payment notifications (FIN-07, P8): no session, no CSRF — the gateway verifies the provider's signature.
Route::post('webhooks/payments/{gateway}', GatewayWebhookController::class)
    ->where('gateway', '[a-z0-9_]{1,30}')
    // The web group's forgery check is PreventRequestForgery (Laravel 13); both are lifted for the provider.
    ->withoutMiddleware([ValidateCsrfToken::class, PreventRequestForgery::class])
    ->middleware('throttle:60,1')
    ->name('webhooks.payments');
