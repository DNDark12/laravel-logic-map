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
        return $this->snapshot->graph->countNodes();
    }

    public function edgeCount(): int
    {
        return $this->snapshot->graph->countEdges();
    }

    public function evidenceCount(): int
    {
        return $this->snapshot->graph->countEvidence();
    }

    public function diagnosticCount(): int
    {
        return count($this->snapshot->diagnostics);
    }
}
