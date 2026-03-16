<?php

namespace dndark\LogicMap\Analysis\Resolvers;

use dndark\LogicMap\Domain\Graph;
use dndark\LogicMap\Domain\Edge;
use dndark\LogicMap\Domain\Enums\EdgeType;
use dndark\LogicMap\Domain\Enums\Confidence;

class CallEdgeResolver
{
    public function __construct(protected Graph $graph) {}

    public function resolveCall(string $sourceId, string $targetClass, ?string $targetMethod = null)
    {
        $targetId = $targetMethod ? "method:{$targetClass}@{$targetMethod}" : "class:{$targetClass}";

        $edge = new Edge(
            source: $sourceId,
            target: $targetId,
            type: EdgeType::CALL,
            confidence: Confidence::MEDIUM, // Calls detected via AST are usually medium until verified
        );

        $this->graph->addEdge($edge);
    }
}
