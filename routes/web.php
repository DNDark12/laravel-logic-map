<?php

use dndark\LogicMap\Http\Controllers\LogicMapController;
use dndark\LogicMap\Http\Controllers\ReportController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web'])->prefix('logic-map')->group(function () {
    Route::get('/', [LogicMapController::class, 'index'])->name('logic-map.index');
    Route::get('/overview', [LogicMapController::class, 'overview'])->name('logic-map.overview');
    Route::get('/subgraph/{id}', [LogicMapController::class, 'subgraph'])
        ->where('id', '.*')
        ->name('logic-map.subgraph');
    Route::get('/search', [LogicMapController::class, 'search'])->name('logic-map.search');
    Route::get('/meta', [LogicMapController::class, 'meta'])->name('logic-map.meta');
    Route::get('/snapshots', [LogicMapController::class, 'snapshots'])->name('logic-map.snapshots');
    Route::get('/diff', [LogicMapController::class, 'diff'])->name('logic-map.diff');
    Route::get('/violations', [LogicMapController::class, 'violations'])->name('logic-map.violations');
    Route::get('/health', [LogicMapController::class, 'health'])->name('logic-map.health');
    Route::get('/hotspots', [LogicMapController::class, 'hotspots'])->name('logic-map.hotspots');
    Route::get('/impact/{id}', [LogicMapController::class, 'impact'])
        ->where('id', '.*')
        ->name('logic-map.impact');
    Route::get('/trace/{id}', [LogicMapController::class, 'trace'])
        ->where('id', '.*')
        ->name('logic-map.trace');

    Route::get('/reports/impact/{id}', [ReportController::class, 'impactView'])
        ->where('id', '.*')
        ->name('logic-map.report.impact');
    Route::get('/reports/impact/{id}/download', [ReportController::class, 'impactDownload'])
        ->where('id', '.*')
        ->name('logic-map.report.download.impact');
    Route::get('/reports/impact/{id}/download-json', [ReportController::class, 'impactDownloadJson'])
        ->where('id', '.*')
        ->name('logic-map.report.download.json.impact');
    Route::get('/reports/trace/{id}', [ReportController::class, 'traceView'])
        ->where('id', '.*')
        ->name('logic-map.report.trace');
    Route::get('/reports/trace/{id}/download', [ReportController::class, 'traceDownload'])
        ->where('id', '.*')
        ->name('logic-map.report.download.trace');
    Route::get('/reports/trace/{id}/download-json', [ReportController::class, 'traceDownloadJson'])
        ->where('id', '.*')
        ->name('logic-map.report.download.json.trace');
    Route::get('/export/graph', [LogicMapController::class, 'exportGraph'])->name('logic-map.export.graph');
    Route::get('/export/analysis', [LogicMapController::class, 'exportAnalysis'])->name('logic-map.export.analysis');
    Route::get('/export/bundle', [LogicMapController::class, 'exportBundle'])->name('logic-map.export.bundle');
    Route::get('/export/json', [LogicMapController::class, 'exportJson'])->name('logic-map.export.json');
    Route::get('/export/csv', [LogicMapController::class, 'exportCsv'])->name('logic-map.export.csv');

    Route::post('/reports/impact/{id}/save-markdown', [ReportController::class, 'saveImpactMarkdown'])
        ->where('id', '.*')
        ->name('logic-map.report.save.impact');
    Route::post('/reports/trace/{id}/save-markdown', [ReportController::class, 'saveTraceMarkdown'])
        ->where('id', '.*')
        ->name('logic-map.report.save.trace');
});
