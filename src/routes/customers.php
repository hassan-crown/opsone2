<?php

use App\Http\Controllers\Customers\CustomerContactController;
use App\Http\Controllers\Customers\CustomerController;
use App\Http\Controllers\Customers\InterestController;
use App\Http\Controllers\Customers\PortalInviteController;
use Illuminate\Support\Facades\Route;

/*
| Customers and interests (P4 part A, domain 02). Every route authorizes server-side through the central Gate;
| lists are narrowed to the user's grants (ScopedQuery).
*/

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('customers', [CustomerController::class, 'index'])->name('customers.index');
    Route::get('customers/create', [CustomerController::class, 'create'])->name('customers.create');
    Route::post('customers', [CustomerController::class, 'store'])->middleware('throttle:records-create')->name('customers.store');
    Route::get('customers/{customer}', [CustomerController::class, 'show'])->whereNumber('customer')->name('customers.show');
    Route::get('customers/{customer}/edit', [CustomerController::class, 'edit'])->whereNumber('customer')->name('customers.edit');
    Route::put('customers/{customer}', [CustomerController::class, 'update'])->whereNumber('customer')->name('customers.update');
    // P8: the customer's workspace activation link (ADR-0022).
    Route::post('customers/{customer}/portal-invite', PortalInviteController::class)->whereNumber('customer')->middleware('throttle:records-create')->name('customers.portal-invite');

    Route::post('customers/{customer}/contacts', [CustomerContactController::class, 'store'])->whereNumber('customer')->middleware('throttle:records-create')->name('customers.contacts.store');
    Route::put('customer-contacts/{contact}', [CustomerContactController::class, 'update'])->whereNumber('contact')->name('customers.contacts.update');
    Route::post('customer-contacts/{contact}/primary', [CustomerContactController::class, 'primary'])->whereNumber('contact')->name('customers.contacts.primary');
    Route::delete('customer-contacts/{contact}', [CustomerContactController::class, 'destroy'])->whereNumber('contact')->name('customers.contacts.destroy');

    Route::get('interests', [InterestController::class, 'index'])->name('interests.index');
    Route::get('interests/create', [InterestController::class, 'create'])->name('interests.create');
    Route::post('interests', [InterestController::class, 'store'])->middleware('throttle:records-create')->name('interests.store');
    Route::get('interests/{interest}', [InterestController::class, 'show'])->whereNumber('interest')->name('interests.show');
    Route::get('interests/{interest}/edit', [InterestController::class, 'edit'])->whereNumber('interest')->name('interests.edit');
    Route::put('interests/{interest}', [InterestController::class, 'update'])->whereNumber('interest')->name('interests.update');
    Route::post('interests/{interest}/follow', [InterestController::class, 'follow'])->whereNumber('interest')->name('interests.follow');
    Route::post('interests/{interest}/convert', [InterestController::class, 'convert'])->whereNumber('interest')->middleware('throttle:records-create')->name('interests.convert');
    Route::post('interests/{interest}/close', [InterestController::class, 'close'])->whereNumber('interest')->name('interests.close');
    Route::post('interests/{interest}/reopen', [InterestController::class, 'reopen'])->whereNumber('interest')->name('interests.reopen');
});
