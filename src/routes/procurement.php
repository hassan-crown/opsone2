<?php

use App\Http\Controllers\Procurement\PurchaseController;
use App\Http\Controllers\Procurement\SupplierController;
use Illuminate\Support\Facades\Route;

/*
| Suppliers and purchase orders (P7 part B, domain 06: FUL-05..07; M-13). Every write authorizes on the record through
| the central Gate in its Action and carries an idempotency key and the lock_version the screen read.
| Screens: SupplierList / SupplierNew, PurchaseList / PurchaseNew / order page / PurchaseReceive / PoCancel.
*/

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('suppliers', [SupplierController::class, 'index'])->name('suppliers.index');
    Route::get('suppliers/create', [SupplierController::class, 'create'])->name('suppliers.create');
    Route::get('suppliers/{supplier}/edit', [SupplierController::class, 'edit'])->whereNumber('supplier')->name('suppliers.edit');

    Route::get('purchases', [PurchaseController::class, 'index'])->name('purchases.index');
    Route::get('purchases/create', [PurchaseController::class, 'create'])->name('purchases.create');
    Route::get('purchases/items', [PurchaseController::class, 'items'])->name('purchases.items');
    Route::get('purchases/{purchase}', [PurchaseController::class, 'show'])->whereNumber('purchase')->name('purchases.show');
    Route::get('purchases/{purchase}/edit', [PurchaseController::class, 'edit'])->whereNumber('purchase')->name('purchases.edit');
    Route::get('purchases/{purchase}/receive', [PurchaseController::class, 'receiveScreen'])->whereNumber('purchase')->name('purchases.receive.form');
    Route::get('purchases/{purchase}/cancel', [PurchaseController::class, 'cancelScreen'])->whereNumber('purchase')->name('purchases.cancel.form');

    Route::middleware('throttle:records-create')->group(function () {
        Route::post('suppliers', [SupplierController::class, 'store'])->name('suppliers.store');
        Route::put('suppliers/{supplier}', [SupplierController::class, 'update'])->whereNumber('supplier')->name('suppliers.update');
        Route::post('suppliers/{supplier}/disable', [SupplierController::class, 'disable'])->whereNumber('supplier')->name('suppliers.disable');
        Route::post('suppliers/{supplier}/enable', [SupplierController::class, 'enable'])->whereNumber('supplier')->name('suppliers.enable');

        Route::post('purchases', [PurchaseController::class, 'store'])->name('purchases.store');
        Route::put('purchases/{purchase}', [PurchaseController::class, 'update'])->whereNumber('purchase')->name('purchases.update');
        Route::post('purchases/{purchase}/confirm', [PurchaseController::class, 'confirm'])->whereNumber('purchase')->name('purchases.confirm');
        Route::post('purchases/{purchase}/receive', [PurchaseController::class, 'receive'])->whereNumber('purchase')->name('purchases.receive');
        Route::post('purchases/{purchase}/cancel', [PurchaseController::class, 'cancel'])->whereNumber('purchase')->name('purchases.cancel');
        Route::post('purchases/{purchase}/adjust', [PurchaseController::class, 'adjust'])->whereNumber('purchase')->name('purchases.adjust');
    });
});
