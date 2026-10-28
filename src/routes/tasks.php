<?php

use App\Http\Controllers\Tasks\TaskActionController;
use App\Http\Controllers\Tasks\TaskAttachmentController;
use App\Http\Controllers\Tasks\TaskController;
use App\Http\Controllers\Tasks\TaskMeasurementController;
use Illuminate\Support\Facades\Route;

/*
| Task screens and transitions (P5, M-07: SHR-02, WF-07..09). Every endpoint authorizes on the task through the
| central Gate (inside the Actions). Contract: docs/p5-engine-api.md and docs/p5-tasks-api.md.
*/

Route::middleware(['auth', 'verified'])->prefix('tasks')->name('tasks.')->group(function () {
    Route::get('/', [TaskController::class, 'index'])->name('index');
    Route::get('today', [TaskController::class, 'today'])->name('today');
    Route::get('{task}', [TaskController::class, 'show'])->whereNumber('task')->name('show');
    Route::get('{task}/assign', [TaskController::class, 'assign'])->whereNumber('task')->name('assign.show');

    Route::middleware('throttle:records-create')->whereNumber('task')->group(function () {
        Route::post('{task}/claim', [TaskActionController::class, 'claim'])->name('claim');
        Route::post('{task}/assign', [TaskActionController::class, 'assign'])->name('assign');
        Route::post('{task}/reassign', [TaskActionController::class, 'reassign'])->name('reassign');
        Route::post('{task}/start', [TaskActionController::class, 'start'])->name('start');
        Route::post('{task}/wait', [TaskActionController::class, 'wait'])->name('wait');
        Route::post('{task}/resolve', [TaskActionController::class, 'resolve'])->name('resolve');
        Route::post('{task}/complete', [TaskActionController::class, 'complete'])->name('complete');
        Route::post('{task}/cancel', [TaskActionController::class, 'cancel'])->name('cancel');
        Route::post('{task}/attachments', [TaskAttachmentController::class, 'store'])->name('attachments.store');
        Route::post('{task}/measurements', [TaskMeasurementController::class, 'store'])->name('measurements.store');
        Route::put('{task}/measurements/{measurement}', [TaskMeasurementController::class, 'update'])->whereNumber('measurement')->name('measurements.update');
    });
    Route::get('{task}/measure', [TaskMeasurementController::class, 'index'])->whereNumber('task')->name('measure');
});
