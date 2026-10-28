<?php

use App\Http\Controllers\Orders\BranchTransferController;
use App\Http\Controllers\Orders\OrderController;
use App\Http\Controllers\Orders\OrderLineController;
use Illuminate\Support\Facades\Route;

/*
| Orders and branch transfer (P4, domain 02, COM-08..12). JSON endpoints for the order screens; every one authorizes
| on the record through the central Gate. Contract: docs/p4-orders-api.md.
*/

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
    Route::get('orders/options', [OrderController::class, 'options'])->name('orders.options');
    Route::get('orders/create', [OrderController::class, 'create'])->name('orders.create');
    Route::get('orders/customers', [OrderController::class, 'customers'])->name('orders.customers');
    Route::get('orders/catalog', [OrderController::class, 'catalog'])->name('orders.catalog');
    Route::post('orders', [OrderController::class, 'store'])->middleware('throttle:orders-create')->name('orders.store');
    Route::get('orders/{order}', [OrderController::class, 'show'])->whereNumber('order')->name('orders.show');
    Route::put('orders/{order}', [OrderController::class, 'update'])->whereNumber('order')->name('orders.update');

    Route::post('orders/{order}/lines', [OrderLineController::class, 'store'])->whereNumber('order')->middleware('throttle:records-create')->name('orders.lines.store');
    Route::delete('orders/{order}/lines/{line}', [OrderLineController::class, 'destroy'])->whereNumber(['order', 'line'])->scopeBindings()->name('orders.lines.destroy');

    Route::get('orders/{order}/branch-transfer', [BranchTransferController::class, 'previewOrder'])->whereNumber('order')->name('orders.branch-transfer.preview');
    Route::post('orders/{order}/branch-transfer', [BranchTransferController::class, 'applyOrder'])->whereNumber('order')->middleware('throttle:records-create')->name('orders.branch-transfer.apply');
    Route::get('customers/{customer}/branch-transfer', [BranchTransferController::class, 'previewCustomer'])->whereNumber('customer')->name('customers.branch-transfer.preview');
    Route::post('customers/{customer}/branch-transfer', [BranchTransferController::class, 'applyCustomer'])->whereNumber('customer')->middleware('throttle:records-create')->name('customers.branch-transfer.apply');
});
