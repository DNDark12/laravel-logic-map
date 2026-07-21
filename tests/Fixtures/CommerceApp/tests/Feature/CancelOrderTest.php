<?php

namespace Fixtures\CommerceApp\Tests\Feature\Orders;

use Fixtures\CommerceApp\Events\OrderCancelled;
use Fixtures\CommerceApp\Jobs\ReconcileInventoryJob;
use Fixtures\CommerceApp\Services\OrderService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\TestCase;

final class CancelOrderTest extends TestCase
{
    public function test_cancel_order_flow(): void
    {
        route('orders.cancel', ['order' => 1]);
        $this->postJson('/orders/{order}/cancel');
        $this->assertDatabaseHas('orders', ['status' => 'cancelled']);
        $this->assertDatabaseMissing('orders', ['status' => 'pending']);

        Event::fake([OrderCancelled::class]);
        Event::assertDispatched(OrderCancelled::class);
        Bus::fake([ReconcileInventoryJob::class]);
        Bus::assertDispatched(ReconcileInventoryJob::class);

        (new OrderService($gateway))->cancel($order, 'fixture reference');
    }
}
