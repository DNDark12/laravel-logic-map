<?php

namespace dndark\LogicMap\Services;

use dndark\LogicMap\Analysis\ArchitectureAnalyzer;
use dndark\LogicMap\Contracts\GraphRepository;
use dndark\LogicMap\Domain\AnalysisReport;
use dndark\LogicMap\Domain\SnapshotResolution;
use Illuminate\Support\Facades\Log;

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
            [$analysis, $analysisState] = $this->resolveAnalysisContext($requestedSnapshot, $graph !== null, $includeAnalysis);

            return new SnapshotResolution(
                requestedFingerprint: $requestedSnapshot,
                resolvedFingerprint: $requestedSnapshot,
                resolvedVia: 'requested_snapshot',
                pointerState: 'bypassed',
                analysisState: $analysisState,
                graph: $graph,
                analysis: $analysis,
            );
        }

        $activeFingerprint = $this->repository->getActiveFingerprint();
        $latestFingerprint = $this->repository->getLatestFingerprint();

        if ($activeFingerprint !== null) {
            $graph = $this->repository->getSnapshot($activeFingerprint);
            if ($graph !== null) {
                [$analysis, $analysisState] = $this->resolveAnalysisContext($activeFingerprint, true, $includeAnalysis);

                return new SnapshotResolution(
                    requestedFingerprint: null,
                    resolvedFingerprint: $activeFingerprint,
                    resolvedVia: 'active_pointer',
                    pointerState: 'ok',
                    analysisState: $analysisState,
                    graph: $graph,
                    analysis: $analysis,
                );
            }

            if ($this->fallbackOnCorruptedPointer() && $latestFingerprint !== null && $latestFingerprint !== $activeFingerprint) {
                $latestGraph = $this->repository->getSnapshot($latestFingerprint);
                if ($latestGraph !== null) {
                    $this->logCorruptedPointerFallback($activeFingerprint, $latestFingerprint);
                    [$analysis, $analysisState] = $this->resolveAnalysisContext($latestFingerprint, true, $includeAnalysis);

                    return new SnapshotResolution(
                        requestedFingerprint: null,
                        resolvedFingerprint: $latestFingerprint,
                        resolvedVia: 'latest_snapshot_fallback',
                        pointerState: 'corrupted',
                        analysisState: $analysisState,
                        graph: $latestGraph,
                        analysis: $analysis,
                    );
                }
            }

            $this->logCorruptedPointerFallback($activeFingerprint, null);

            return new SnapshotResolution(
                requestedFingerprint: null,
                resolvedFingerprint: null,
                resolvedVia: 'active_pointer',
                pointerState: 'corrupted',
                analysisState: $includeAnalysis ? 'unresolved' : 'not_requested',
            );
        }

        if ($this->fallbackOnMissingPointer() && $latestFingerprint !== null) {
            $latestGraph = $this->repository->getSnapshot($latestFingerprint);
            if ($latestGraph !== null) {
                $this->logMissingPointerFallback($latestFingerprint);
                [$analysis, $analysisState] = $this->resolveAnalysisContext($latestFingerprint, true, $includeAnalysis);

                return new SnapshotResolution(
                    requestedFingerprint: null,
                    resolvedFingerprint: $latestFingerprint,
                    resolvedVia: 'latest_snapshot_fallback',
                    pointerState: 'missing',
                    analysisState: $analysisState,
                    graph: $latestGraph,
                    analysis: $analysis,
                );
            }
        }

        $this->logMissingPointerFallback(null);

        return new SnapshotResolution(
            requestedFingerprint: null,
            resolvedFingerprint: null,
            resolvedVia: 'active_pointer',
            pointerState: 'missing',
            analysisState: $includeAnalysis ? 'unresolved' : 'not_requested',
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
        if ($this->strictResolution()) {
            return false;
        }

        return (bool) config('logic-map.query.resolver.fallback_on_missing_pointer', true);
    }

    protected function fallbackOnCorruptedPointer(): bool
    {
        if ($this->strictResolution()) {
            return false;
        }

        return (bool) config('logic-map.query.resolver.fallback_on_corrupted_pointer', true);
    }

    /**
     * @return array{0: ?AnalysisReport, 1: string}
     */
    protected function resolveAnalysisContext(string $fingerprint, bool $hasGraph, bool $includeAnalysis): array
    {
        if (!$includeAnalysis) {
            return [null, 'not_requested'];
        }

        if (!$hasGraph) {
            return [null, 'unresolved'];
        }

        $analysis = $this->resolveAnalysis($fingerprint);
        if ($analysis !== null) {
            return [$analysis, 'available'];
        }

        $this->logMissingAnalysis($fingerprint);

        return [null, 'missing'];
    }

    protected function strictResolution(): bool
    {
        return (bool) config('logic-map.query.resolver.strict_resolution', false);
    }

    protected function logMissingPointerFallback(?string $resolvedFingerprint): void
    {
        Log::warning('Logic map resolver fallback: active pointer missing.', [
            'pointer_state' => 'missing',
            'resolved_via' => $resolvedFingerprint !== null ? 'latest_snapshot_fallback' : 'active_pointer',
            'resolved_fingerprint' => $resolvedFingerprint,
            'strict_resolution' => $this->strictResolution(),
        ]);
    }

    protected function logCorruptedPointerFallback(string $activeFingerprint, ?string $resolvedFingerprint): void
    {
        Log::warning('Logic map resolver fallback: active pointer corrupted.', [
            'pointer_state' => 'corrupted',
            'active_fingerprint' => $activeFingerprint,
            'resolved_via' => $resolvedFingerprint !== null ? 'latest_snapshot_fallback' : 'active_pointer',
            'resolved_fingerprint' => $resolvedFingerprint,
            'strict_resolution' => $this->strictResolution(),
        ]);
    }

    protected function logMissingAnalysis(string $fingerprint): void
    {
        Log::warning('Logic map resolver analysis unavailable for resolved snapshot.', [
            'resolved_fingerprint' => $fingerprint,
            'analysis_state' => 'missing',
        ]);
    }
}
