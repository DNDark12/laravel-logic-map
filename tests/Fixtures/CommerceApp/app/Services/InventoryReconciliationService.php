<?php

namespace Fixtures\CommerceApp\Services;

use Fixtures\CommerceApp\Models\InventoryStock;

final class InventoryReconciliationService
{
    public function totalQuantity(): int
    {
        return (int) InventoryStock::query()->value('quantity');
    }
}
