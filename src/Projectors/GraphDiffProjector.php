<?php

namespace dndark\LogicMap\Projectors;

use dndark\LogicMap\Domain\Edge;
use dndark\LogicMap\Domain\Graph;
use dndark\LogicMap\Domain\Node;

class GraphDiffProjector
{
    public function diff(Graph $from, Graph $to): array
    {
        $maxNodeChanges = (int) config('logic-map.diff.max_node_changes', 200);
        $maxEdgeChanges = (int) config('logic-map.diff.max_edge_changes', 300);

        [$addedNodes, $removedNodes, $modifiedNodes, $totalAddedNodes, $totalRemovedNodes, $totalModifiedNodes] = $this->buildNodeDiff(
            $from,
            $to,
            $maxNodeChanges
        );
        [$addedEdges, $removedEdges, $modifiedEdges, $totalAddedEdges, $totalRemovedEdges, $totalModifiedEdges] = $this->buildEdgeDiff(
            $from,
            $to,
            $maxEdgeChanges
        );

        return [
            'summary' => [
                'added_nodes' => $totalAddedNodes,
                'removed_nodes' => $totalRemovedNodes,
                'modified_nodes' => $totalModifiedNodes,
                'added_edges' => $totalAddedEdges,
                'removed_edges' => $totalRemovedEdges,
                'modified_edges' => $totalModifiedEdges,
            ],
            'nodes' => [
                'added' => $addedNodes,
                'removed' => $removedNodes,
                'modified' => $modifiedNodes,
                'truncated' => $totalAddedNodes > count($addedNodes)
                    || $totalRemovedNodes > count($removedNodes)
                    || $totalModifiedNodes > count($modifiedNodes),
            ],
            'edges' => [
                'added' => $addedEdges,
                'removed' => $removedEdges,
                'modified' => $modifiedEdges,
                'truncated' => $totalAddedEdges > count($addedEdges)
                    || $totalRemovedEdges > count($removedEdges)
                    || $totalModifiedEdges > count($modifiedEdges),
            ],
        ];
    }

    /**
     * @return array{
     *   0: array<int, array>,
     *   1: array<int, array>,
     *   2: array<int, array>,
     *   3: int,
     *   4: int,
     *   5: int
     * }
     */
    protected function buildNodeDiff(Graph $from, Graph $to, int $maxChanges): array
    {
        $fromNodes = $from->getNodes();
        $toNodes = $to->getNodes();

        $fromIds = array_keys($fromNodes);
        $toIds = array_keys($toNodes);

        $addedIds = array_values(array_diff($toIds, $fromIds));
        $removedIds = array_values(array_diff($fromIds, $toIds));
        $commonIds = array_values(array_intersect($fromIds, $toIds));

        $added = [];
        foreach (array_slice($addedIds, 0, $maxChanges) as $id) {
            $added[] = $toNodes[$id]->toArray();
        }

        $removed = [];
        foreach (array_slice($removedIds, 0, $maxChanges) as $id) {
            $removed[] = $fromNodes[$id]->toArray();
        }

        $modified = [];
        foreach ($commonIds as $id) {
            if (count($modified) >= $maxChanges) {
                break;
            }

            $fromComparable = $this->nodeComparablePayload($fromNodes[$id]);
            $toComparable = $this->nodeComparablePayload($toNodes[$id]);

            if ($fromComparable !== $toComparable) {
                $modified[] = [
                    'id' => $id,
                    'from' => $fromComparable,
                    'to' => $toComparable,
                ];
            }
        }

        $totalModified = 0;
        foreach ($commonIds as $id) {
            if ($this->nodeComparablePayload($fromNodes[$id]) !== $this->nodeComparablePayload($toNodes[$id])) {
                $totalModified++;
            }
        }

        return [
            $added,
            $removed,
            $modified,
            count($addedIds),
            count($removedIds),
            $totalModified,
        ];
    }

    /**
     * @return array{
     *   0: array<int, array>,
     *   1: array<int, array>,
     *   2: array<int, array>,
     *   3: int,
     *   4: int,
     *   5: int
     * }
     */
    protected function buildEdgeDiff(Graph $from, Graph $to, int $maxChanges): array
    {
        $fromEdges = $this->edgeMap($from->getEdges());
        $toEdges = $this->edgeMap($to->getEdges());

        $fromKeys = array_keys($fromEdges);
        $toKeys = array_keys($toEdges);

        $addedKeys = array_values(array_diff($toKeys, $fromKeys));
        $removedKeys = array_values(array_diff($fromKeys, $toKeys));
        $commonKeys = array_values(array_intersect($fromKeys, $toKeys));

        $added = [];
        foreach (array_slice($addedKeys, 0, $maxChanges) as $key) {
            $added[] = $toEdges[$key]->toArray();
        }

        $removed = [];
        foreach (array_slice($removedKeys, 0, $maxChanges) as $key) {
            $removed[] = $fromEdges[$key]->toArray();
        }

        $modifiedAll = [];
        foreach ($commonKeys as $key) {
            if ($fromEdges[$key]->confidence !== $toEdges[$key]->confidence) {
                $modifiedAll[] = [
                    'key' => $key,
                    'from' => $fromEdges[$key]->toArray(),
                    'to' => $toEdges[$key]->toArray(),
                ];
            }
        }

        return [
            $added,
            $removed,
            array_slice($modifiedAll, 0, $maxChanges),
            count($addedKeys),
            count($removedKeys),
            count($modifiedAll),
        ];
    }

    /**
     * @param array<int, Edge> $edges
     * @return array<string, Edge>
     */
    protected function edgeMap(array $edges): array
    {
        $map = [];
        foreach ($edges as $edge) {
            $key = $this->edgeKey($edge);
            $map[$key] = $edge;
        }

        return $map;
    }

    protected function edgeKey(Edge $edge): string
    {
        return $edge->source . '|' . $edge->target . '|' . $edge->type->value;
    }

    protected function nodeComparablePayload(Node $node): array
    {
        return [
            'kind' => $node->kind->value,
            'name' => $node->name,
            'scope' => $node->scope,
            'parentId' => $node->parentId,
            'metadata' => $node->metadata,
        ];
    }
}

