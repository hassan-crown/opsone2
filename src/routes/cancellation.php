<?php

use App\Http\Controllers\Cancellation\CancellationController;
use Illuminate\Support\Facades\Route;

/*
| Order cancellation and the settlement follow-up (P7 part E, domain 02/06: COM-14, WF-13, FUL-12, FIN-14, INT-08;
| M-34 with M-04-T04..T06 / M-05-T09). Writes authorize P-18 on the order in their Action (settlement items: the
| permission of the action they stand for) and carry an idempotency key, the version read and, when applying, the
| reviewed fingerprint. Contract: docs/p7-inventory-api.md «Cancellation». Screen: CancelReview.
*/

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('orders/{order}/cancellation', [CancellationController::class, 'show'])->whereNumber('order')->name('orders.cancellation');

    Route::middleware('throttle:records-create')->group(function () {
        // The review reads every source of the order (stock, purchases, money, agreement): bounded like the writes.
        Route::post('orders/{order}/cancellation/preview', [CancellationController::class, 'preview'])->whereNumber('order')->name('orders.cancellation.preview');
        Route::post('orders/{order}/cancellation/requests', [CancellationController::class, 'request'])->whereNumber('order')->name('orders.cancellation.request');
        Route::post('orders/{order}/cancellation', [CancellationController::class, 'store'])->whereNumber('order')->name('orders.cancellation.store');
        Route::post('cancellations/{cancellation}/accept', [CancellationController::class, 'accept'])->whereNumber('cancellation')->name('cancellations.accept');
        Route::post('cancellations/{cancellation}/reject', [CancellationController::class, 'reject'])->whereNumber('cancellation')->name('cancellations.reject');
        Route::post('settlement-items/{item}/resolve', [CancellationController::class, 'resolve'])->whereNumber('item')->name('settlement.items.resolve');
    });
});
