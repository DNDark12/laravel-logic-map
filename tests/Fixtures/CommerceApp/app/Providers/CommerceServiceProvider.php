<?php

namespace Fixtures\CommerceApp\Providers;

use Fixtures\CommerceApp\Console\Commands\ReconcileInventory;
use Fixtures\CommerceApp\Contracts\OrderGateway;
use Fixtures\CommerceApp\Events\OrderCancelled;
use Fixtures\CommerceApp\Listeners\RestockInventory;
use Fixtures\CommerceApp\Listeners\SendCancellationWebhook;
use Fixtures\CommerceApp\Models\Order;
use Fixtures\CommerceApp\Policies\OrderPolicy;
use Fixtures\CommerceApp\Repositories\DatabaseOrderGateway;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Console\Scheduling\Schedule;

final class CommerceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(OrderGateway::class, DatabaseOrderGateway::class);
        $this->commands([ReconcileInventory::class]);
        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
            $schedule->command('inventory:reconcile')->daily();
        });
    }

    public function boot(): void
    {
        Gate::policy(Order::class, OrderPolicy::class);
        Event::listen(OrderCancelled::class, RestockInventory::class);
        Event::listen(OrderCancelled::class, SendCancellationWebhook::class);
    }
}
