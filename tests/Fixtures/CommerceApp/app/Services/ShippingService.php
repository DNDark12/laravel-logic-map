<?php

namespace Fixtures\CommerceApp\Services;

use Fixtures\CommerceApp\Models\Order;

final class ShippingService
{
    public function canShip(Order $order): bool
    {
        return $order->status !== 'cancelled';
    }
}
