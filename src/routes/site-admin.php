<?php

use App\Http\Controllers\Site\DomainController;
use App\Http\Controllers\Site\MediaController;
use App\Http\Controllers\Site\SitePageController;
use App\Http\Controllers\Site\ThemeController;
use Illuminate\Support\Facades\Route;

/*
| Site administration (P8 part A): SitePages, PageEditor, ThemeEdit/ThemeApply and DomainVerify. Staff only; every
| action is authorized in its writer by the central Gate (P-55, P-56, P-100, P-101). Nothing deletes a page.
*/

Route::middleware(['auth', 'verified'])->prefix('site-admin')->name('site.admin.')->group(function () {
    Route::get('/', fn () => redirect('/site-admin/pages'))->name('home');

    Route::get('pages', [SitePageController::class, 'index'])->name('pages.index');
    Route::post('pages', [SitePageController::class, 'store'])->middleware('throttle:records-create')->name('pages.store');
    Route::get('pages/{page}', [SitePageController::class, 'edit'])->name('pages.edit');
    Route::put('pages/{page}', [SitePageController::class, 'update'])->name('pages.update');
    Route::post('pages/{page}/publish', [SitePageController::class, 'publish'])->name('pages.publish');
    Route::post('pages/{page}/unpublish', [SitePageController::class, 'unpublish'])->name('pages.unpublish');
    Route::post('pages/{page}/discard', [SitePageController::class, 'discard'])->name('pages.discard');
    Route::post('pages/{page}/archive', [SitePageController::class, 'archive'])->name('pages.archive');
    Route::post('pages/{page}/restore', [SitePageController::class, 'restore'])->name('pages.restore');
    Route::post('pages/{page}/images', [SitePageController::class, 'image'])->middleware('throttle:30,1')->name('pages.images');
    Route::get('pages/{page}/preview/{locale}', [SitePageController::class, 'preview'])->where('locale', 'ar|en')->name('pages.preview');

    Route::get('theme', [ThemeController::class, 'edit'])->name('theme.edit');
    Route::post('theme/preview', [ThemeController::class, 'preview'])->name('theme.preview');
    Route::put('theme', [ThemeController::class, 'save'])->name('theme.save');
    Route::post('theme/assets/{kind}', [ThemeController::class, 'upload'])->where('kind', 'logo|logo_dark|favicon')->middleware('throttle:30,1')->name('theme.upload');
    Route::delete('theme/assets/{kind}', [ThemeController::class, 'removeAsset'])->where('kind', 'logo|logo_dark|favicon')->name('theme.remove-asset');
    Route::post('theme/apply', [ThemeController::class, 'apply'])->name('theme.apply');
    Route::post('theme/discard', [ThemeController::class, 'discard'])->name('theme.discard');
    Route::post('theme/restore', [ThemeController::class, 'restore'])->name('theme.restore');

    Route::get('domain', [DomainController::class, 'show'])->name('domain.show');
    Route::post('domain', [DomainController::class, 'store'])->name('domain.store');
    Route::post('domain/check', [DomainController::class, 'check'])->middleware('throttle:10,1')->name('domain.check');
    Route::delete('domain', [DomainController::class, 'destroy'])->name('domain.destroy');

    Route::get('media/{attachment:uuid}', [MediaController::class, 'admin'])->name('media');
});
