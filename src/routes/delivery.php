<?php

use App\Http\Controllers\Delivery\DeliveryController;
use Illuminate\Support\Facades\Route;

/*
| Deliveries (P7 delivery, domain 06: FUL-09/FUL-10; M-14). Every write authorizes P-52 on the order through the central
| Gate in its Action and carries an idempotency key and the lock_version the screen read. Contract:
| docs/p7-inventory-api.md «Delivery». Screens: DelList / DelNew / DelRecord (+ the delivery side of Ready*).
*/

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('deliveries', [DeliveryController::class, 'index'])->name('deliveries.index');
    Route::get('deliveries/{note}', [DeliveryController::class, 'show'])->whereNumber('note')->name('deliveries.show');
    Route::get('orders/{order}/deliveries/create', [DeliveryController::class, 'create'])->whereNumber('order')->name('deliveries.create');

    Route::middleware('throttle:records-create')->group(function () {
        Route::post('orders/{order}/deliveries', [DeliveryController::class, 'store'])->whereNumber('order')->name('deliveries.store');
        Route::post('deliveries/{note}/confirm', [DeliveryController::class, 'confirm'])->whereNumber('note')->name('deliveries.confirm');
        Route::post('deliveries/{note}/cancel', [DeliveryController::class, 'cancel'])->whereNumber('note')->name('deliveries.cancel');
    });
});
