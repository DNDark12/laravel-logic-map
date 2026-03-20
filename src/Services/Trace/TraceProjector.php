<?php

namespace dndark\LogicMap\Services\Trace;

use dndark\LogicMap\Analysis\Support\ModuleExtractor;
use dndark\LogicMap\Domain\AnalysisReport;
use dndark\LogicMap\Domain\Graph;
use dndark\LogicMap\Domain\Node;
use dndark\LogicMap\Domain\WorkflowTraceReport;
use dndark\LogicMap\Support\Traversal\GraphWalker;
use dndark\LogicMap\Support\Traversal\TraversalPolicy;
use dndark\LogicMap\Support\Traversal\WalkStep;

/**
 * Projects workflow trace data for a target node.
 *
 * Ordering rules (from docs/v2/api/workflow-trace.md):
 *   1. Shortest hop count first
 *   2. Edge priority: route_to_controller > call > dispatch > listen > use
 *   3. Lexical node ID
 *
 * Segment rules:
 *   - dispatch/listen edges start a new async segment boundary
 *   - cycles prevented by GraphWalker visited set
 *   - stops at max_depth, sets truncated=true
 */
class TraceProjector
{
    public function __construct(
        protected GraphWalker $walker,
    ) {
    }

    public function project(
        Graph          $graph,
        AnalysisReport $report,
        Node           $targetNode,
        string         $direction,
        int            $maxDepth,
    ): WorkflowTraceReport {
        // Walk in the requested direction
        $steps = $this->walker->walk($graph, $targetNode->id, $direction, $maxDepth);

        // Determine if truncated (any step at exactly max_depth means we stopped there)
        $truncated = false;
        foreach ($steps as $step) {
            if ($step->depth >= $maxDepth) {
                // Check if the node at maxDepth has further neighbors we could expand
                $neighborEdges = match ($direction) {
                    'upstream'   => $graph->getEdgesTo($step->node->id),
                    'backward'   => $graph->getEdgesTo($step->node->id),
                    'downstream' => $graph->getEdgesFrom($step->node->id),
                    'forward'    => $graph->getEdgesFrom($step->node->id),
                    default      => array_merge(
                        $graph->getEdgesFrom($step->node->id),
                        $graph->getEdgesTo($step->node->id),
                    ),
                };
                if (!empty($neighborEdges)) {
                    $truncated = true;
                    break;
                }
            }
        }

        $targetModule = $targetNode->metadata['module'] ?? ModuleExtractor::moduleOf($targetNode->id);

        // Build segments
        $segments      = $this->buildSegments($steps);
        $branchPoints  = $this->buildBranchPoints($steps, $graph, $direction);
        $entrypoints   = $this->buildEntrypoints($steps, $direction);
        $persistenceTP = $this->buildPersistenceTouchpoints($steps);

        $asyncHops = count(array_filter($steps, fn(WalkStep $s) => $s->asyncBoundary));

        // Build target identity
        $riskInfo = $report->getNodeRisk($targetNode->id);
        $target = [
            'node_id' => $targetNode->id,
            'kind'    => $targetNode->kind->value,
            'name'    => $targetNode->metadata['shortLabel'] ?? $targetNode->name ?? $targetNode->id,
            'module'  => $targetModule,
            'risk'    => $riskInfo['risk'] ?? 'none',
        ];

        $summary = [
            'direction'               => $direction,
            'max_depth'               => $maxDepth,
            'segment_count'           => count($segments),
            'branch_count'            => count($branchPoints),
            'async_hops'              => $asyncHops,
            'persistence_touch_count' => count($persistenceTP),
            'truncated'               => $truncated,
        ];

        return new WorkflowTraceReport(
            target:                 $target,
            summary:                $summary,
            segments:               $segments,
            branchPoints:           $branchPoints,
            entrypoints:            $entrypoints,
            persistenceTouchpoints: $persistenceTP,
        );
    }

    /**
     * Build segment rows from walk steps.
     * A new segment starts at each async boundary (dispatch/listen edge).
     */
    private function buildSegments(array $steps): array
    {
        $segments    = [];
        $segmentIdx  = 0;
        $prevAsync   = false;

        foreach ($steps as $step) {
            /** @var WalkStep $step */
            if ($step->asyncBoundary && !$prevAsync) {
                $segmentIdx++;
            }
            $prevAsync = $step->asyncBoundary;

            $edgeType = $step->incomingEdge?->type->value ?? null;

            $segmentType = 'sync';
            if ($step->asyncBoundary) {
                $segmentType = match ($edgeType) {
                    'dispatch' => 'async_dispatch',
                    'listen'   => 'async_listener',
                    default    => 'async_dispatch',
                };
            } elseif ($step->incomingEdge !== null &&
                      TraversalPolicy::isPersistenceKind($step->node->kind->value)) {
                $segmentType = 'persistence';
            }

            $segments[] = [
                'segment_index'  => $segmentIdx,
                'segment_type'   => $segmentType,
                'from_node_id'   => $step->incomingEdge?->source ?? null,
                'to_node_id'     => $step->node->id,
                'edge_type'      => $edgeType,
                'depth'          => $step->depth,
                'async_boundary' => $step->asyncBoundary,
            ];
        }

        return $segments;
    }

    /**
     * Build branch_points: nodes where traversal fans out (outgoing > 1 in forward, incoming > 1 in backward).
     */
    private function buildBranchPoints(array $steps, Graph $graph, string $direction): array
    {
        $branchPoints = [];

        foreach ($steps as $step) {
            /** @var WalkStep $step */
            $nodeId = $step->node->id;

            if ($direction === 'backward' || $direction === 'upstream') {
                $count = count($graph->getEdgesTo($nodeId));
                $key   = 'incoming_count';
            } else {
                $count = count($graph->getEdgesFrom($nodeId));
                $key   = 'outgoing_count';
            }

            if ($count > 1) {
                $branchPoints[] = [
                    'node_id' => $nodeId,
                    'kind'    => $step->node->kind->value,
                    $key      => $count,
                    'depth'   => $step->depth,
                ];
            }
        }

        return $branchPoints;
    }

    /**
     * Build entrypoints: for backward traces, route nodes that are reached.
     */
    private function buildEntrypoints(array $steps, string $direction): array
    {
        if (!in_array($direction, ['backward', 'upstream'], true)) {
            return [];
        }

        $entrypoints = [];
        foreach ($steps as $step) {
            if ($step->node->kind->value === 'route') {
                $entrypoints[] = [
                    'node_id' => $step->node->id,
                    'kind'    => $step->node->kind->value,
                    'name'    => $step->node->metadata['shortLabel'] ?? $step->node->name ?? $step->node->id,
                    'depth'   => $step->depth,
                ];
            }
        }

        return $entrypoints;
    }

    /**
     * Build persistence touchpoints: model/repository nodes encountered.
     */
    private function buildPersistenceTouchpoints(array $steps): array
    {
        $touchpoints = [];
        foreach ($steps as $step) {
            if (TraversalPolicy::isPersistenceKind($step->node->kind->value)) {
                $touchpoints[] = [
                    'node_id' => $step->node->id,
                    'kind'    => $step->node->kind->value,
                    'name'    => $step->node->metadata['shortLabel'] ?? $step->node->name ?? $step->node->id,
                    'depth'   => $step->depth,
                ];
            }
        }

        return $touchpoints;
    }
}
