<?php

use App\Http\Controllers\Audit\AuditLogController;
use App\Http\Controllers\Files\AttachmentDownloadController;
use Illuminate\Support\Facades\Route;

// Shared services, part A (ADR-0006): audit log (read-only) and private attachment downloads.
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('org/audit', [AuditLogController::class, 'index'])->name('audit.index');

    // Signed, short-lived, bound to the user; access to the owning record is re-checked on every download.
    Route::get('files/{attachment}', AttachmentDownloadController::class)
        ->middleware(['signed', 'throttle:60,1'])
        ->name('files.download');
});
