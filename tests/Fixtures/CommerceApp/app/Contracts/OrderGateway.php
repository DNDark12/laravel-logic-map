<?php

namespace Fixtures\CommerceApp\Contracts;

use Fixtures\CommerceApp\Models\Order;

interface OrderGateway
{
    public function save(Order $order): void;
}
