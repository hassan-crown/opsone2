<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\LocaleController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::put('locale', [LocaleController::class, 'update'])->name('locale.update');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
});

require __DIR__.'/settings.php';
require __DIR__.'/org.php';
require __DIR__.'/account.php';
require __DIR__.'/shared-audit.php';
require __DIR__.'/notifications.php';
require __DIR__.'/help.php';
require __DIR__.'/support-access.php';
require __DIR__.'/customers.php';
require __DIR__.'/catalog.php';
require __DIR__.'/orders.php';
require __DIR__.'/flow.php';
require __DIR__.'/tasks.php';
require __DIR__.'/calendar.php';
require __DIR__.'/agreements.php';
require __DIR__.'/finance.php';
require __DIR__.'/commissions.php';

if (app()->isLocal()) {
    Route::inertia('_design', 'design')->name('design');
}
