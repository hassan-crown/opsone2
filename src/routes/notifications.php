<?php

use App\Http\Controllers\Notifications\IntegrationsController;
use App\Http\Controllers\Notifications\NotificationController;
use App\Http\Controllers\Notifications\NotificationPreferenceController;
use Illuminate\Support\Facades\Route;

/*
| Notifications and outbound channels (P3-B): the shell bell, per-user preferences and the facility's
| integrations (SMTP, SMS). Every route authorizes server-side through the central Gate.
*/

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('notifications/{notification}/open', [NotificationController::class, 'open'])->name('notifications.open');
    Route::post('notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
    Route::post('notifications/{notification}/read', [NotificationController::class, 'read'])->name('notifications.read');

    Route::get('settings/notifications', [NotificationPreferenceController::class, 'edit'])->name('notifications.preferences');
    Route::put('settings/notifications', [NotificationPreferenceController::class, 'update'])->name('notifications.preferences.update');

    Route::get('settings/integrations', [IntegrationsController::class, 'edit'])->name('integrations.edit');
    Route::put('settings/integrations/smtp', [IntegrationsController::class, 'updateSmtp'])->name('integrations.smtp.update');
    Route::post('settings/integrations/smtp/test', [IntegrationsController::class, 'testSmtp'])
        ->middleware('throttle:5,1')
        ->name('integrations.smtp.test');
    Route::post('settings/integrations/smtp/enable', [IntegrationsController::class, 'enableSmtp'])->name('integrations.smtp.enable');
    Route::post('settings/integrations/smtp/disable', [IntegrationsController::class, 'disableSmtp'])->name('integrations.smtp.disable');
});
