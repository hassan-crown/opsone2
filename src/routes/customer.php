<?php

use App\Http\Controllers\Portal\ActivationController;
use App\Http\Controllers\Portal\AppointmentController;
use App\Http\Controllers\Portal\CancellationController;
use App\Http\Controllers\Portal\DocumentController;
use App\Http\Controllers\Portal\FileController;
use App\Http\Controllers\Portal\PaymentController;
use App\Http\Controllers\Portal\SettingsController;
use App\Http\Controllers\Portal\SignInController;
use App\Http\Controllers\Portal\WorkspaceController;
use App\Http\Middleware\Portal\AuthenticateCustomer;
use App\Http\Middleware\Portal\RedirectSignedInCustomer;
use Illuminate\Support\Facades\Route;

/*
| Customer workspace (P8 part B, domain 07, ADR-0022). Loaded by PortalServiceProvider under `/portal`, names
| `customer.*`, middleware group `customer` (own session cookie, never the staff session or the staff Gate).
| Every record is looked up through App\Portal\PortalAccess, scoped by the signed-in customer: others' ids are 404.
*/

// Language for signed-in and signed-out customers alike (cookie, plus the account's preference when signed in).
Route::put('locale', [SettingsController::class, 'locale'])->name('locale');

Route::middleware(RedirectSignedInCustomer::class)->group(function () {
    Route::get('login', [SignInController::class, 'show'])->name('login');
    Route::post('login', [SignInController::class, 'send'])->middleware('throttle:portal-otp')->name('login.send');
    Route::post('login/resend', [SignInController::class, 'resend'])->middleware('throttle:portal-otp')->name('login.resend');
    Route::get('login/verify', [SignInController::class, 'showVerify'])->name('login.verify');
    Route::post('login/verify', [SignInController::class, 'verify'])->middleware('throttle:portal-otp')->name('login.verify.store');

    // ActivateAccount: the signed link staff sent (PortalInvitation); a code is still required.
    Route::get('activate/{customer}', [ActivationController::class, 'show'])->middleware('signed')->name('activate');
    Route::post('activate/{customer}', [ActivationController::class, 'send'])->middleware(['signed', 'throttle:portal-otp'])->name('activate.send');
});

Route::middleware(AuthenticateCustomer::class)->group(function () {
    Route::get('/', [WorkspaceController::class, 'home'])->name('home');
    Route::get('orders', [WorkspaceController::class, 'orders'])->name('orders.index');
    Route::get('orders/{order}', [WorkspaceController::class, 'order'])->name('orders.show');
    Route::get('notifications', [WorkspaceController::class, 'notifications'])->name('notifications');

    Route::get('documents', [DocumentController::class, 'index'])->name('documents.index');
    Route::get('documents/{document}', [DocumentController::class, 'show'])->name('documents.show');
    Route::post('documents/{document}/approve', [DocumentController::class, 'approve'])->name('documents.approve');
    Route::post('documents/{document}/changes', [DocumentController::class, 'requestChanges'])->name('documents.changes');

    Route::get('orders/{order}/pay', [PaymentController::class, 'show'])->name('orders.pay');
    Route::post('orders/{order}/proofs', [PaymentController::class, 'store'])->middleware('throttle:records-create')->name('proofs.store');
    Route::post('proofs/{proof}/resubmit', [PaymentController::class, 'resubmit'])->middleware('throttle:records-create')->name('proofs.resubmit');
    Route::post('proofs/{proof}/withdraw', [PaymentController::class, 'withdraw'])->name('proofs.withdraw');

    Route::post('appointments/{appointment}/confirm', [AppointmentController::class, 'confirm'])->name('appointments.confirm');
    Route::post('appointments/{appointment}/change', [AppointmentController::class, 'requestChange'])->name('appointments.change');

    Route::get('orders/{order}/cancel', [CancellationController::class, 'create'])->name('orders.cancel');
    Route::post('orders/{order}/cancellation', [CancellationController::class, 'store'])->name('cancellations.store');
    Route::post('cancellations/{cancellation}/withdraw', [CancellationController::class, 'withdraw'])->name('cancellations.withdraw');

    Route::get('settings', [SettingsController::class, 'show'])->name('settings');
    Route::post('logout', [SignInController::class, 'logout'])->name('logout');
    Route::post('logout/everywhere', [SignInController::class, 'logoutEverywhere'])->name('logout.everywhere');

    Route::get('files/{attachment}', FileController::class)->middleware(['signed', 'throttle:60,1'])->name('files.download');
});
