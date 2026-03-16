<?php

namespace dndark\LogicMap\Analysis\Analyzers;

use dndark\LogicMap\Contracts\ViolationAnalyzer;
use dndark\LogicMap\Domain\Graph;
use dndark\LogicMap\Domain\Violation;

/**
 * Detects circular dependencies using Tarjan's Strongly Connected Components algorithm.
 *
 * A cycle exists when two or more nodes form a loop of mutual dependencies.
 * Only reports SCCs with size > 1.
 */
class CircularDependencyAnalyzer implements ViolationAnalyzer
{
    protected int $index = 0;
    protected array $stack = [];
    protected array $onStack = [];
    protected array $indices = [];
    protected array $lowlinks = [];
    protected array $sccs = [];

    public function analyze(Graph $graph): array
    {
        $this->reset();

        // Build adjacency list from edges (unique source → target pairs)
        $adjacency = [];
        foreach ($graph->getEdges() as $edge) {
            $adjacency[$edge->source][$edge->target] = true;
        }

        // Run Tarjan on all nodes
        foreach ($graph->getNodes() as $node) {
            if (!isset($this->indices[$node->id])) {
                $this->strongConnect($node->id, $adjacency);
            }
        }

        // Convert SCCs (size > 1) to violations
        $violations = [];
        foreach ($this->sccs as $scc) {
            if (count($scc) <= 1) {
                continue;
            }

            $nodeList = implode(' → ', array_slice($scc, 0, 5));
            if (count($scc) > 5) {
                $nodeList .= ' → ...(' . count($scc) . ' total)';
            }

            // Report one violation per node in the cycle
            foreach ($scc as $nodeId) {
                $violations[] = new Violation(
                    type: 'circular_dependency',
                    severity: 'critical',
                    nodeId: $nodeId,
                    message: "Part of circular dependency: {$nodeList}",
                    details: [
                        'cycle_nodes' => $scc,
                        'cycle_size' => count($scc),
                    ],
                );
            }
        }

        return $violations;
    }

    /**
     * Tarjan's SCC algorithm — recursive strongConnect.
     */
    protected function strongConnect(string $nodeId, array &$adjacency): void
    {
        $this->indices[$nodeId] = $this->index;
        $this->lowlinks[$nodeId] = $this->index;
        $this->index++;
        $this->stack[] = $nodeId;
        $this->onStack[$nodeId] = true;

        // Visit neighbors
        foreach (array_keys($adjacency[$nodeId] ?? []) as $target) {
            if (!isset($this->indices[$target])) {
                $this->strongConnect($target, $adjacency);
                $this->lowlinks[$nodeId] = min(
                    $this->lowlinks[$nodeId],
                    $this->lowlinks[$target]
                );
            } elseif (!empty($this->onStack[$target])) {
                $this->lowlinks[$nodeId] = min(
                    $this->lowlinks[$nodeId],
                    $this->indices[$target]
                );
            }
        }

        // Root of an SCC
        if ($this->lowlinks[$nodeId] === $this->indices[$nodeId]) {
            $scc = [];
            do {
                $w = array_pop($this->stack);
                $this->onStack[$w] = false;
                $scc[] = $w;
            } while ($w !== $nodeId);

            $this->sccs[] = $scc;
        }
    }

    protected function reset(): void
    {
        $this->index = 0;
        $this->stack = [];
        $this->onStack = [];
        $this->indices = [];
        $this->lowlinks = [];
        $this->sccs = [];
    }

    public function getName(): string
    {
        return 'circular_dependency';
    }

    public function isEnabled(): bool
    {
        return (bool)config('logic-map.analysis.analyzers.circular_dependency', true);
    }
}
