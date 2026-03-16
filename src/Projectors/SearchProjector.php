<?php

namespace dndark\LogicMap\Projectors;

use dndark\LogicMap\Contracts\GraphProjector;
use dndark\LogicMap\Domain\Graph;
use dndark\LogicMap\Domain\Node;

class SearchProjector implements GraphProjector
{
    public function overview(Graph $graph, array $filters = []): array
    {
        return [];
    }

    public function subgraph(Graph $graph, string $id, array $filters = []): array
    {
        return [];
    }

    public function search(Graph $graph, string $query, array $filters = []): array
    {
        $nodes = $graph->getNodes();
        $query = strtolower(trim($query));
        $limit = $filters['limit'] ?? config('logic-map.overview_node_limit', 50);

        // Parse filter options
        $kindFilter = $filters['kind'] ?? null;
        $scopeFilter = $filters['scope'] ?? null;

        if ($query === '' && !$kindFilter && !$scopeFilter) {
            // Return all nodes up to limit if no query
            return [
                'nodes' => array_slice(
                    array_map(fn(Node $n) => $n->toArray(), array_values($nodes)),
                    0,
                    $limit
                ),
                'edges' => [],
                'meta' => [
                    'query' => $query,
                    'total_matches' => count($nodes),
                    'limit_applied' => count($nodes) > $limit,
                ],
            ];
        }

        $matches = [];
        $totalMatches = 0;

        foreach ($nodes as $node) {
            // Apply kind filter
            if ($kindFilter && $node->kind->value !== $kindFilter) {
                continue;
            }

            // Apply scope filter
            if ($scopeFilter && $node->scope !== $scopeFilter) {
                continue;
            }

            // Match query against name and id
            $matchesQuery = $query === '' ||
                str_contains(strtolower($node->name ?? ''), $query) ||
                str_contains(strtolower($node->id), $query);

            if ($matchesQuery) {
                $totalMatches++;
                if (count($matches) < $limit) {
                    $matches[] = $node->toArray();
                }
            }
        }

        return [
            'nodes' => $matches,
            'edges' => [],
            'meta' => [
                'query' => $query,
                'total_matches' => $totalMatches,
                'limit_applied' => $totalMatches > $limit,
                'filters' => array_filter([
                    'kind' => $kindFilter,
                    'scope' => $scopeFilter,
                ]),
            ],
        ];
    }
}
