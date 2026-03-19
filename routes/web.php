<?php

use dndark\LogicMap\Http\Controllers\LogicMapController;
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
    Route::get('/export/graph', [LogicMapController::class, 'exportGraph'])->name('logic-map.export.graph');
    Route::get('/export/analysis', [LogicMapController::class, 'exportAnalysis'])->name('logic-map.export.analysis');
    Route::get('/export/bundle', [LogicMapController::class, 'exportBundle'])->name('logic-map.export.bundle');
    Route::get('/export/json', [LogicMapController::class, 'exportJson'])->name('logic-map.export.json');
    Route::get('/export/csv', [LogicMapController::class, 'exportCsv'])->name('logic-map.export.csv');
});
