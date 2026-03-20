<?php

namespace dndark\LogicMap\Support\Traversal;

use dndark\LogicMap\Domain\Edge;
use dndark\LogicMap\Domain\Graph;
use dndark\LogicMap\Domain\Node;

/**
 * Shared BFS traversal engine for path-based read models.
 *
 * Produces an ordered, deduplicated list of WalkStep objects
 * following deterministic ordering:
 *   1. Shortest hop count first
 *   2. Edge priority (via TraversalPolicy::EDGE_PRIORITY)
 *   3. Lexical node ID
 *
 * Cycles are prevented by the visited set.
 */
class GraphWalker
{
    /**
     * Walk the graph from a starting node.
     *
     * @param  Graph   $graph
     * @param  string  $startId    Node ID to begin from
     * @param  string  $direction  'downstream'|'upstream'|'both'
     * @param  int     $maxDepth
     * @return WalkStep[]          Ordered by depth, then priority, then lexical ID
     */
    public function walk(
        Graph  $graph,
        string $startId,
        string $direction,
        int    $maxDepth,
    ): array {
        $visited  = [$startId => true];
        $queue    = []; // [nodeId, depth, incomingEdge, asyncBoundary]
        $results  = [];

        $this->enqueue($queue, $graph, $startId, $direction, 0, null, false, $visited);

        while (!empty($queue)) {
            // Sort queue for deterministic ordering at each step
            usort($queue, function (array $a, array $b): int {
                // depth first
                if ($a[1] !== $b[1]) {
                    return $a[1] <=> $b[1];
                }
                // edge priority second (null edge = 0 priority)
                $aPrio = $a[2] !== null ? TraversalPolicy::edgePriority($a[2]->type->value) : 0;
                $bPrio = $b[2] !== null ? TraversalPolicy::edgePriority($b[2]->type->value) : 0;
                if ($aPrio !== $bPrio) {
                    return $aPrio <=> $bPrio;
                }
                // lexical node ID third
                return $a[0] <=> $b[0];
            });

            [$nodeId, $depth, $incomingEdge, $asyncBoundary] = array_shift($queue);

            $node = $graph->getNode($nodeId);
            if ($node === null) {
                continue;
            }

            $results[] = new WalkStep(
                node:          $node,
                depth:         $depth,
                incomingEdge:  $incomingEdge,
                asyncBoundary: $asyncBoundary,
            );

            if ($depth < $maxDepth) {
                $this->enqueue($queue, $graph, $nodeId, $direction, $depth + 1, null, false, $visited);
            }
        }

        return $results;
    }

    /**
     * Enqueue unvisited neighbors of a node.
     */
    private function enqueue(
        array  &$queue,
        Graph  $graph,
        string $nodeId,
        string $direction,
        int    $nextDepth,
        mixed  $parentEdge,
        bool   $parentAsync,
        array  &$visited,
    ): void {
        $edges = $this->getNeighborEdges($graph, $nodeId, $direction);

        foreach ($edges as $edge) {
            $neighborId = $direction === 'upstream' ? $edge->source : $edge->target;

            if (isset($visited[$neighborId])) {
                continue;
            }

            $visited[$neighborId] = true;
            $isAsync = TraversalPolicy::isAsyncBoundary($edge->type->value);

            $queue[] = [$neighborId, $nextDepth, $edge, $isAsync];
        }
    }

    /**
     * Returns outgoing (downstream), incoming (upstream), or both edges from a node.
     *
     * @return Edge[]
     */
    private function getNeighborEdges(Graph $graph, string $nodeId, string $direction): array
    {
        return match ($direction) {
            'upstream'   => $graph->getEdgesTo($nodeId),
            'downstream' => $graph->getEdgesFrom($nodeId),
            default      => array_merge(
                $graph->getEdgesFrom($nodeId),
                $graph->getEdgesTo($nodeId)
            ),
        };
    }
}
