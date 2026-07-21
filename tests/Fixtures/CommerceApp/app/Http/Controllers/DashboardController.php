<?php

namespace Fixtures\CommerceApp\Http\Controllers;

use Fixtures\CommerceApp\Services\SalesDashboardService;
use Illuminate\Http\JsonResponse;

final class DashboardController
{
    public function sales(SalesDashboardService $sales): JsonResponse
    {
        return response()->json(['cancelled_orders' => $sales->cancelledOrderCount()]);
    }
}
