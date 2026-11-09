<?php

use App\Http\Controllers\Ai\AiSettingsController;
use App\Http\Controllers\Ai\AutoReplySettingsController;
use App\Http\Controllers\Ai\DataQuestionController;
use Illuminate\Support\Facades\Route;

/*
| AI assistant (ADR-0050). Settings only in AI-0: the facility's switches (P-02, checked in the controller and the
| form request), credits and the privacy notice. Feature screens arrive with their batches (AI-1, AI-2, …).
*/

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('settings/ai', [AiSettingsController::class, 'edit'])->name('ai-settings.edit');
    Route::put('settings/ai', [AiSettingsController::class, 'update'])->name('ai-settings.update');
    Route::post('ai/data-questions', [DataQuestionController::class, 'ask'])->middleware('throttle:20,1')->name('ai.data-questions.ask');
    // AI-6 automatic reply (Q-92: the facility owner only, checked in the controller and the form request).
    Route::get('settings/ai/auto-reply', [AutoReplySettingsController::class, 'edit'])->name('ai-auto-reply.edit');
    Route::put('settings/ai/auto-reply', [AutoReplySettingsController::class, 'update'])->name('ai-auto-reply.update');
});
