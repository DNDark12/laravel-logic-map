<?php

use DNDark\LogicMap\Http\Controllers\LogicMapV2Controller;
use DNDark\LogicMap\Http\Middleware\EnsureLogicMapEnabled;
use DNDark\LogicMap\Services\Query\ApiResult;
use Illuminate\Support\Facades\Route;

$middleware = [
    ...(array) config('logic-map.http.middleware', ['web']),
    EnsureLogicMapEnabled::class,
];

Route::get('logic-map', static fn () => view('logic-map::app'))
    ->middleware($middleware)
    ->name('logic-map.viewer');

Route::prefix('logic-map/api')->middleware($middleware)->group(function (): void {
    Route::get('status', [LogicMapV2Controller::class, 'status'])->name('logic-map.status');
    Route::get('symbols/search', [LogicMapV2Controller::class, 'search'])->name('logic-map.symbols.search');
    Route::get('symbols/{id}/context', [LogicMapV2Controller::class, 'context'])
        ->where('id', '[A-Za-z0-9_-]+')
        ->name('logic-map.symbols.context');
    Route::get('workflows/{id}', [LogicMapV2Controller::class, 'workflow'])
        ->where('id', '[A-Za-z0-9_-]+')
        ->name('logic-map.workflows.show');
    Route::post('impact', [LogicMapV2Controller::class, 'impact'])->name('logic-map.impact');
    Route::get('modules', [LogicMapV2Controller::class, 'modules'])->name('logic-map.modules.index');
    Route::get('modules/{id}', [LogicMapV2Controller::class, 'module'])
        ->where('id', '[A-Za-z0-9_-]+')
        ->name('logic-map.modules.show');
    Route::any('{unmatched}', static fn () => ApiResult::failure(
        'The requested Laravel Logic Map API route was not found.',
        ['code' => 'route_not_found'],
        404,
    )->toResponse())->where('unmatched', '.*');
});
