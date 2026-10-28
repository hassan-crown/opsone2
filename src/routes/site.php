<?php

use App\Http\Controllers\Site\LeadController;
use App\Http\Controllers\Site\MediaController;
use App\Http\Controllers\Site\PublicSiteController;
use App\Http\Controllers\Site\SeoController;
use App\Http\Middleware\SiteLocale;
use Illuminate\Support\Facades\Route;

/*
| The facility's public site (P8 part A, WEB-01..04, P-83 / P-72). Server-rendered pages in `data-surface="site"`,
| one URL per language (`/ar/...`, `/en/...`); `/` serves the home page in the default language. Only published
| versions are ever served. Reserved slugs (store, account…) cannot be taken by content pages.
*/

Route::middleware(SiteLocale::class)->group(function () {
    Route::get('/', [PublicSiteController::class, 'root'])->name('home');

    Route::get('sitemap.xml', [SeoController::class, 'sitemap'])->name('site.sitemap');
    Route::get('robots.txt', [SeoController::class, 'robots'])->name('site.robots');
    Route::get('site-media/{attachment:uuid}', [MediaController::class, 'public'])->name('site.media');

    Route::prefix('{locale}')->where(['locale' => 'ar|en'])->group(function () {
        Route::get('/', [PublicSiteController::class, 'show'])->name('site.home');
        Route::post('contact', [LeadController::class, 'store'])->middleware('throttle:site-lead')->name('site.lead');
        Route::get('{slug}', [PublicSiteController::class, 'show'])->where('slug', '[a-z0-9][a-z0-9-]*')->name('site.page');
    });
});
