<?php

use Fixtures\CommerceApp\Http\Controllers\DashboardController;
use Fixtures\CommerceApp\Http\Controllers\OrderController;
use Fixtures\CommerceApp\Http\Controllers\ShippingController;
use Illuminate\Support\Facades\Route;

Route::get('/orders/{order}', [OrderController::class, 'show'])
    ->name('orders.show');

Route::post('/orders/{order}/cancel', [OrderController::class, 'cancel'])
    ->middleware(['auth', 'throttle:orders'])
    ->name('orders.cancel');

Route::post('/orders/{order}/ship', [ShippingController::class, 'ship'])
    ->name('orders.ship');

Route::get('/dashboard/sales', [DashboardController::class, 'sales'])
    ->name('dashboard.sales');
