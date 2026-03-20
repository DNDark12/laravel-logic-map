<?php

namespace dndark\LogicMap\Services;

use dndark\LogicMap\Domain\QueryResult;
use dndark\LogicMap\Domain\SnapshotResolution;
use dndark\LogicMap\Services\Trace\TraceProjector;

/**
 * Read service for GET /logic-map/trace/{id}.
 *
 * Validates params, resolves snapshot, finds target node, delegates to TraceProjector.
 */
class TraceReadService
{
    private const VALID_DIRECTIONS = ['forward', 'backward'];
    private const MIN_DEPTH = 1;
    private const MAX_DEPTH = 10;
    private const DEFAULT_DEPTH = 6;
    private const DEFAULT_DIRECTION = 'forward';

    public function __construct(
        protected SnapshotResolver $snapshotResolver,
        protected TraceProjector   $traceProjector,
    ) {
    }

    public function trace(
        string  $id,
        string  $direction = self::DEFAULT_DIRECTION,
        int     $maxDepth  = self::DEFAULT_DEPTH,
        ?string $snapshot  = null,
    ): QueryResult {
        // Validate direction
        if (!in_array($direction, self::VALID_DIRECTIONS, true)) {
            return QueryResult::typedError(
                type: 'invalid_direction',
                message: "Direction must be one of: " . implode(', ', self::VALID_DIRECTIONS) . ".",
                httpStatus: 422,
            );
        }

        // Validate max_depth
        if ($maxDepth < self::MIN_DEPTH || $maxDepth > self::MAX_DEPTH) {
            return QueryResult::typedError(
                type: 'invalid_max_depth',
                message: "max_depth must be between " . self::MIN_DEPTH . " and " . self::MAX_DEPTH . ".",
                httpStatus: 422,
            );
        }

        // Resolve snapshot
        $resolution = $this->snapshotResolver->resolve($snapshot, true);

        if (!$resolution->hasGraph()) {
            return $this->snapshotNotFound($resolution);
        }

        if (!$resolution->hasAnalysis()) {
            return $this->analysisUnavailable($resolution);
        }

        // Find target node
        $targetNode = $resolution->graph->getNode($id);
        if ($targetNode === null) {
            return QueryResult::typedError(
                type: 'node_not_found',
                message: "Node '{$id}' not found in the active snapshot.",
                httpStatus: 404,
                data: ['_resolution' => $resolution->context()],
            );
        }

        // Normalize direction for GraphWalker (forward→downstream, backward→upstream)
        $walkerDirection = match ($direction) {
            'forward'  => 'downstream',
            'backward' => 'upstream',
            default    => 'downstream',
        };

        // Project trace report
        $report = $this->traceProjector->project(
            graph:      $resolution->graph,
            report:     $resolution->analysis,
            targetNode: $targetNode,
            direction:  $walkerDirection,
            maxDepth:   $maxDepth,
        );

        // Re-attach the original direction to summary for contract compliance
        $data = $report->toArray();
        $data['summary']['direction'] = $direction;

        return QueryResult::success($data)->withResolution($resolution->context());
    }

    protected function snapshotNotFound(SnapshotResolution $resolution): QueryResult
    {
        if ($resolution->requestedFingerprint !== null) {
            return QueryResult::typedError(
                type: 'snapshot_not_found',
                message: "Snapshot '{$resolution->requestedFingerprint}' not found.",
                httpStatus: 404,
                data: ['_resolution' => $resolution->context()],
            );
        }

        return QueryResult::typedError(
            type: 'snapshot_not_found',
            message: 'No snapshot found. Run `php artisan logic-map:build` first.',
            httpStatus: 404,
            data: ['_resolution' => $resolution->context()],
        );
    }

    protected function analysisUnavailable(SnapshotResolution $resolution): QueryResult
    {
        return QueryResult::typedError(
            type: 'analysis_unavailable',
            message: 'No analysis report found. Run `php artisan logic-map:build` first.',
            httpStatus: 404,
            data: ['_resolution' => $resolution->context()],
        );
    }
}
