<?php

use App\Http\Controllers\Inbox\InboxController;
use Illuminate\Support\Facades\Route;

/*
| WhatsApp inbox (AI-3, ADR-0030 amendment): conversations of the facility's own WhatsApp number. Pages answer 404
| while the inbox is off; every write authorizes P-108 on the conversation. Meta's webhook is in routes/notifications.php.
*/

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('inbox', [InboxController::class, 'index'])->name('inbox.index');
    Route::get('inbox/{conversation}', [InboxController::class, 'show'])->whereNumber('conversation')->name('inbox.show');

    Route::middleware('throttle:records-create')->group(function () {
        Route::post('inbox/{conversation}/replies', [InboxController::class, 'reply'])->whereNumber('conversation')->name('inbox.reply');
        Route::post('inbox/{conversation}/assign', [InboxController::class, 'assign'])->whereNumber('conversation')->name('inbox.assign');
        Route::post('inbox/{conversation}/link', [InboxController::class, 'link'])->whereNumber('conversation')->name('inbox.link');
    });
});
