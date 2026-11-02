<?php

use App\Http\Controllers\Rules\DocumentClauseController;
use App\Http\Controllers\Rules\MessageTemplateController;
use App\Http\Controllers\Rules\NumberFormatController;
use App\Http\Controllers\Rules\PaymentPlanController;
use App\Http\Controllers\Rules\ReminderSettingsController;
use App\Http\Controllers\Rules\TaskSlaController;
use Illuminate\Support\Facades\Route;

/*
| No-Code phase 1a (ADR-0037): the owner's business rules — payment plans, reminders, clauses, messages, task SLA and
| numbering. Reading needs P-103 or P-02, every change P-103 (checked in the actions) and is audited.
*/

Route::middleware(['auth', 'verified'])->prefix('settings')->name('rules.')->group(function () {
    Route::get('payment-plans', [PaymentPlanController::class, 'index'])->name('plans');
    Route::get('reminders', [ReminderSettingsController::class, 'edit'])->name('reminders');
    Route::get('clauses', [DocumentClauseController::class, 'index'])->name('clauses');
    Route::get('messages', [MessageTemplateController::class, 'index'])->name('messages');
    Route::get('task-sla', [TaskSlaController::class, 'edit'])->name('sla');
    Route::get('numbering', [NumberFormatController::class, 'index'])->name('numbering');

    Route::middleware('throttle:records-create')->group(function () {
        Route::post('payment-plans', [PaymentPlanController::class, 'store'])->name('plans.store');
        Route::put('payment-plans/{plan}', [PaymentPlanController::class, 'update'])->whereNumber('plan')->name('plans.update');
        Route::post('payment-plans/{plan}/archive', [PaymentPlanController::class, 'archive'])->whereNumber('plan')->name('plans.archive');
        Route::put('reminders', [ReminderSettingsController::class, 'update'])->name('reminders.update');
        Route::post('clauses', [DocumentClauseController::class, 'store'])->name('clauses.store');
        Route::put('clauses/{clause}', [DocumentClauseController::class, 'update'])->whereNumber('clause')->name('clauses.update');
        Route::post('clauses/{clause}/archive', [DocumentClauseController::class, 'archive'])->whereNumber('clause')->name('clauses.archive');
        Route::put('messages', [MessageTemplateController::class, 'update'])->name('messages.update');
        Route::delete('messages', [MessageTemplateController::class, 'reset'])->name('messages.reset');
        Route::post('messages/preview', [MessageTemplateController::class, 'preview'])->name('messages.preview');
        Route::put('messages/whatsapp', [MessageTemplateController::class, 'whatsapp'])->name('messages.whatsapp');
        Route::put('task-sla', [TaskSlaController::class, 'update'])->name('sla.update');
        Route::put('numbering', [NumberFormatController::class, 'update'])->name('numbering.update');
    });
});
