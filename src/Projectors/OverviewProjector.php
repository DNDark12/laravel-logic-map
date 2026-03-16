<?php

namespace dndark\LogicMap\Projectors;

use dndark\LogicMap\Contracts\GraphProjector;
use dndark\LogicMap\Domain\Graph;

class OverviewProjector implements GraphProjector
{
    public function overview(Graph $graph, array $filters = []): array
    {
        $nodes = $graph->getNodes();
        $edges = $graph->getEdges();

        // Filter and aggregate for overview
        // In Sprint 1, we return a limited set of nodes or aggregated clusters
        $limit = config('logic-map.overview_node_limit', 100);

        $outputNodes = array_slice($nodes, 0, $limit);
        $outputNodeIds = array_keys($outputNodes);

        $outputEdges = array_filter($edges, function ($edge) use ($outputNodeIds) {
            return in_array($edge->source, $outputNodeIds) && in_array($edge->target, $outputNodeIds);
        });

        return [
            'nodes' => array_map(fn($n) => $n->toArray(), array_values($outputNodes)),
            'edges' => array_map(fn($e) => $e->toArray(), array_values($outputEdges)),
            'meta' => [
                'total_nodes' => count($nodes),
                'total_edges' => count($edges),
                'limit_applied' => count($nodes) > $limit,
            ]
        ];
    }

    public function subgraph(Graph $graph, string $id, array $filters = []): array
    {
        // Not used in OverviewProjector but required by contract
        return [];
    }
}
