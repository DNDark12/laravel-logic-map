<?php

namespace dndark\LogicMap\Services;

use dndark\LogicMap\Contracts\GraphRepository;
use dndark\LogicMap\Domain\QueryResult;
use dndark\LogicMap\Domain\SnapshotResolution;

class ExportReadService
{
    public function __construct(
        protected SnapshotResolver $snapshotResolver,
        protected GraphRepository $repository,
    ) {
    }

    public function graph(?string $snapshot = null): QueryResult
    {
        $resolution = $this->snapshotResolver->resolve($snapshot);
        if (!$resolution->hasGraph()) {
            return $this->snapshotNotFound($resolution);
        }

        return QueryResult::success([
            'graph' => $this->graphPayload($resolution),
        ])->withResolution($resolution->context());
    }

    public function analysis(?string $snapshot = null): QueryResult
    {
        $resolution = $this->snapshotResolver->resolve($snapshot, true);
        if (!$resolution->hasGraph()) {
            return $this->snapshotNotFound($resolution);
        }
        if (!$resolution->hasAnalysis()) {
            return $this->analysisUnavailable($resolution);
        }

        return QueryResult::success([
            'analysis' => $this->analysisPayload($resolution),
        ])->withResolution($resolution->context());
    }

    public function bundle(?string $snapshot = null): QueryResult
    {
        $resolution = $this->snapshotResolver->resolve($snapshot, true);
        if (!$resolution->hasGraph()) {
            return $this->snapshotNotFound($resolution);
        }
        if (!$resolution->hasAnalysis()) {
            return $this->analysisUnavailable($resolution);
        }

        return QueryResult::success([
            'graph' => $this->graphPayload($resolution),
            'analysis' => $this->analysisPayload($resolution),
        ])->withResolution($resolution->context());
    }

    public function csv(?string $snapshot = null): QueryResult
    {
        $resolution = $this->snapshotResolver->resolve($snapshot, true);
        if (!$resolution->hasGraph()) {
            return $this->snapshotNotFound($resolution);
        }

        $includeMetrics = (bool) config('logic-map.export.include_metrics', true);
        $delimiter = (string) config('logic-map.export.csv_delimiter', ',');
        if (strlen($delimiter) !== 1) {
            $delimiter = ',';
        }

        $headers = $includeMetrics
            ? ['id', 'kind', 'name', 'in_degree', 'out_degree', 'fan_in', 'fan_out', 'instability', 'coupling', 'depth', 'risk', 'risk_score']
            : ['id', 'kind', 'name', 'risk', 'risk_score'];

        $rows = [];
        foreach ($resolution->graph->getNodes() as $node) {
            $risk = ($resolution->analysis && isset($resolution->analysis->nodeRiskMap[$node->id]))
                ? $resolution->analysis->nodeRiskMap[$node->id]
                : ['risk' => 'none', 'score' => 0];

            if ($includeMetrics) {
                $rows[] = [
                    $node->id,
                    $node->kind->value,
                    $node->name,
                    $node->metrics['in_degree'] ?? 0,
                    $node->metrics['out_degree'] ?? 0,
                    $node->metrics['fan_in'] ?? 0,
                    $node->metrics['fan_out'] ?? 0,
                    $node->metrics['instability'] ?? 0,
                    $node->metrics['coupling'] ?? 0,
                    $node->metrics['depth'] ?? '',
                    $risk['risk'],
                    $risk['score'],
                ];
            } else {
                $rows[] = [
                    $node->id,
                    $node->kind->value,
                    $node->name,
                    $risk['risk'],
                    $risk['score'],
                ];
            }
        }

        $output = fopen('php://temp', 'r+');
        fputcsv($output, $headers, $delimiter);
        foreach ($rows as $row) {
            fputcsv($output, $row, $delimiter);
        }
        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return QueryResult::success(
            data: $csv,
            meta: [
                'content_type' => 'text/csv; charset=UTF-8',
                'filename' => 'logic-map-export.csv',
            ],
        );
    }

    protected function graphPayload(SnapshotResolution $resolution): array
    {
        $metadata = $resolution->resolvedFingerprint !== null
            ? $this->repository->getSnapshotMetadata($resolution->resolvedFingerprint)
            : [];

        return [
            'nodes' => array_map(fn($node) => $node->toArray(), array_values($resolution->graph->getNodes())),
            'edges' => array_map(fn($edge) => $edge->toArray(), $resolution->graph->getEdges()),
            'metadata' => [
                'fingerprint' => $resolution->resolvedFingerprint,
                'generated_at' => $metadata['generated_at'] ?? null,
            ],
        ];
    }

    protected function analysisPayload(SnapshotResolution $resolution): array
    {
        $payload = $resolution->analysis->toArray();
        $payload['metadata']['graph_fingerprint'] = $resolution->resolvedFingerprint;

        return $payload;
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
