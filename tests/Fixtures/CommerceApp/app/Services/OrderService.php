<?php

namespace Fixtures\CommerceApp\Services;

use Fixtures\CommerceApp\Contracts\OrderGateway;
use Fixtures\CommerceApp\Events\OrderCancelled;
use Fixtures\CommerceApp\Exceptions\OrderCannotBeCancelledException;
use Fixtures\CommerceApp\Models\InventoryStock;
use Fixtures\CommerceApp\Models\Order;
use Fixtures\CommerceApp\Notifications\OrderWasCancelled;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class OrderService
{
    public function __construct(private readonly OrderGateway $orders)
    {
    }

    public function cancel(Order $order, string $reason): void
    {
        if (! $order->canBeCancelled()) {
            throw new OrderCannotBeCancelledException($order);
        }

        DB::transaction(function () use ($order, $reason): void {
            $order->status = 'cancelled';
            $order->cancellation_reason = $reason;
            $this->orders->save($order);

            InventoryStock::query()
                ->where('order_id', $order->getKey())
                ->increment('quantity');
        });

        OrderCancelled::dispatch($order);
        Cache::forget("order-summary:{$order->getKey()}");
        $order->user->notify(new OrderWasCancelled($order));
    }
}
