<?php

use App\Http\Controllers\Returns\ReturnController;
use Illuminate\Support\Facades\Route;

/*
| Ready-goods returns (P7 returns, domain 06: FUL-11, INT-02/03/05; M-15). Every write authorizes its own grant on the
| return through the central Gate in its Action (P-53/P-42 request, P-102 decide, P-53 receive/inspect, P-49 with P-53
| correct, P-42 refund) and carries an idempotency key and the return's lock_version. Contract: docs/p7-inventory-api.md
| «Returns». Screens: ReturnList / ReturnNew / ReturnPage (decision, receipt, inspection, refund).
*/

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('returns', [ReturnController::class, 'index'])->name('returns.index');
    Route::get('returns/{orderReturn}', [ReturnController::class, 'show'])->whereNumber('orderReturn')->name('returns.show');
    Route::get('orders/{order}/returns/create', [ReturnController::class, 'create'])->whereNumber('order')->name('returns.create');

    Route::middleware('throttle:records-create')->group(function () {
        Route::post('orders/{order}/returns', [ReturnController::class, 'store'])->whereNumber('order')->name('returns.store');
        Route::post('returns/{orderReturn}/accept', [ReturnController::class, 'accept'])->whereNumber('orderReturn')->name('returns.accept');
        Route::post('returns/{orderReturn}/reject', [ReturnController::class, 'reject'])->whereNumber('orderReturn')->name('returns.reject');
        Route::post('returns/{orderReturn}/withdraw', [ReturnController::class, 'withdraw'])->whereNumber('orderReturn')->name('returns.withdraw');
        Route::post('returns/{orderReturn}/receive', [ReturnController::class, 'receive'])->whereNumber('orderReturn')->name('returns.receive');
        Route::post('returns/{orderReturn}/inspect', [ReturnController::class, 'inspect'])->whereNumber('orderReturn')->name('returns.inspect');
        Route::post('returns/{orderReturn}/correct', [ReturnController::class, 'correct'])->whereNumber('orderReturn')->name('returns.correct');
        Route::post('returns/{orderReturn}/refund', [ReturnController::class, 'refund'])->whereNumber('orderReturn')->name('returns.refund');
        Route::put('returns/settings', [ReturnController::class, 'settings'])->name('returns.settings');
    });
});
