<?php

use App\Http\Controllers\Ai\AiSettingsController;
use Illuminate\Support\Facades\Route;

/*
| AI assistant (ADR-0050). Settings only in AI-0: the facility's switches (P-02, checked in the controller and the
| form request), credits and the privacy notice. Feature screens arrive with their batches (AI-1, AI-2, …).
*/

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('settings/ai', [AiSettingsController::class, 'edit'])->name('ai-settings.edit');
    Route::put('settings/ai', [AiSettingsController::class, 'update'])->name('ai-settings.update');
});
