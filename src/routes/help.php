<?php

use App\Http\Controllers\Help\HelpController;
use App\Http\Controllers\Help\SupportRequestController;
use App\Http\Controllers\SearchController;
use Illuminate\Support\Facades\Route;

/*
| Help center, what's new, support request and global search (P3 part C). Help is readable by every signed-in
| user (P-83); search results are filtered per record by the central Gate (Access::can).
*/

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('help', [HelpController::class, 'index'])->name('help.index');
    Route::get('help/whats-new', [HelpController::class, 'whatsNew'])->name('help.whats-new');
    Route::get('help/context', [HelpController::class, 'context'])->name('help.context');
    Route::get('help/support', [SupportRequestController::class, 'create'])->name('help.support');
    Route::post('help/support', [SupportRequestController::class, 'store'])->middleware('throttle:5,1')->name('help.support.store');
    Route::get('help/category/{category}', [HelpController::class, 'category'])->name('help.category');
    Route::get('help/{article:slug}', [HelpController::class, 'article'])->name('help.article');

    Route::get('search', [SearchController::class, 'index'])->name('search.index');
    Route::get('search/suggest', [SearchController::class, 'suggest'])->middleware('throttle:60,1')->name('search.suggest');
});
