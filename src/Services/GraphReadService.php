<?php

namespace dndark\LogicMap\Services;

use dndark\LogicMap\Contracts\GraphRepository;
use dndark\LogicMap\Domain\QueryResult;
use dndark\LogicMap\Domain\SnapshotResolution;
use dndark\LogicMap\Projectors\GraphDiffProjector;
use dndark\LogicMap\Projectors\MetaProjector;
use dndark\LogicMap\Projectors\OverviewProjector;
use dndark\LogicMap\Projectors\SearchProjector;
use dndark\LogicMap\Projectors\SubgraphProjector;

class GraphReadService
{
    public function __construct(
        protected GraphRepository $repository,
        protected SnapshotResolver $snapshotResolver,
        protected OverviewProjector $overviewProjector,
        protected SubgraphProjector $subgraphProjector,
        protected SearchProjector $searchProjector,
        protected MetaProjector $metaProjector,
        protected GraphDiffProjector $diffProjector,
    ) {
    }

    public function overview(array $filters = [], ?string $snapshot = null): QueryResult
    {
        $resolution = $this->snapshotResolver->resolve($snapshot);
        if (!$resolution->hasGraph()) {
            return $this->snapshotNotFound($resolution);
        }

        $data = $this->overviewProjector->overview($resolution->graph, $filters);

        return QueryResult::success($data)->withResolution($resolution->context());
    }

    public function subgraph(string $id, array $filters = [], ?string $snapshot = null): QueryResult
    {
        $resolution = $this->snapshotResolver->resolve($snapshot);
        if (!$resolution->hasGraph()) {
            return $this->snapshotNotFound($resolution);
        }

        if (!$resolution->graph->hasNode($id)) {
            return QueryResult::typedError(
                type: 'node_not_found',
                message: "Node '{$id}' not found.",
                httpStatus: 404,
                meta: ['resolution' => $resolution->context()],
                data: ['_resolution' => $resolution->context()],
            );
        }

        $data = $this->subgraphProjector->subgraph($resolution->graph, $id, $filters);

        return QueryResult::success($data)->withResolution($resolution->context());
    }

    public function search(string $query, array $filters = [], ?string $snapshot = null): QueryResult
    {
        $resolution = $this->snapshotResolver->resolve($snapshot);
        if (!$resolution->hasGraph()) {
            return $this->snapshotNotFound($resolution);
        }

        $data = $this->searchProjector->search($resolution->graph, $query, $filters);

        return QueryResult::success($data)->withResolution($resolution->context());
    }

    public function meta(?string $snapshot = null): QueryResult
    {
        $resolution = $this->snapshotResolver->resolve($snapshot);
        if (!$resolution->hasGraph()) {
            return $this->snapshotNotFound($resolution);
        }

        $data = $this->metaProjector->getMeta($resolution->graph);
        $data['kind_labels'] = config('logic-map.analysis.kind_labels', []);
        $data['ui_thresholds'] = config('logic-map.analysis.ui_thresholds', ['large_graph' => 150]);

        return QueryResult::success($data)->withResolution($resolution->context());
    }

    public function snapshots(): QueryResult
    {
        $fingerprints = array_reverse($this->repository->listFingerprints());
        $latestFingerprint = $this->repository->getLatestFingerprint();
        $resolution = $this->snapshotResolver->resolve();
        $currentFingerprint = $resolution->resolvedFingerprint;

        $items = array_map(function (string $fingerprint) use ($latestFingerprint, $currentFingerprint): array {
            return [
                'fingerprint' => $fingerprint,
                'is_latest' => $fingerprint === $latestFingerprint,
                'is_current' => $fingerprint === $currentFingerprint,
                'is_active' => $fingerprint === $currentFingerprint,
            ];
        }, $fingerprints);

        return QueryResult::success([
            'snapshots' => $items,
            'latest_fingerprint' => $latestFingerprint,
            'active_fingerprint' => $currentFingerprint,
            'current_fingerprint' => $currentFingerprint,
            'count' => count($items),
        ])->withResolution($resolution->context());
    }

    public function diff(?string $from = null, ?string $to = null): QueryResult
    {
        $fingerprints = $this->repository->listFingerprints();
        if (count($fingerprints) < 2) {
            return QueryResult::typedError(
                type: 'insufficient_snapshots',
                message: 'At least two snapshots are required. Run `php artisan logic-map:build` after code changes.',
                httpStatus: 422,
            );
        }

        $toFingerprint = $to ?: $this->repository->getLatestFingerprint() ?: end($fingerprints);
        if (!is_string($toFingerprint) || !in_array($toFingerprint, $fingerprints, true)) {
            return QueryResult::typedError(
                type: 'snapshot_not_found',
                message: "Target snapshot '{$toFingerprint}' not found.",
                httpStatus: 404,
            );
        }

        $fromFingerprint = $from;
        if (!$fromFingerprint) {
            $toIndex = array_search($toFingerprint, $fingerprints, true);
            if ($toIndex === false || $toIndex === 0) {
                return QueryResult::typedError(
                    type: 'snapshot_not_found',
                    message: 'Could not infer source snapshot. Please provide `from` query parameter explicitly.',
                    httpStatus: 422,
                );
            }

            $fromFingerprint = $fingerprints[$toIndex - 1];
        }

        if (!in_array($fromFingerprint, $fingerprints, true)) {
            return QueryResult::typedError(
                type: 'snapshot_not_found',
                message: "Source snapshot '{$fromFingerprint}' not found.",
                httpStatus: 404,
            );
        }

        if ($fromFingerprint === $toFingerprint) {
            return QueryResult::typedError(
                type: 'invalid_snapshot_pair',
                message: 'Source and target snapshots must be different.',
                httpStatus: 422,
            );
        }

        $fromGraph = $this->repository->getSnapshot($fromFingerprint);
        $toGraph = $this->repository->getSnapshot($toFingerprint);
        if (!$fromGraph || !$toGraph) {
            return QueryResult::typedError(
                type: 'snapshot_not_found',
                message: 'Could not load one or both snapshots for diff.',
                httpStatus: 404,
            );
        }

        $data = $this->diffProjector->diff($fromGraph, $toGraph);
        $data['from_fingerprint'] = $fromFingerprint;
        $data['to_fingerprint'] = $toFingerprint;
        $data['available_snapshots'] = array_reverse($fingerprints);

        $resolvedVia = $to !== null && $to !== '' ? 'requested_snapshot' : 'latest_snapshot_fallback';
        $pointerState = $to !== null && $to !== '' ? 'bypassed' : 'ok';

        return QueryResult::success($data)->withResolution([
            'requested_snapshot' => $to !== null && $to !== '' ? $toFingerprint : null,
            'resolved_via' => $resolvedVia,
            'resolved_fingerprint' => $toFingerprint,
            'pointer_state' => $pointerState,
            'analysis_state' => 'not_requested',
        ]);
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
}
