<?php

namespace Fixtures\CommerceApp\Repositories;

use Fixtures\CommerceApp\Contracts\OrderGateway;
use Fixtures\CommerceApp\Models\Order;

final class DatabaseOrderGateway implements OrderGateway
{
    public function save(Order $order): void
    {
        $order->save();
    }
}
