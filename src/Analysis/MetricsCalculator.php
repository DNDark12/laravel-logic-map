<?php

namespace dndark\LogicMap\Analysis;

use dndark\LogicMap\Domain\Edge;
use dndark\LogicMap\Domain\Enums\NodeKind;
use dndark\LogicMap\Domain\Graph;

class MetricsCalculator
{
    /**
     * Calculate and populate metrics on all nodes in the graph.
     * Metrics are structural facts derived from graph topology.
     *
     * Populates: in_degree, out_degree, fan_in, fan_out, instability, coupling, depth
     */
    public function calculate(Graph $graph): void
    {
        $depths = $this->calculateAllDepths($graph);

        foreach ($graph->getNodes() as $node) {
            $edgesTo = $graph->getEdgesTo($node->id);
            $edgesFrom = $graph->getEdgesFrom($node->id);

            $inDegree = count($edgesTo);
            $outDegree = count($edgesFrom);

            // fan_in: unique source nodes that reference this node
            $fanIn = count(array_unique(array_map(fn(Edge $e) => $e->source, $edgesTo)));

            // fan_out: unique target nodes this node references
            $fanOut = count(array_unique(array_map(fn(Edge $e) => $e->target, $edgesFrom)));

            // instability: 0 = stable (depended upon), 1 = unstable (depends on others)
            $instability = ($fanIn + $fanOut) > 0
                ? round($fanOut / ($fanIn + $fanOut), 3)
                : 0.0;

            $node->metrics = [
                'in_degree' => $inDegree,
                'out_degree' => $outDegree,
                'fan_in' => $fanIn,
                'fan_out' => $fanOut,
                'instability' => $instability,
                'coupling' => $fanIn + $fanOut,
                'depth' => $depths[$node->id] ?? null,
            ];

            // Hub utility: many callers, no outgoing calls, and not an entry route.
            $hubThreshold = $this->getHubUtilityFanInThreshold();
            $isHubUtility = $node->kind !== NodeKind::ROUTE
                && $fanIn > $hubThreshold
                && $fanOut === 0;

            $node->metadata['isHubUtility'] = $isHubUtility;
            $node->metadata['is_hub_utility'] = $isHubUtility;
        }
    }

    /**
     * Calculate BFS shortest path depth from entrypoint nodes.
     *
     * Per ADR-012:
     * - Entrypoints: route nodes (configurable)
     * - Traversal edges: handles, calls, dispatches, queries, resolves
     * - Unreachable nodes get depth = null
     * - Cycles handled naturally via BFS visited set
     *
     * @return array<string, int> nodeId => depth (missing = unreachable)
     */
    protected function calculateAllDepths(Graph $graph): array
    {
        $entrypointKinds = $this->getEntrypointKinds();
        $traversalEdgeTypes = $this->getTraversalEdgeTypes();

        // Find entrypoint nodes
        $queue = [];
        $depths = [];

        foreach ($graph->getNodes() as $node) {
            if (in_array($node->kind->value, $entrypointKinds, true)) {
                $queue[] = $node->id;
                $depths[$node->id] = 0;
            }
        }

        // BFS from all entrypoints simultaneously
        while (!empty($queue)) {
            $currentId = array_shift($queue);
            $currentDepth = $depths[$currentId];

            foreach ($graph->getEdgesFrom($currentId) as $edge) {
                // Only traverse configured edge types
                if (!in_array($edge->type->value, $traversalEdgeTypes, true)) {
                    continue;
                }

                // Skip already-visited or shorter-path nodes
                if (isset($depths[$edge->target])) {
                    continue;
                }

                $depths[$edge->target] = $currentDepth + 1;
                $queue[] = $edge->target;
            }
        }

        return $depths;
    }

    /**
     * Get configured entrypoint kinds for depth calculation.
     *
     * @return string[]
     */
    protected function getEntrypointKinds(): array
    {
        return config('logic-map.analysis.depth.entrypoint_kinds', ['route']);
    }

    /**
     * Get configured edge types for depth traversal.
     *
     * @return string[]
     */
    protected function getTraversalEdgeTypes(): array
    {
        return config(
            'logic-map.analysis.depth.traversal_edge_types',
            ['handles', 'calls', 'dispatches', 'queries', 'resolves']
        );
    }

    protected function getHubUtilityFanInThreshold(): int
    {
        return (int) config('logic-map.analysis.ui_thresholds.hub_utility_fan_in', 5);
    }
}
