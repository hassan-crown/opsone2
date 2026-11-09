<?php

use App\Http\Controllers\Inbox\InboxController;
use App\Http\Controllers\Inbox\LeadAssistController;
use App\Http\Controllers\Inbox\OrderDraftController;
use Illuminate\Support\Facades\Route;

/*
| WhatsApp inbox (AI-3, ADR-0030 amendment): conversations of the facility's own WhatsApp number. Pages answer 404
| while the inbox is off; every write authorizes P-108 on the conversation. Meta's webhook is in routes/notifications.php.
*/

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('inbox', [InboxController::class, 'index'])->name('inbox.index');
    Route::get('inbox/{conversation}', [InboxController::class, 'show'])->whereNumber('conversation')->name('inbox.show');
    // AI-5: the staff member's own order draft from the conversation (review screen).
    Route::get('inbox/{conversation}/order-draft/{draft}', [OrderDraftController::class, 'show'])->whereNumber(['conversation', 'draft'])->name('inbox.order-draft.show');
    // The price preview runs on every edit (debounced): its own per-user limit, apart from the create limit.
    Route::post('inbox/{conversation}/order-draft/{draft}/price', [OrderDraftController::class, 'price'])->whereNumber(['conversation', 'draft'])->middleware('throttle:search')->name('inbox.order-draft.price');

    Route::middleware('throttle:records-create')->group(function () {
        Route::post('inbox/{conversation}/replies', [InboxController::class, 'reply'])->whereNumber('conversation')->name('inbox.reply');
        Route::post('inbox/{conversation}/assign', [InboxController::class, 'assign'])->whereNumber('conversation')->name('inbox.assign');
        Route::post('inbox/{conversation}/link', [InboxController::class, 'link'])->whereNumber('conversation')->name('inbox.link');
        // AI-4: draft a reply for the current staff member, or reject their draft. Nothing is sent from here.
        Route::post('inbox/{conversation}/suggestions', [InboxController::class, 'suggest'])->whereNumber('conversation')->name('inbox.suggest');
        // AI-5: draft an order from the conversation, then create it through CreateOrders or reject the draft.
        Route::post('inbox/{conversation}/order-draft', [OrderDraftController::class, 'propose'])->whereNumber('conversation')->name('inbox.order-draft.propose');
        Route::post('inbox/{conversation}/order-draft/{draft}/confirm', [OrderDraftController::class, 'confirm'])->whereNumber(['conversation', 'draft'])->name('inbox.order-draft.confirm');
        Route::post('inbox/{conversation}/order-draft/{draft}/reject', [OrderDraftController::class, 'reject'])->whereNumber(['conversation', 'draft'])->name('inbox.order-draft.reject');
        Route::post('inbox/{conversation}/suggestions/{suggestion}/reject', [InboxController::class, 'rejectSuggestion'])->whereNumber(['conversation', 'suggestion'])->name('inbox.suggestion.reject');
        // AI-7a: new-lead draft + qualification fields, accepting them, booking a server-computed slot (all staff-initiated).
        Route::post('inbox/{conversation}/lead', [LeadAssistController::class, 'suggest'])->whereNumber('conversation')->name('inbox.lead.suggest');
        Route::post('inbox/{conversation}/lead/{suggestion}/accept', [LeadAssistController::class, 'accept'])->whereNumber(['conversation', 'suggestion'])->name('inbox.lead.accept');
        Route::post('inbox/{conversation}/lead/{suggestion}/reject', [LeadAssistController::class, 'reject'])->whereNumber(['conversation', 'suggestion'])->name('inbox.lead.reject');
        Route::post('inbox/{conversation}/lead/book', [LeadAssistController::class, 'book'])->whereNumber('conversation')->name('inbox.lead.book');
    });
});
