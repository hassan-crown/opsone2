<?php

use App\Http\Controllers\Flow\FlowController;
use App\Http\Controllers\Flow\OrderProgressController;
use Illuminate\Support\Facades\Route;

/*
| P5 flow settings (M-23) and order progress (M-04/M-06/M-27). JSON endpoints under the web session; the pages are
| Inertia. Every write authorizes through the central Gate inside its Action. Contract: docs/p5-engine-api.md.
*/

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('settings/flow', [FlowController::class, 'edit'])->name('flow.edit');
    Route::get('settings/flow/stages/{stage}', [FlowController::class, 'stage'])->where('stage', '[a-z0-9_]{1,40}')->name('flow.stage');
    Route::put('settings/flow/draft', [FlowController::class, 'saveDraft'])->middleware('throttle:records-create')->name('flow.draft.save');
    Route::delete('settings/flow/draft', [FlowController::class, 'discardDraft'])->middleware('throttle:records-create')->name('flow.draft.discard');
    Route::post('settings/flow/check', [FlowController::class, 'check'])->middleware('throttle:search')->name('flow.check');
    Route::post('settings/flow/publish', [FlowController::class, 'publish'])->middleware('throttle:records-create')->name('flow.publish');
    Route::post('settings/flow/template', [FlowController::class, 'chooseTemplate'])->middleware('throttle:records-create')->name('flow.template');

    Route::prefix('orders/{order}')->whereNumber('order')->group(function () {
        Route::get('progress', [OrderProgressController::class, 'show'])->name('orders.progress');
        Route::post('stages/{stage}/complete', [OrderProgressController::class, 'completeStage'])->whereNumber('stage')->middleware('throttle:records-create')->name('orders.progress.complete');
        Route::post('actions/{action}/allow', [OrderProgressController::class, 'allow'])->whereNumber('action')->middleware('throttle:records-create')->name('orders.progress.allow');
        Route::post('actions/{action}/rework', [OrderProgressController::class, 'rework'])->whereNumber('action')->middleware('throttle:records-create')->name('orders.progress.rework');
        Route::post('pause', [OrderProgressController::class, 'pause'])->middleware('throttle:records-create')->name('orders.progress.pause');
        Route::post('resume', [OrderProgressController::class, 'resume'])->middleware('throttle:records-create')->name('orders.progress.resume');
        Route::post('finish-execution', [OrderProgressController::class, 'finishExecution'])->middleware('throttle:records-create')->name('orders.progress.finish');
        Route::get('upcoming', [OrderProgressController::class, 'upcoming'])->name('orders.progress.upcoming');
        Route::put('upcoming', [OrderProgressController::class, 'editUpcoming'])->middleware('throttle:records-create')->name('orders.progress.upcoming.save');
    });
});
