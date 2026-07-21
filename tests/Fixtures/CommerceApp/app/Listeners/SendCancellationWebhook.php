<?php

namespace Fixtures\CommerceApp\Listeners;

use Fixtures\CommerceApp\Events\OrderCancelled;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Http;

final class SendCancellationWebhook implements ShouldQueue
{
    public function handle(OrderCancelled $event): void
    {
        Http::post(
            config('services.erp.base_url').'/orders/'.$event->order->getKey().'/cancel',
        );
    }
}
