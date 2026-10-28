<?php

use App\Http\Controllers\Catalog\CatalogHomeController;
use App\Http\Controllers\Catalog\ImageController;
use App\Http\Controllers\Catalog\MaterialController;
use App\Http\Controllers\Catalog\ProductController;
use App\Http\Controllers\Catalog\ReadyProductController;
use Illuminate\Support\Facades\Route;

/*
| Catalog (P4 part B, domain 02): made-to-order products, materials and ready goods with variants. Every action is
| authorized server-side by the central Gate (P-11, P-12 + license capability `ready_goods`, P-13). No route deletes.
*/

Route::middleware(['auth', 'verified'])->prefix('catalog')->name('catalog.')->group(function () {
    Route::get('/', CatalogHomeController::class)->name('home');

    Route::get('products', [ProductController::class, 'index'])->name('products.index');
    Route::get('products/new', [ProductController::class, 'create'])->name('products.create');
    Route::post('products', [ProductController::class, 'store'])->middleware('throttle:records-create')->name('products.store');
    Route::get('products/{product}', [ProductController::class, 'edit'])->name('products.edit');
    Route::put('products/{product}', [ProductController::class, 'update'])->name('products.update');
    Route::post('products/{product}/disable', [ProductController::class, 'disable'])->name('products.disable');
    Route::post('products/{product}/enable', [ProductController::class, 'enable'])->name('products.enable');
    Route::post('products/{product}/images', [ImageController::class, 'product'])->middleware('throttle:30,1')->name('products.images');

    Route::get('materials', [MaterialController::class, 'index'])->name('materials.index');
    Route::post('materials', [MaterialController::class, 'store'])->middleware('throttle:records-create')->name('materials.store');
    Route::put('materials/{material}', [MaterialController::class, 'update'])->name('materials.update');
    Route::post('materials/{material}/disable', [MaterialController::class, 'disable'])->name('materials.disable');
    Route::post('materials/{material}/enable', [MaterialController::class, 'enable'])->name('materials.enable');
    Route::post('materials/{material}/images', [ImageController::class, 'material'])->middleware('throttle:30,1')->name('materials.images');

    Route::get('ready', [ReadyProductController::class, 'index'])->name('ready.index');
    Route::get('ready/new', [ReadyProductController::class, 'create'])->name('ready.create');
    Route::post('ready', [ReadyProductController::class, 'store'])->middleware('throttle:records-create')->name('ready.store');
    Route::get('ready/{ready}', [ReadyProductController::class, 'edit'])->name('ready.edit');
    Route::put('ready/{ready}', [ReadyProductController::class, 'update'])->name('ready.update');
    Route::post('ready/{ready}/status', [ReadyProductController::class, 'status'])->name('ready.status');
    Route::post('ready/{ready}/images', [ImageController::class, 'ready'])->middleware('throttle:30,1')->name('ready.images');
    Route::post('ready/{ready}/variants', [ReadyProductController::class, 'storeVariant'])->middleware('throttle:records-create')->name('ready.variants.store');
    Route::put('ready/{ready}/variants/{variant}', [ReadyProductController::class, 'updateVariant'])->name('ready.variants.update');
    Route::post('ready/{ready}/variants/{variant}/status', [ReadyProductController::class, 'variantStatus'])->name('ready.variants.status');
});
