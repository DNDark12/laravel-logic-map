<?php

namespace DNDark\LogicMap\Services\Indexing;

use DNDark\LogicMap\Domain\Snapshot\GraphSnapshot;

final readonly class IndexResult
{
    public function __construct(
        public GraphSnapshot $snapshot,
        public bool $reused,
    ) {
    }

    public function nodeCount(): int
    {
        return count($this->snapshot->graph->nodes());
    }

    public function edgeCount(): int
    {
        return count($this->snapshot->graph->edges());
    }

    public function evidenceCount(): int
    {
        return count($this->snapshot->graph->evidence());
    }

    public function diagnosticCount(): int
    {
        return count($this->snapshot->diagnostics);
    }
}
