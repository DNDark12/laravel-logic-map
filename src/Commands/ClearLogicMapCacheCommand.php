<?php

namespace dndark\LogicMap\Commands;

use Illuminate\Console\Command;
use dndark\LogicMap\Contracts\GraphRepository;

class ClearLogicMapCacheCommand extends Command
{
    protected $signature = 'logic-map:clear-cache';

    protected $description = 'Clear all cached logic map snapshots and projections';

    public function handle(GraphRepository $repository): int
    {
        $this->info('Clearing logic map cache...');

        $cleared = $repository->clear();

        if ($cleared > 0) {
            $this->info("Cleared {$cleared} cached snapshot(s).");
        } else {
            $this->warn('No cached snapshots found to clear.');
        }

        $this->info('Logic map cache cleared successfully.');

        return self::SUCCESS;
    }
}
