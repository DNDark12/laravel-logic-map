<?php

namespace dndark\LogicMap\Projectors;

use DNDark\LogicMap\Contracts\GraphProjector;
use DNDark\LogicMap\Domain\Edge;
use DNDark\LogicMap\Domain\Graph;
use DNDark\LogicMap\Domain\Node;

class SubgraphProjector implements GraphProjector
{
    public function overview(Graph $graph, array $filters = []): array
    {
        return [];
    }

    public function subgraph(Graph $graph, string $id, array $filters = []): array
    {
        $nodes = $graph->getNodes();
        $edges = $graph->getEdges();

        if (!isset($nodes[$id])) {
            return ['nodes' => [], 'edges' => [], 'meta' => ['focus_id' => $id, 'found' => false]];
        }

        $limit = $filters['limit'] ?? config('logic-map.subgraph_node_limit', 50);
        $depth = $filters['depth'] ?? 1;
        $minConfidence = $this->parseConfidence($filters['min_confidence'] ?? 'low');
        $excludeKinds = $filters['exclude_kinds'] ?? config('logic-map.filters.excluded_kinds', []);

        $neighborhoodNodes = [$id => $nodes[$id]];
        $neighborhoodEdges = [];
        $frontier = [$id];
        $visited = [$id => true];

        // BFS to specified depth
        for ($d = 0; $d < $depth && count($frontier) > 0 && count($neighborhoodNodes) < $limit; $d++) {
            $nextFrontier = [];

            foreach ($edges as $edge) {
                if (count($neighborhoodNodes) >= $limit) {
                    break;
                }

                // Filter by confidence
                if ($edge->confidence->value < $minConfidence) {
                    continue;
                }

                $sourceInFrontier = in_array($edge->source, $frontier);
                $targetInFrontier = in_array($edge->target, $frontier);

                if ($sourceInFrontier || $targetInFrontier) {
                    // Get the neighbor node ID
                    $neighborId = $sourceInFrontier ? $edge->target : $edge->source;

                    // Add edge if both ends will be in the neighborhood
                    if (isset($neighborhoodNodes[$neighborId]) ||
                        (isset($nodes[$neighborId]) && count($neighborhoodNodes) < $limit)) {

                        $neighborhoodEdges[$edge->source . '->' . $edge->target] = $edge;
                    }

                    // Add neighbor node if exists and passes filters
                    if (isset($nodes[$neighborId]) && !isset($visited[$neighborId])) {
                        $neighborNode = $nodes[$neighborId];

                        // Filter by excluded kinds
                        if (in_array($neighborNode->kind->value, $excludeKinds)) {
                            continue;
                        }

                        if (count($neighborhoodNodes) < $limit) {
                            $neighborhoodNodes[$neighborId] = $neighborNode;
                            $visited[$neighborId] = true;
                            $nextFrontier[] = $neighborId;
                        }
                    }
                }
            }

            $frontier = $nextFrontier;
        }

        // Also include intra-neighborhood edges
        foreach ($edges as $edge) {
            $key = $edge->source . '->' . $edge->target;
            if (!isset($neighborhoodEdges[$key]) &&
                isset($neighborhoodNodes[$edge->source]) &&
                isset($neighborhoodNodes[$edge->target])) {

                if ($edge->confidence->value >= $minConfidence) {
                    $neighborhoodEdges[$key] = $edge;
                }
            }
        }

        return [
            'nodes' => array_map(fn(Node $n) => $n->toArray(), array_values($neighborhoodNodes)),
            'edges' => array_map(fn(Edge $e) => $e->toArray(), array_values($neighborhoodEdges)),
            'meta' => [
                'focus_id' => $id,
                'found' => true,
                'depth' => $depth,
                'limit_applied' => count($neighborhoodNodes) >= $limit,
                'node_count' => count($neighborhoodNodes),
                'edge_count' => count($neighborhoodEdges),
            ],
        ];
    }

    protected function parseConfidence(string $value): string
    {
        $levels = ['low' => 'low', 'medium' => 'medium', 'high' => 'high'];
        return $levels[$value] ?? 'low';
    }
}
