<?php

namespace Fixtures\CommerceApp\Services;

use Fixtures\CommerceApp\Models\Order;

final class SalesDashboardService
{
    public function cancelledOrderCount(): int
    {
        return Order::query()->where('status', 'cancelled')->count();
    }
}
