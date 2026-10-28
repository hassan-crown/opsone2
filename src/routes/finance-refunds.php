<?php

use App\Http\Controllers\Settlement\CorrectionController;
use App\Http\Controllers\Settlement\DisputeController;
use App\Http\Controllers\Settlement\ExternalDocumentController;
use App\Http\Controllers\Settlement\RefundController;
use Illuminate\Support\Facades\Route;

/*
| Refunds, corrections, financial disputes and external accounting documents (P6c, domain 05: FIN-08..10, FIN-13;
| M-12, M-11-T07, M-39, M-40). Every write authorizes on the record through the central Gate and carries an
| idempotency key. Contract: docs/p6-finance-api.md («6c»).
*/

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('finance/invoices', [ExternalDocumentController::class, 'index'])->name('finance.invoices');

    Route::middleware('throttle:records-create')->group(function () {
        Route::post('orders/{order}/finance/refunds', [RefundController::class, 'store'])->whereNumber('order')->name('orders.finance.refunds.store');
        Route::post('finance/refunds/{refund}/start', [RefundController::class, 'start'])->whereNumber('refund')->name('finance.refunds.start');
        Route::post('finance/refunds/{refund}/confirm', [RefundController::class, 'confirm'])->whereNumber('refund')->name('finance.refunds.confirm');
        Route::post('finance/refunds/{refund}/fail', [RefundController::class, 'fail'])->whereNumber('refund')->name('finance.refunds.fail');
        Route::post('finance/refunds/{refund}/retry', [RefundController::class, 'retry'])->whereNumber('refund')->name('finance.refunds.retry');
        Route::post('finance/refunds/{refund}/cancel', [RefundController::class, 'cancel'])->whereNumber('refund')->name('finance.refunds.cancel');

        Route::post('finance/payments/{payment}/correction', [CorrectionController::class, 'store'])->whereNumber('payment')->name('finance.payments.correction');

        Route::post('orders/{order}/finance/disputes', [DisputeController::class, 'store'])->whereNumber('order')->name('orders.finance.disputes.store');
        Route::post('finance/disputes/{dispute}/await', [DisputeController::class, 'await'])->whereNumber('dispute')->name('finance.disputes.await');
        Route::post('finance/disputes/{dispute}/resume', [DisputeController::class, 'resume'])->whereNumber('dispute')->name('finance.disputes.resume');
        Route::post('finance/disputes/{dispute}/resolve', [DisputeController::class, 'resolve'])->whereNumber('dispute')->name('finance.disputes.resolve');

        Route::post('orders/{order}/finance/documents', [ExternalDocumentController::class, 'store'])->whereNumber('order')->name('orders.finance.documents.store');
        Route::post('orders/{order}/finance/documents/link', [ExternalDocumentController::class, 'link'])->whereNumber('order')->name('orders.finance.documents.link');
        Route::post('finance/documents/{document}/attach', [ExternalDocumentController::class, 'attach'])->whereNumber('document')->name('finance.documents.attach');
        Route::post('finance/documents/{document}/accept', [ExternalDocumentController::class, 'accept'])->whereNumber('document')->name('finance.documents.accept');
        Route::post('finance/documents/{document}/correction', [ExternalDocumentController::class, 'correction'])->whereNumber('document')->name('finance.documents.correction');
    });
});
