<?php

namespace Fixtures\CommerceApp\Listeners;

use Fixtures\CommerceApp\Events\OrderCancelled;

final class RestockInventory
{
    public function handle(OrderCancelled $event): void
    {
        $event->order->getKey();
    }
}
