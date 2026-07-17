<?php

namespace Fixtures\CommerceApp\Http\Controllers;

use Fixtures\CommerceApp\Models\Order;
use Fixtures\CommerceApp\Services\ShippingService;
use Illuminate\Http\JsonResponse;

final class ShippingController
{
    public function ship(Order $order, ShippingService $shipping): JsonResponse
    {
        return response()->json(['shippable' => $shipping->canShip($order)]);
    }
}
