<?php

namespace Fixtures\CommerceApp\Console\Commands;

use Fixtures\CommerceApp\Jobs\ReconcileInventoryJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;

final class ReconcileInventory extends Command
{
    protected $signature = 'inventory:reconcile';

    protected $description = 'Reconcile inventory totals for the semantic fixture';

    public function handle(): int
    {
        Bus::dispatchSync(new ReconcileInventoryJob());

        return self::SUCCESS;
    }
}
