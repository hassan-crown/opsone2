<?php

use App\Http\Controllers\Inbox\InboxController;
use App\Http\Controllers\Inbox\LeadAssistController;
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
        // AI-4: draft a reply for the current staff member, or reject their draft. Nothing is sent from here.
        Route::post('inbox/{conversation}/suggestions', [InboxController::class, 'suggest'])->whereNumber('conversation')->name('inbox.suggest');
        Route::post('inbox/{conversation}/suggestions/{suggestion}/reject', [InboxController::class, 'rejectSuggestion'])->whereNumber(['conversation', 'suggestion'])->name('inbox.suggestion.reject');
        // AI-7a: new-lead draft + qualification fields, accepting them, booking a server-computed slot (all staff-initiated).
        Route::post('inbox/{conversation}/lead', [LeadAssistController::class, 'suggest'])->whereNumber('conversation')->name('inbox.lead.suggest');
        Route::post('inbox/{conversation}/lead/{suggestion}/accept', [LeadAssistController::class, 'accept'])->whereNumber(['conversation', 'suggestion'])->name('inbox.lead.accept');
        Route::post('inbox/{conversation}/lead/{suggestion}/reject', [LeadAssistController::class, 'reject'])->whereNumber(['conversation', 'suggestion'])->name('inbox.lead.reject');
        Route::post('inbox/{conversation}/lead/book', [LeadAssistController::class, 'book'])->whereNumber('conversation')->name('inbox.lead.book');
    });
});
