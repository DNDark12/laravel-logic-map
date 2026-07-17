<?php

namespace Fixtures\CommerceApp\Jobs;

use Fixtures\CommerceApp\Services\InventoryReconciliationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class ReconcileInventoryJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function handle(InventoryReconciliationService $inventory): void
    {
        $inventory->totalQuantity();
    }
}
