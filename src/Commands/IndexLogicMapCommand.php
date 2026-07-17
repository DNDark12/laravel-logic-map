<?php

namespace DNDark\LogicMap\Commands;

use DNDark\LogicMap\Services\Indexing\IndexLogicMapService;
use DNDark\LogicMap\Services\Indexing\IndexOptions;
use Illuminate\Console\Command;
use Throwable;

final class IndexLogicMapCommand extends Command
{
    protected $signature = 'logic-map:index
                            {--force : Re-run analysis even when the active fingerprint matches}
                            {--no-boot : Skip safe Laravel boot fact collection}';

    protected $description = 'Build and activate a Laravel Logic Map V2 semantic snapshot';

    public function handle(IndexLogicMapService $service): int
    {
        try {
            $result = $service->index(new IndexOptions(
                (array) config('logic-map.scan_paths', []),
                (array) config('logic-map.excludes', []),
                (bool) $this->option('force'),
                ! (bool) $this->option('no-boot'),
            ));
        } catch (Throwable $throwable) {
            $this->error('Index failed: '.$throwable->getMessage());

            return self::FAILURE;
        }

        $this->line('Snapshot: '.$result->snapshot->id);
        $this->line('Status: '.($result->reused ? 'reused' : 'created'));
        $this->line('Nodes: '.$result->nodeCount());
        $this->line('Edges: '.$result->edgeCount());
        $this->line('Evidence: '.$result->evidenceCount());
        $this->line('Diagnostics: '.$result->diagnosticCount());

        return self::SUCCESS;
    }
}
