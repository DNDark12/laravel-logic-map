<?php

namespace Fixtures\CommerceApp\Http\Controllers;

use Fixtures\CommerceApp\Http\Requests\CancelOrderRequest;
use Fixtures\CommerceApp\Models\Order;
use Fixtures\CommerceApp\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

final class OrderController
{
    public function show(Order $order): View
    {
        return view('orders.show', ['order' => $order]);
    }

    public function cancel(
        CancelOrderRequest $request,
        Order $order,
        OrderService $orders,
    ): JsonResponse {
        Gate::authorize('cancel', $order);
        $orders->cancel($order, $request->string('reason')->toString());

        return response()->json(['ok' => true]);
    }
}
