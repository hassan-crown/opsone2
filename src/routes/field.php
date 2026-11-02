<?php

use App\Http\Controllers\Field\FieldController;
use App\Http\Controllers\Field\FieldVisitController;
use App\Http\Controllers\Field\InstallationRemarkController;
use Illuminate\Support\Facades\Route;

/*
| Installation field app (ADR-0036): the technician's visits, one screen per visit, installable as a PWA (manifest
| and service worker in public/, scope /field). Only the booked members of an appointment see or act on it.
*/

// Public: browsers fetch a manifest without cookies.
Route::get('field/manifest.webmanifest', [FieldController::class, 'manifest'])->name('field.manifest');

Route::middleware(['auth', 'verified'])->prefix('field')->name('field.')->group(function () {
    Route::get('/', [FieldController::class, 'index'])->name('index');
    Route::get('install', [FieldController::class, 'install'])->name('install');
    Route::get('visits/{appointment}', [FieldController::class, 'show'])->whereNumber('appointment')->name('visits.show');

    Route::middleware('throttle:records-create')->whereNumber('appointment')->group(function () {
        Route::post('visits/{appointment}/arrive', [FieldVisitController::class, 'arrive'])->name('visits.arrive');
        Route::post('visits/{appointment}/remarks', [FieldVisitController::class, 'remark'])->name('visits.remarks');
        Route::post('visits/{appointment}/signature', [FieldVisitController::class, 'sign'])->name('visits.sign');
        Route::post('visits/{appointment}/signature-link', [FieldVisitController::class, 'signatureLink'])->name('visits.signature-link');
        Route::post('visits/{appointment}/finish', [FieldVisitController::class, 'finish'])->name('visits.finish');
    });
    // Photos come in bursts from the device's queue after a reconnect: their own, wider limit.
    Route::post('visits/{appointment}/photos', [FieldVisitController::class, 'photo'])->whereNumber('appointment')->middleware('throttle:60,1')->name('visits.photos');
});

Route::middleware(['auth', 'verified', 'throttle:records-create'])->group(function () {
    Route::post('installation-remarks/{remark}/resolve', [InstallationRemarkController::class, 'resolve'])->whereNumber('remark')->name('installation-remarks.resolve');
});
