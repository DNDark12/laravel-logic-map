<?php

namespace dndark\LogicMap\Projectors;

use dndark\LogicMap\Analysis\Support\ModuleExtractor;
use dndark\LogicMap\Contracts\GraphProjector;
use dndark\LogicMap\Domain\Edge;
use dndark\LogicMap\Domain\Graph;
use dndark\LogicMap\Domain\Node;

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

        $crossModuleEdges = $this->buildCrossModuleEdges($outputNodes, $outputEdges);

        return [
            'nodes' => array_map(fn($n) => $n->toArray(), array_values($outputNodes)),
            'edges' => array_map(fn($e) => $e->toArray(), array_values($outputEdges)),
            'meta' => [
                'total_nodes' => count($nodes),
                'total_edges' => count($edges),
                'limit_applied' => count($nodes) > $limit,
                'cross_module_edges' => $crossModuleEdges,
            ]
        ];
    }

    public function subgraph(Graph $graph, string $id, array $filters = []): array
    {
        // Not used in OverviewProjector but required by contract
        return [];
    }

    /**
     * @param array<string, Node> $nodes
     * @param array<int, Edge> $edges
     * @return array<int, array{source_module: string, target_module: string, count: int}>
     */
    protected function buildCrossModuleEdges(array $nodes, array $edges): array
    {
        $moduleByNode = [];
        foreach ($nodes as $id => $node) {
            $moduleByNode[$id] = $node->metadata['module'] ?? ModuleExtractor::moduleOf($node->id);
        }

        $counts = [];
        foreach ($edges as $edge) {
            $sourceModule = $moduleByNode[$edge->source] ?? ModuleExtractor::moduleOf($edge->source);
            $targetModule = $moduleByNode[$edge->target] ?? ModuleExtractor::moduleOf($edge->target);

            if ($sourceModule === '' || $targetModule === '' || $sourceModule === $targetModule) {
                continue;
            }

            $key = $sourceModule . '>>>' . $targetModule;
            $counts[$key] = [
                'source_module' => $sourceModule,
                'target_module' => $targetModule,
                'count' => ($counts[$key]['count'] ?? 0) + 1,
            ];
        }

        $result = array_values($counts);
        usort($result, function (array $a, array $b): int {
            if ($a['count'] !== $b['count']) {
                return $b['count'] <=> $a['count'];
            }

            return ($a['source_module'] . $a['target_module']) <=> ($b['source_module'] . $b['target_module']);
        });

        return $result;
    }
}
