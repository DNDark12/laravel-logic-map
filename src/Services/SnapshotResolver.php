<?php

namespace dndark\LogicMap\Services;

use dndark\LogicMap\Analysis\ArchitectureAnalyzer;
use dndark\LogicMap\Contracts\GraphRepository;
use dndark\LogicMap\Domain\AnalysisReport;
use dndark\LogicMap\Domain\SnapshotResolution;

class SnapshotResolver
{
    public function __construct(
        protected GraphRepository $repository,
        protected ArchitectureAnalyzer $architectureAnalyzer,
    ) {
    }

    public function resolve(?string $requestedSnapshot = null, bool $includeAnalysis = false): SnapshotResolution
    {
        $requestedSnapshot = is_string($requestedSnapshot) && $requestedSnapshot !== ''
            ? $requestedSnapshot
            : null;

        if ($requestedSnapshot !== null) {
            $graph = $this->repository->getSnapshot($requestedSnapshot);

            return new SnapshotResolution(
                requestedFingerprint: $requestedSnapshot,
                resolvedFingerprint: $requestedSnapshot,
                resolvedVia: 'requested_snapshot',
                pointerState: 'bypassed',
                graph: $graph,
                analysis: $includeAnalysis && $graph ? $this->resolveAnalysis($requestedSnapshot) : null,
            );
        }

        $activeFingerprint = $this->repository->getActiveFingerprint();
        $latestFingerprint = $this->repository->getLatestFingerprint();

        if ($activeFingerprint !== null) {
            $graph = $this->repository->getSnapshot($activeFingerprint);
            if ($graph !== null) {
                return new SnapshotResolution(
                    requestedFingerprint: null,
                    resolvedFingerprint: $activeFingerprint,
                    resolvedVia: 'active_pointer',
                    pointerState: 'ok',
                    graph: $graph,
                    analysis: $includeAnalysis ? $this->resolveAnalysis($activeFingerprint) : null,
                );
            }

            if ($this->fallbackOnCorruptedPointer() && $latestFingerprint !== null && $latestFingerprint !== $activeFingerprint) {
                $latestGraph = $this->repository->getSnapshot($latestFingerprint);
                if ($latestGraph !== null) {
                    return new SnapshotResolution(
                        requestedFingerprint: null,
                        resolvedFingerprint: $latestFingerprint,
                        resolvedVia: 'latest_snapshot_fallback',
                        pointerState: 'corrupted',
                        graph: $latestGraph,
                        analysis: $includeAnalysis ? $this->resolveAnalysis($latestFingerprint) : null,
                    );
                }
            }

            return new SnapshotResolution(
                requestedFingerprint: null,
                resolvedFingerprint: null,
                resolvedVia: 'active_pointer',
                pointerState: 'corrupted',
            );
        }

        if ($this->fallbackOnMissingPointer() && $latestFingerprint !== null) {
            $latestGraph = $this->repository->getSnapshot($latestFingerprint);
            if ($latestGraph !== null) {
                return new SnapshotResolution(
                    requestedFingerprint: null,
                    resolvedFingerprint: $latestFingerprint,
                    resolvedVia: 'latest_snapshot_fallback',
                    pointerState: 'missing',
                    graph: $latestGraph,
                    analysis: $includeAnalysis ? $this->resolveAnalysis($latestFingerprint) : null,
                );
            }
        }

        return new SnapshotResolution(
            requestedFingerprint: null,
            resolvedFingerprint: null,
            resolvedVia: 'active_pointer',
            pointerState: 'missing',
        );
    }

    protected function resolveAnalysis(string $fingerprint): ?AnalysisReport
    {
        $configHash = $this->architectureAnalyzer->getConfigHash();
        $report = $this->repository->getAnalysisReport($fingerprint, $configHash);

        if ($report !== null) {
            return $report;
        }

        return $this->repository->getAnalysisReport($fingerprint);
    }

    protected function fallbackOnMissingPointer(): bool
    {
        return (bool) config('logic-map.query.resolver.fallback_on_missing_pointer', true);
    }

    protected function fallbackOnCorruptedPointer(): bool
    {
        return (bool) config('logic-map.query.resolver.fallback_on_corrupted_pointer', true);
    }
}
