<?php

use App\Http\Controllers\Account\WorkSettingsController;
use App\Http\Controllers\Calendar\AppointmentController;
use App\Http\Controllers\Calendar\CalendarController;
use Illuminate\Support\Facades\Route;

/*
| Calendar and appointments (P5, domain 09, SHR-04): CalWeek / daily view, ApptSchedule, ApptChange. Every action
| authorizes on the record in the M-16 Actions (P-54, or a booked member for the outcome). Contract: docs/p5-engine-api.md.
*/

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('calendar', [CalendarController::class, 'index'])->name('calendar.index');
    Route::get('calendar/conflicts', [CalendarController::class, 'conflicts'])->middleware('throttle:search')->name('calendar.conflicts');

    Route::get('appointments/new', [CalendarController::class, 'create'])->name('appointments.create');
    Route::post('appointments', [AppointmentController::class, 'store'])->middleware('throttle:records-create')->name('appointments.store');
    Route::get('appointments/{appointment}', [AppointmentController::class, 'show'])->whereNumber('appointment')->name('appointments.show');
    Route::post('appointments/{appointment}/send', [AppointmentController::class, 'send'])->whereNumber('appointment')->middleware('throttle:records-create')->name('appointments.send');
    Route::post('appointments/{appointment}/confirm', [AppointmentController::class, 'confirm'])->whereNumber('appointment')->middleware('throttle:records-create')->name('appointments.confirm');
    Route::post('appointments/{appointment}/change', [AppointmentController::class, 'requestChange'])->whereNumber('appointment')->middleware('throttle:records-create')->name('appointments.change');
    Route::post('appointments/{appointment}/change/approve', [AppointmentController::class, 'approveChange'])->whereNumber('appointment')->middleware('throttle:records-create')->name('appointments.change.approve');
    Route::post('appointments/{appointment}/change/discard', [AppointmentController::class, 'discardChange'])->whereNumber('appointment')->middleware('throttle:records-create')->name('appointments.change.discard');
    Route::post('appointments/{appointment}/cancel', [AppointmentController::class, 'cancel'])->whereNumber('appointment')->middleware('throttle:records-create')->name('appointments.cancel');
    Route::post('appointments/{appointment}/complete', [AppointmentController::class, 'complete'])->whereNumber('appointment')->middleware('throttle:records-create')->name('appointments.complete');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('settings/work', [WorkSettingsController::class, 'edit'])->name('settings.work');
    Route::put('settings/work', [WorkSettingsController::class, 'update'])->middleware('throttle:records-create')->name('settings.work.update');
});
