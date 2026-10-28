<?php

use App\Http\Controllers\Agreements\SalesSettingsController;
use App\Http\Controllers\Commissions\CommissionController;
use App\Http\Controllers\Commissions\OrderSaleController;
use App\Http\Controllers\Commissions\PayoutController;
use Illuminate\Support\Facades\Route;

/*
| Sale ownership and commissions (P6d, ADR-0016: SAL-10..16, SAL-19, INT-06, M-33). Every write authorizes on the
| record through the central Gate (P-94..P-98). Contract: docs/p6-finance-api.md «6d».
*/

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('finance/commissions', [CommissionController::class, 'index'])->name('finance.commissions');
    Route::get('finance/commissions/mine', [CommissionController::class, 'mine'])->name('finance.commissions.mine');
    Route::get('finance/commissions/members/{member}', [CommissionController::class, 'member'])->whereNumber('member')->name('finance.commissions.member');
    Route::get('orders/{order}/sale', [OrderSaleController::class, 'show'])->whereNumber('order')->name('orders.sale');

    Route::middleware('throttle:records-create')->group(function () {
        Route::post('finance/commissions/members/{member}/payouts', [PayoutController::class, 'store'])->whereNumber('member')->name('finance.commissions.payouts.store');
        Route::post('finance/commissions/payouts/{payout}/approve', [PayoutController::class, 'approve'])->whereNumber('payout')->name('finance.commissions.payouts.approve');
        Route::post('finance/commissions/payouts/{payout}/record', [PayoutController::class, 'record'])->whereNumber('payout')->name('finance.commissions.payouts.record');
        Route::post('finance/commissions/payouts/{payout}/cancel', [PayoutController::class, 'cancel'])->whereNumber('payout')->name('finance.commissions.payouts.cancel');
        Route::post('finance/commissions/payouts/{payout}/correct', [PayoutController::class, 'correct'])->whereNumber('payout')->name('finance.commissions.payouts.correct');

        Route::post('orders/{order}/sale/corrections', [OrderSaleController::class, 'requestCorrection'])->whereNumber('order')->name('orders.sale.corrections.store');
        Route::post('sale-corrections/{correction}/approve', [OrderSaleController::class, 'approve'])->whereNumber('correction')->name('sale-corrections.approve');
        Route::post('sale-corrections/{correction}/reject', [OrderSaleController::class, 'reject'])->whereNumber('correction')->name('sale-corrections.reject');
        Route::post('sale-corrections/{correction}/cancel', [OrderSaleController::class, 'cancel'])->whereNumber('correction')->name('sale-corrections.cancel');
    });

    Route::put('settings/sales/commissions', [SalesSettingsController::class, 'updateCommissions'])->middleware('throttle:records-create')->name('settings.sales.commissions');
});
