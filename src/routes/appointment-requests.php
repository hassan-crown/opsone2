<?php

use App\Http\Controllers\AppointmentRequests\AppointmentRequestController;
use App\Http\Controllers\AppointmentRequests\AppointmentRequestSettingsController;
use Illuminate\Support\Facades\Route;

/*
| Customer appointment requests (ADR-0039): the staff queue (P-54 in scope, checked on every record) and the owner's
| settings (reading P-104 or P-02, changes P-104). «Schedule» is the normal ApptSchedule (`appointments.create?request=`).
*/

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('appointment-requests', [AppointmentRequestController::class, 'index'])->name('appointment-requests.index');
    Route::get('appointment-requests/{appointmentRequest}', [AppointmentRequestController::class, 'show'])->whereNumber('appointmentRequest')->name('appointment-requests.show');
    Route::post('appointment-requests/{appointmentRequest}/assign', [AppointmentRequestController::class, 'assign'])->whereNumber('appointmentRequest')->middleware('throttle:records-create')->name('appointment-requests.assign');
    Route::post('appointment-requests/{appointmentRequest}/close', [AppointmentRequestController::class, 'close'])->whereNumber('appointmentRequest')->middleware('throttle:records-create')->name('appointment-requests.close');

    Route::get('settings/appointment-requests', [AppointmentRequestSettingsController::class, 'edit'])->name('rules.appointment_requests');
    Route::put('settings/appointment-requests', [AppointmentRequestSettingsController::class, 'update'])->middleware('throttle:records-create')->name('rules.appointment_requests.update');
});
