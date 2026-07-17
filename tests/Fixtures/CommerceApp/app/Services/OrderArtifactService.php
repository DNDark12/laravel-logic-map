<?php

namespace Fixtures\CommerceApp\Services;

use Fixtures\CommerceApp\Models\Order;
use Illuminate\Support\Facades\Storage;

final class OrderArtifactService
{
    public function writeAudit(Order $order): void
    {
        $disk = config('logic-map.fixture.audit_disk');

        Storage::disk($disk)->put(
            "orders/{$order->getKey()}/audit.json",
            json_encode(['order_id' => $order->getKey()], JSON_THROW_ON_ERROR),
        );
    }
}
