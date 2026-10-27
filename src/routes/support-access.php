<?php

use App\Http\Controllers\SupportAccess\SupportAccessController;
use Illuminate\Support\Facades\Route;

/*
| OpsOne support access (M-38, SHR-14, P-79). Granting opens facility data, so it asks for the password again.
*/

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('settings/support-access', [SupportAccessController::class, 'index'])->name('support-access.index');
    Route::post('settings/support-access/{grant}/grant', [SupportAccessController::class, 'grant'])
        ->middleware('throttle:6,1')
        ->name('support-access.grant');
    Route::post('settings/support-access/{grant}/reject', [SupportAccessController::class, 'reject'])->name('support-access.reject');
    Route::post('settings/support-access/{grant}/revoke', [SupportAccessController::class, 'revoke'])->name('support-access.revoke');
});
