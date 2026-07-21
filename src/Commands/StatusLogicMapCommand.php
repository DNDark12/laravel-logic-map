<?php

namespace DNDark\LogicMap\Commands;

use DNDark\LogicMap\Services\Query\LogicMapStatusService;
use Illuminate\Console\Command;

final class StatusLogicMapCommand extends Command
{
    protected $signature = 'logic-map:status';

    protected $description = 'Show the active Laravel Logic Map V2 snapshot status';

    public function handle(LogicMapStatusService $service): int
    {
        $status = $service->status();

        if (! $status['active']) {
            $this->warn('No active Laravel Logic Map index exists.');

            return self::FAILURE;
        }

        $this->line('Snapshot: '.$status['snapshot']['id']);
        $this->line('Analysis: '.$status['snapshot']['analysis_version']);
        $this->line('Schema: '.$status['snapshot']['schema_version']);
        $this->line('Fingerprint: '.$status['snapshot']['fingerprint']);
        $this->line('Indexed: '.$status['snapshot']['indexed_at']);
        $this->line('Stale: '.($status['snapshot']['stale'] ? 'yes' : 'no'));
        $this->line('Nodes: '.$status['counts']['nodes']);
        $this->line('Edges: '.$status['counts']['edges']);
        $this->line('Evidence: '.$status['counts']['evidence']);
        $this->line('Diagnostics: '.$status['counts']['diagnostics']);

        return self::SUCCESS;
    }
}
