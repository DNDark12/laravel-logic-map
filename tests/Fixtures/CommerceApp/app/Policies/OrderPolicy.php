<?php

namespace Fixtures\CommerceApp\Policies;

use Fixtures\CommerceApp\Models\Order;
use Fixtures\CommerceApp\Models\User;

final class OrderPolicy
{
    public function cancel(User $user, Order $order): bool
    {
        return $order->canBeCancelled();
    }
}
