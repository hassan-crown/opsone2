<?php

use App\Http\Controllers\Portal\TicketController;
use App\Http\Middleware\Portal\AuthenticateCustomer;
use Illuminate\Support\Facades\Route;

/*
| Customer tickets (P8 part D, SHR-08, P-71). Loaded by PortalServiceProvider next to routes/customer.php: under
| `/portal`, names `customer.tickets.*`, the `customer` middleware group. Only the signed-in customer's own tickets.
*/

Route::middleware(AuthenticateCustomer::class)->group(function () {
    Route::get('tickets', [TicketController::class, 'index'])->name('tickets.index');
    Route::get('tickets/new', [TicketController::class, 'create'])->name('tickets.create');
    Route::post('tickets', [TicketController::class, 'store'])->middleware('throttle:portal-write')->name('tickets.store');
    Route::get('tickets/{ticket}', [TicketController::class, 'show'])->name('tickets.show');
    Route::post('tickets/{ticket}/replies', [TicketController::class, 'reply'])->middleware('throttle:portal-write')->name('tickets.reply');
    Route::post('tickets/{ticket}/close', [TicketController::class, 'close'])->middleware('throttle:portal-write')->name('tickets.close');
});
