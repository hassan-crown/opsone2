<?php

use App\Http\Controllers\FieldSales\FieldDispatchController;
use App\Http\Controllers\FieldSales\FieldLeadController;
use App\Http\Controllers\FieldSales\FieldSalesSettingsController;
use App\Http\Controllers\FieldSales\FieldTeamController;
use App\Http\Controllers\FieldSales\OrderSalesChannelController;
use App\Http\Controllers\FieldSales\SalesVisitController;
use Illuminate\Support\Facades\Route;

/*
| Field sales (ADR-0041): the rep's leads and sales visits inside the field app (scope /field), the supervisor's
| dispatch board and team follow-up with the rep's permissions and discount (P-105), the order's sales channel (P-106) and the settings (P-104, a configuration-release section).
*/

Route::middleware(['auth', 'verified'])->group(function () {
    Route::prefix('field')->name('field.')->group(function () {
        Route::get('leads/{interest}', [FieldLeadController::class, 'show'])->whereNumber('interest')->name('leads.show');

        Route::middleware('throttle:records-create')->group(function () {
            Route::post('leads/{interest}/follow', [FieldLeadController::class, 'follow'])->whereNumber('interest')->name('leads.follow');
            Route::post('leads/{interest}/close', [FieldLeadController::class, 'close'])->whereNumber('interest')->name('leads.close');
            Route::post('leads/{interest}/convert', [FieldLeadController::class, 'convert'])->whereNumber('interest')->name('leads.convert');

            Route::whereNumber('appointment')->prefix('visits/{appointment}/sales')->name('sales.')->group(function () {
                Route::post('start', [SalesVisitController::class, 'start'])->name('start');
                Route::post('note', [SalesVisitController::class, 'note'])->name('note');
                Route::post('measurements', [SalesVisitController::class, 'measure'])->name('measure');
                Route::post('quote', [SalesVisitController::class, 'createQuote'])->name('quote');
                Route::post('documents/{document}/send', [SalesVisitController::class, 'sendDocument'])->whereNumber('document')->name('documents.send');
                Route::post('documents/{document}/sign-link', [SalesVisitController::class, 'signLink'])->whereNumber('document')->name('documents.sign-link');
                Route::post('portal-invite', [SalesVisitController::class, 'portalInvite'])->name('portal-invite');
                Route::post('finish', [SalesVisitController::class, 'finish'])->name('finish');
            });
        });
        // Photos come in bursts from the device's queue after a reconnect: their own, wider limit.
        Route::post('visits/{appointment}/sales/photos', [SalesVisitController::class, 'photo'])->whereNumber('appointment')->middleware('throttle:60,1')->name('sales.photos');
    });

    Route::get('field-sales', [FieldDispatchController::class, 'index'])->name('field-sales.index');
    Route::get('field-sales/team', [FieldTeamController::class, 'index'])->name('field-sales.team');
    Route::get('field-sales/team/{rep}', [FieldTeamController::class, 'show'])->whereNumber('rep')->name('field-sales.team.show');
    Route::get('settings/field-sales', [FieldSalesSettingsController::class, 'edit'])->name('rules.field_sales');

    Route::middleware('throttle:records-create')->group(function () {
        Route::post('field-sales/leads/{interest}/assign', [FieldDispatchController::class, 'assignLead'])->whereNumber('interest')->name('field-sales.leads.assign');
        Route::post('field-sales/requests/{appointmentRequest}/assign', [FieldDispatchController::class, 'assignRequest'])->whereNumber('appointmentRequest')->name('field-sales.requests.assign');
        Route::post('field-sales/team/{rep}/permissions', [FieldTeamController::class, 'permission'])->whereNumber('rep')->name('field-sales.team.permission');
        Route::post('field-sales/team/{rep}/discount', [FieldTeamController::class, 'discount'])->whereNumber('rep')->name('field-sales.team.discount');
        Route::post('orders/{order}/sales-channel', OrderSalesChannelController::class)->whereNumber('order')->name('orders.sales-channel');
        Route::put('settings/field-sales', [FieldSalesSettingsController::class, 'update'])->name('rules.field_sales.update');
    });
});
