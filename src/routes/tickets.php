<?php

use App\Http\Controllers\Tickets\TicketController;
use Illuminate\Support\Facades\Route;

/*
| Staff side of customer support tickets (P8 part D, domain 09, SHR-08, M-21): the scoped inbox (TicketInbox) and the
| ticket page. Every write authorizes P-59 on the ticket in its Action. The customer side is routes/customer-tickets.php.
*/

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('tickets', [TicketController::class, 'index'])->name('tickets.index');
    Route::get('tickets/{ticket}', [TicketController::class, 'show'])->whereNumber('ticket')->name('tickets.show');

    Route::middleware('throttle:records-create')->group(function () {
        Route::post('tickets/{ticket}/take', [TicketController::class, 'take'])->whereNumber('ticket')->name('tickets.take');
        Route::post('tickets/{ticket}/assign', [TicketController::class, 'assign'])->whereNumber('ticket')->name('tickets.assign');
        Route::post('tickets/{ticket}/replies', [TicketController::class, 'reply'])->whereNumber('ticket')->name('tickets.reply');
        Route::post('tickets/{ticket}/request-info', [TicketController::class, 'requestInfo'])->whereNumber('ticket')->name('tickets.request-info');
        Route::post('tickets/{ticket}/resolve', [TicketController::class, 'resolve'])->whereNumber('ticket')->name('tickets.resolve');
        Route::post('tickets/{ticket}/close', [TicketController::class, 'close'])->whereNumber('ticket')->name('tickets.close');
        Route::post('tickets/{ticket}/reopen', [TicketController::class, 'reopen'])->whereNumber('ticket')->name('tickets.reopen');
    });
});
