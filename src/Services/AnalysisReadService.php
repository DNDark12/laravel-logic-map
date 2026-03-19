<?php

namespace dndark\LogicMap\Services;

use dndark\LogicMap\Domain\QueryResult;
use dndark\LogicMap\Domain\SnapshotResolution;

class AnalysisReadService
{
    public function __construct(
        protected SnapshotResolver $snapshotResolver,
        protected HealthPayloadBuilder $healthPayloadBuilder,
        protected HotspotsBuilder $hotspotsBuilder,
    ) {
    }

    public function violations(array $filters = [], ?string $snapshot = null): QueryResult
    {
        $resolution = $this->snapshotResolver->resolve($snapshot, true);
        if (!$resolution->hasGraph()) {
            return $this->snapshotNotFound($resolution);
        }
        if (!$resolution->hasAnalysis()) {
            return $this->analysisUnavailable($resolution);
        }

        $violations = $resolution->analysis->violations;

        if (!empty($filters['severity'])) {
            $violations = array_values(array_filter(
                $violations,
                fn($violation) => $violation->severity === $filters['severity']
            ));
        }

        if (!empty($filters['type'])) {
            $violations = array_values(array_filter(
                $violations,
                fn($violation) => $violation->type === $filters['type']
            ));
        }

        return QueryResult::success([
            'violations' => array_map(fn($violation) => $violation->toArray(), $violations),
            'summary' => $resolution->analysis->summary,
        ])->withResolution($resolution->context());
    }

    public function health(?string $snapshot = null): QueryResult
    {
        $resolution = $this->snapshotResolver->resolve($snapshot, true);
        if (!$resolution->hasGraph()) {
            return $this->snapshotNotFound($resolution);
        }
        if (!$resolution->hasAnalysis()) {
            return $this->analysisUnavailable($resolution);
        }

        return QueryResult::success(
            $this->healthPayloadBuilder->build($resolution->graph, $resolution->analysis)
        )->withResolution($resolution->context());
    }

    public function hotspots(array $filters = [], ?string $snapshot = null): QueryResult
    {
        $resolution = $this->snapshotResolver->resolve($snapshot, true);
        if (!$resolution->hasGraph()) {
            return $this->snapshotNotFound($resolution);
        }
        if (!$resolution->hasAnalysis()) {
            return $this->analysisUnavailable($resolution);
        }

        return QueryResult::success(
            $this->hotspotsBuilder->build($resolution->graph, $resolution->analysis, $filters)
        )->withResolution($resolution->context());
    }

    protected function snapshotNotFound(SnapshotResolution $resolution): QueryResult
    {
        if ($resolution->requestedFingerprint !== null) {
            return QueryResult::typedError(
                type: 'snapshot_not_found',
                message: "Snapshot '{$resolution->requestedFingerprint}' not found.",
                httpStatus: 404,
                meta: ['resolution' => $resolution->context()],
                data: ['_resolution' => $resolution->context()],
            );
        }

        return QueryResult::typedError(
            type: 'snapshot_not_found',
            message: 'No snapshot found. Run `php artisan logic-map:build` first.',
            httpStatus: 404,
            meta: ['resolution' => $resolution->context()],
            data: ['_resolution' => $resolution->context()],
        );
    }

    protected function analysisUnavailable(SnapshotResolution $resolution): QueryResult
    {
        return QueryResult::typedError(
            type: 'analysis_unavailable',
            message: 'No analysis report found. Run `php artisan logic-map:build` first.',
            httpStatus: 404,
            meta: ['resolution' => $resolution->context()],
            data: ['_resolution' => $resolution->context()],
        );
    }
}
