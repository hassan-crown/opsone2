<?php

use App\Http\Controllers\Store\CheckoutController;
use App\Http\Controllers\Store\StoreAdminController;
use App\Http\Controllers\Store\StoreController;
use App\Providers\StoreServiceProvider;
use Illuminate\Support\Facades\Route;

/*
| Ready-goods store (P8 part C, domain 07, ADR-0023). Loaded by StoreServiceProvider before the public site.
|
| Public: `/{ar|en}/store/...` in the customer middleware group (customer session: guest cart, checkout code, sign-in)
| plus the site's URL language. Every page is 404 while the store is not selling (license `ready_goods`, StoreGate).
| The payment provider's notifications use the finance webhook route (`/webhooks/payments/moyasar`).
|
| Staff: `/store-admin/...` (web session), every action authorized by the central Gate (P-02, P-91, P-92, P-93).
*/

Route::middleware(StoreServiceProvider::publicMiddleware())
    ->prefix('{locale}/store')->where(['locale' => 'ar|en'])->name('store.')
    ->group(function () {
        Route::get('/', [StoreController::class, 'index'])->name('index');
        Route::get('p/{product}', [StoreController::class, 'product'])->whereNumber('product')->name('product');
        Route::get('cart', [StoreController::class, 'cart'])->name('cart');
        Route::post('cart', [StoreController::class, 'add'])->middleware('throttle:60,1')->name('cart.add');
        Route::put('cart/{variant}', [StoreController::class, 'update'])->whereNumber('variant')->name('cart.update');

        Route::get('l/{token}', [CheckoutController::class, 'openLink'])->where('token', '[A-Za-z0-9]{1,64}')->middleware('throttle:30,1')->name('link');

        Route::get('checkout', [CheckoutController::class, 'show'])->name('checkout');
        Route::post('checkout/code', [CheckoutController::class, 'sendCode'])->middleware('throttle:store-otp')->name('checkout.code');
        Route::post('checkout/verify', [CheckoutController::class, 'verify'])->middleware('throttle:store-otp')->name('checkout.verify');
        Route::post('checkout/coupon', [CheckoutController::class, 'coupon'])->middleware('throttle:30,1')->name('checkout.coupon');
        Route::post('checkout', [CheckoutController::class, 'start'])->middleware('throttle:store-checkout')->name('checkout.start');
        Route::get('checkout/{reference}', [CheckoutController::class, 'pending'])->name('checkout.pending');
        Route::post('checkout/{reference}/cancel', [CheckoutController::class, 'cancel'])->middleware('throttle:10,1')->name('checkout.cancel');
    });

Route::middleware(['web', 'auth', 'verified'])->prefix('store-admin')->name('store.admin.')->group(function () {
    Route::get('settings', [StoreAdminController::class, 'settings'])->name('settings');
    Route::put('settings', [StoreAdminController::class, 'saveSettings'])->name('settings.update');
    Route::put('settings/payments', [StoreAdminController::class, 'savePayments'])->name('payments.update');

    Route::get('coupons', [StoreAdminController::class, 'coupons'])->name('coupons.index');
    Route::get('coupons/new', [StoreAdminController::class, 'newCoupon'])->name('coupons.create');
    Route::post('coupons', [StoreAdminController::class, 'storeCoupon'])->middleware('throttle:records-create')->name('coupons.store');
    Route::post('coupons/{coupon}/pause', [StoreAdminController::class, 'pauseCoupon'])->name('coupons.pause');

    Route::get('links', [StoreAdminController::class, 'links'])->name('links.index');
    Route::post('links', [StoreAdminController::class, 'storeLink'])->middleware('throttle:records-create')->name('links.store');
    Route::post('links/{link}/cancel', [StoreAdminController::class, 'cancelLink'])->name('links.cancel');
    Route::post('links/{link}/replace', [StoreAdminController::class, 'replaceLink'])->name('links.replace');
});
