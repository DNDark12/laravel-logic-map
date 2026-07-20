<?php

namespace DNDark\LogicMap\Commands;

use DNDark\LogicMap\Services\Indexing\ClearLogicMapService;
use Illuminate\Console\Command;

final class ClearLogicMapCommand extends Command
{
    protected $signature = 'logic-map:clear {--force : Clear without interactive confirmation}';

    protected $description = 'Clear Laravel Logic Map V2 snapshots and runtime evidence';

    public function handle(ClearLogicMapService $service): int
    {
        if (! (bool) $this->option('force')) {
            if (! $this->input->isInteractive()) {
                $this->error('Refusing to clear non-interactively without --force.');

                return self::FAILURE;
            }

            if (! $this->confirm('Clear the Laravel Logic Map index and runtime evidence?')) {
                $this->warn('Clear cancelled.');

                return self::FAILURE;
            }
        }

        $service->clear();
        $this->info('Laravel Logic Map V2 store cleared.');

        return self::SUCCESS;
    }
}
