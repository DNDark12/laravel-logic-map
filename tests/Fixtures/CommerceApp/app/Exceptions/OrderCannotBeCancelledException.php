<?php

namespace Fixtures\CommerceApp\Exceptions;

use Fixtures\CommerceApp\Models\Order;
use RuntimeException;

final class OrderCannotBeCancelledException extends RuntimeException
{
    public function __construct(Order $order)
    {
        parent::__construct('Order '.(string) $order->getKey().' cannot be cancelled.');
    }
}
