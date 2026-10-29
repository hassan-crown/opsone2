<?php

use App\Http\Controllers\Reports\ReportExportController;
use App\Http\Controllers\Reports\ReportsController;
use Illuminate\Support\Facades\Route;

/*
| Reports and exports (P9, domain 09: SHR-12, SHR-13, SHR-15; P-60, P-61, P-62). The P6b ReportCollection screen
| (`reports/collection`, routes/finance.php) stays the collection report's page; its numbers come from the same
| metrics layer. Contract: docs/p9-metrics.md.
*/

Route::middleware(['auth', 'verified'])->prefix('reports')->group(function () {
    Route::get('/', [ReportsController::class, 'index'])->name('reports.index');
    Route::get('compare', [ReportsController::class, 'compare'])->name('reports.compare');

    Route::get('exports', [ReportExportController::class, 'index'])->name('reports.exports.index');
    Route::post('exports', [ReportExportController::class, 'store'])->middleware('throttle:report-exports')->name('reports.exports.store');
    Route::post('exports/{export}/link', [ReportExportController::class, 'link'])->whereUuid('export')->middleware('throttle:60,1')->name('reports.exports.link');
    Route::get('exports/{export}/download', [ReportExportController::class, 'download'])->whereUuid('export')->middleware(['signed', 'throttle:30,1'])->name('reports.exports.download');

    Route::get('{report}', [ReportsController::class, 'show'])
        ->where('report', 'sales|refunds|commissions|workflow|appointments|inventory|procurement|returns|store')->name('reports.show');
});
