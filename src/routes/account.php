<?php

use App\Http\Controllers\Account\FacilityProfileController;
use App\Http\Controllers\Account\OwnershipTransferController;
use App\Http\Controllers\Account\PlanController;
use App\Http\Controllers\Account\SessionController;
use App\Http\Controllers\Account\SettingsHomeController;
use Illuminate\Support\Facades\Route;

/*
| Account and facility settings (P2): settings home, facility profile, plan and usage, ownership transfer,
| and the user's own sessions. Authorization is server-side via the central Gate (Access::can).
*/

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('settings', SettingsHomeController::class)->name('settings.home');

    Route::get('settings/facility', [FacilityProfileController::class, 'edit'])->name('settings.basic');
    Route::put('settings/facility', [FacilityProfileController::class, 'update'])->name('settings.basic.update');

    Route::get('settings/plan', PlanController::class)->name('plan.show');

    Route::get('settings/ownership', [OwnershipTransferController::class, 'show'])->name('ownership.show');
    Route::post('settings/ownership', [OwnershipTransferController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('ownership.store');
    Route::post('settings/ownership/{transfer}/accept', [OwnershipTransferController::class, 'accept'])->name('ownership.accept');
    Route::post('settings/ownership/{transfer}/reject', [OwnershipTransferController::class, 'reject'])->name('ownership.reject');
    Route::delete('settings/ownership/{transfer}', [OwnershipTransferController::class, 'cancel'])->name('ownership.cancel');

    Route::get('settings/sessions', [SessionController::class, 'index'])->name('sessions.index');
    Route::delete('settings/sessions', [SessionController::class, 'destroyOthers'])
        ->middleware('throttle:6,1')
        ->name('sessions.destroy-others');
    Route::delete('settings/sessions/{session}', [SessionController::class, 'destroy'])->name('sessions.destroy');
});
