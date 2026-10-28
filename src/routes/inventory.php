<?php

use App\Http\Controllers\Inventory\OrderStockController;
use App\Http\Controllers\Inventory\StockController;
use App\Http\Controllers\Inventory\StockCountController;
use App\Http\Controllers\Inventory\StockLocationController;
use App\Http\Controllers\Inventory\StockTransferController;
use Illuminate\Support\Facades\Route;

/*
| Inventory (P7a, ADR-0017, domain 06: FUL-01..04, M-05-T01/T09, M-24, M-25). JSON writes; every one authorizes on the
| record through the central Gate (P-15, P-18, P-48..P-52). Contract: docs/p7-inventory-api.md.
*/

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('stock', [StockController::class, 'index'])->name('stock.index');
    Route::get('stock/movements', [StockController::class, 'movements'])->name('stock.movements');
    Route::get('stock/ready', [StockController::class, 'ready'])->name('stock.ready');
    Route::get('stock/items', [StockController::class, 'items'])->name('stock.items');
    Route::get('stock/locations', [StockLocationController::class, 'index'])->name('stock.locations.index');
    Route::get('stock/transfers', [StockTransferController::class, 'index'])->name('stock.transfers.index');
    Route::get('stock/counts', [StockCountController::class, 'index'])->name('stock.counts.index');
    Route::get('stock/counts/{count}', [StockCountController::class, 'show'])->whereNumber('count')->name('stock.counts.show');
    Route::get('orders/{order}/materials', [OrderStockController::class, 'materials'])->whereNumber('order')->name('orders.materials');

    Route::middleware('throttle:records-create')->group(function () {
        Route::post('stock/adjustments', [StockController::class, 'adjust'])->name('stock.adjustments.store');
        Route::put('stock/items', [StockController::class, 'updateItem'])->name('stock.items.update');
        Route::put('stock/settings', [StockController::class, 'updateSettings'])->name('stock.settings.update');

        Route::post('stock/locations', [StockLocationController::class, 'store'])->name('stock.locations.store');
        Route::put('stock/locations/{location}', [StockLocationController::class, 'update'])->whereNumber('location')->name('stock.locations.update');

        Route::post('stock/transfers', [StockTransferController::class, 'store'])->name('stock.transfers.store');
        Route::post('stock/transfers/{transfer}/dispatch', [StockTransferController::class, 'dispatch'])->whereNumber('transfer')->name('stock.transfers.dispatch');
        Route::post('stock/transfers/{transfer}/receive', [StockTransferController::class, 'receive'])->whereNumber('transfer')->name('stock.transfers.receive');
        Route::post('stock/transfers/{transfer}/instant', [StockTransferController::class, 'instant'])->whereNumber('transfer')->name('stock.transfers.instant');
        Route::post('stock/transfers/{transfer}/cancel', [StockTransferController::class, 'cancel'])->whereNumber('transfer')->name('stock.transfers.cancel');

        Route::post('stock/counts', [StockCountController::class, 'store'])->name('stock.counts.store');
        Route::put('stock/counts/{count}', [StockCountController::class, 'update'])->whereNumber('count')->name('stock.counts.update');
        Route::post('stock/counts/{count}/submit', [StockCountController::class, 'submit'])->whereNumber('count')->name('stock.counts.submit');
        Route::post('stock/counts/{count}/approve', [StockCountController::class, 'approve'])->whereNumber('count')->name('stock.counts.approve');
        Route::post('stock/counts/{count}/reject', [StockCountController::class, 'reject'])->whereNumber('count')->name('stock.counts.reject');

        Route::post('orders/{order}/confirm', [OrderStockController::class, 'confirm'])->whereNumber('order')->name('orders.ready.confirm');
        Route::post('orders/{order}/cancel-remainder', [OrderStockController::class, 'cancelRemainder'])->whereNumber('order')->name('orders.ready.cancel');
        Route::post('orders/{order}/materials/reserve', [OrderStockController::class, 'reserve'])->whereNumber('order')->name('orders.materials.reserve');
        Route::post('orders/{order}/materials/issue', [OrderStockController::class, 'issue'])->whereNumber('order')->name('orders.materials.issue');
        Route::post('orders/{order}/materials/{reservation}/release', [OrderStockController::class, 'release'])->whereNumber(['order', 'reservation'])->name('orders.materials.release');
    });
});
