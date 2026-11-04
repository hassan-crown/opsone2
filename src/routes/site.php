<?php

use App\Http\Controllers\Site\AppointmentRequestController;
use App\Http\Controllers\Site\LeadController;
use App\Http\Controllers\Site\MediaController;
use App\Http\Controllers\Site\PublicSiteController;
use App\Http\Controllers\Site\SeoController;
use App\Http\Controllers\Site\SitePreviewController;
use App\Http\Middleware\RefuseCustomerWritesWhenReadOnly;
use App\Http\Middleware\SiteLocale;
use App\Http\Middleware\SitePreviewMode;
use Illuminate\Support\Facades\Route;

/*
| The facility's public site (P8 part A, WEB-01..04, P-83 / P-72). Server-rendered pages in `data-surface="site"`,
| one URL per language (`/ar/...`, `/en/...`); `/` serves the home page in the default language. Only published
| versions are ever served. Reserved slugs (store, account…) cannot be taken by content pages.
*/

// Private preview of drafts (THM-04): a signed, expiring link sets a preview cookie for that browser only.
Route::get('site-preview/start', [SitePreviewController::class, 'start'])->middleware(['signed', 'throttle:30,1'])->name('site.preview.start');
Route::get('site-preview/exit', [SitePreviewController::class, 'exit'])->name('site.preview.exit');

Route::middleware([SiteLocale::class, SitePreviewMode::class])->group(function () {
    Route::get('/', [PublicSiteController::class, 'root'])->name('home');

    Route::get('sitemap.xml', [SeoController::class, 'sitemap'])->name('site.sitemap');
    Route::get('robots.txt', [SeoController::class, 'robots'])->name('site.robots');
    Route::get('site-media/{attachment:uuid}', [MediaController::class, 'public'])->name('site.media');

    Route::prefix('{locale}')->where(['locale' => 'ar|en'])->group(function () {
        Route::get('/', [PublicSiteController::class, 'show'])->name('site.home');
        Route::post('contact', [LeadController::class, 'store'])->middleware(['throttle:site-lead', RefuseCustomerWritesWhenReadOnly::class])->name('site.lead');
        Route::post('appointment-request', [AppointmentRequestController::class, 'store'])->middleware(['throttle:site-appointment', RefuseCustomerWritesWhenReadOnly::class])->name('site.appointment');
        Route::get('{slug}', [PublicSiteController::class, 'show'])->where('slug', '[a-z0-9][a-z0-9-]*')->name('site.page');
    });
});
