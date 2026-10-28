<?php

use App\Http\Controllers\Agreements\AgreementController;
use App\Http\Controllers\Agreements\ChangeController;
use App\Http\Controllers\Agreements\DiscountDecisionController;
use App\Http\Controllers\Agreements\DocumentController;
use App\Http\Controllers\Agreements\SalesSettingsController;
use Illuminate\Support\Facades\Route;

/*
| Agreement and documents inside the order (P6a, domain 04: AGR-01..09, M-08, M-09, M-26) and the money settings
| (SettingsSales). Every write authorizes on the record through the central Gate. Contract: docs/p6-finance-api.md.
*/

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('orders/{order}/agreement', [AgreementController::class, 'show'])->whereNumber('order')->name('orders.agreement');
    Route::post('orders/{order}/agreement/quotes', [AgreementController::class, 'storeQuote'])->whereNumber('order')->middleware('throttle:records-create')->name('orders.agreement.quotes.store');
    Route::post('orders/{order}/agreement/designs', [AgreementController::class, 'storeDesign'])->whereNumber('order')->middleware('throttle:records-create')->name('orders.agreement.designs.store');
    Route::post('orders/{order}/agreement/contracts', [AgreementController::class, 'storeContract'])->whereNumber('order')->middleware('throttle:records-create')->name('orders.agreement.contracts.store');
    Route::post('orders/{order}/agreement/changes', [AgreementController::class, 'storeChange'])->whereNumber('order')->middleware('throttle:records-create')->name('orders.agreement.changes.store');

    Route::prefix('agreement')->name('agreement.')->middleware('throttle:records-create')->group(function () {
        Route::put('documents/{document}', [DocumentController::class, 'update'])->whereNumber('document')->name('documents.update');
        Route::post('documents/{document}/send', [DocumentController::class, 'send'])->whereNumber('document')->name('documents.send');
        Route::post('documents/{document}/revise', [DocumentController::class, 'revise'])->whereNumber('document')->name('documents.revise');
        Route::post('documents/{document}/external-approval', [DocumentController::class, 'externalApproval'])->whereNumber('document')->name('documents.external-approval');
        Route::post('documents/{document}/extend', [DocumentController::class, 'extend'])->whereNumber('document')->name('documents.extend');
        Route::post('documents/{document}/cancel', [DocumentController::class, 'cancel'])->whereNumber('document')->name('documents.cancel');
        Route::post('documents/{document}/discount-requests', [DocumentController::class, 'requestDiscount'])->whereNumber('document')->name('documents.discount-requests');

        Route::put('changes/{change}', [ChangeController::class, 'update'])->whereNumber('change')->name('changes.update');
        Route::post('changes/{change}/present', [ChangeController::class, 'present'])->whereNumber('change')->name('changes.present');
        Route::post('changes/{change}/revision', [ChangeController::class, 'requestRevision'])->whereNumber('change')->name('changes.revision');
        Route::post('changes/{change}/apply', [ChangeController::class, 'apply'])->whereNumber('change')->name('changes.apply');
        Route::post('changes/{change}/withdraw', [ChangeController::class, 'withdraw'])->whereNumber('change')->name('changes.withdraw');
        Route::post('changes/{change}/discount-requests', [ChangeController::class, 'requestDiscount'])->whereNumber('change')->name('changes.discount-requests');

        Route::post('decisions/{decision}/approve', [DiscountDecisionController::class, 'approve'])->whereNumber('decision')->name('decisions.approve');
        Route::post('decisions/{decision}/reject', [DiscountDecisionController::class, 'reject'])->whereNumber('decision')->name('decisions.reject');
        Route::post('decisions/{decision}/cancel', [DiscountDecisionController::class, 'cancel'])->whereNumber('decision')->name('decisions.cancel');
    });
    Route::get('agreement/decisions/{decision}', [DiscountDecisionController::class, 'show'])->whereNumber('decision')->name('agreement.decisions.show');

    Route::get('settings/sales', [SalesSettingsController::class, 'edit'])->name('settings.sales');
    Route::put('settings/sales', [SalesSettingsController::class, 'update'])->middleware('throttle:records-create')->name('settings.sales.update');
    Route::put('settings/sales/limits', [SalesSettingsController::class, 'updateLimits'])->middleware('throttle:records-create')->name('settings.sales.limits');
    Route::put('settings/sales/cash-proof', [SalesSettingsController::class, 'updateCashProof'])->middleware('throttle:records-create')->name('settings.sales.cash-proof');
});
