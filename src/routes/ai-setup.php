<?php

use App\Http\Controllers\Ai\AiSetupController;
use Illuminate\Support\Facades\Route;

/*
| AI-1 «اقتراح الإعداد» (ADR-0050, plan §2 axis 1): the onboarding specialist's screen. P-104 on every route (checked
| in the controller and again in ApplyFlowSetup). Proposals run on the queue; applying writes drafts only.
*/

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('settings/ai-setup', [AiSetupController::class, 'show'])->name('ai-setup.show');
    Route::post('settings/ai-setup', [AiSetupController::class, 'store'])->middleware('throttle:records-create')->name('ai-setup.store');
    Route::post('settings/ai-setup/{setup}/apply', [AiSetupController::class, 'apply'])->whereNumber('setup')->middleware('throttle:records-create')->name('ai-setup.apply');
    Route::post('settings/ai-setup/{setup}/reject', [AiSetupController::class, 'reject'])->whereNumber('setup')->middleware('throttle:records-create')->name('ai-setup.reject');
});
