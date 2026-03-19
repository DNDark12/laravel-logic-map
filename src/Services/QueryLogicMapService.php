<?php

namespace dndark\LogicMap\Services;

use dndark\LogicMap\Analysis\ArchitectureAnalyzer;
use dndark\LogicMap\Contracts\GraphRepository;
use dndark\LogicMap\Domain\AnalysisReport;
use dndark\LogicMap\Domain\Graph;
use dndark\LogicMap\Projectors\GraphDiffProjector;
use dndark\LogicMap\Projectors\MetaProjector;
use dndark\LogicMap\Projectors\OverviewProjector;
use dndark\LogicMap\Projectors\SearchProjector;
use dndark\LogicMap\Projectors\SubgraphProjector;
use dndark\LogicMap\Support\FileDiscovery;
use dndark\LogicMap\Support\Fingerprint;

class QueryLogicMapService
{
    public function __construct(
        protected GraphRepository $repository,
        protected OverviewProjector $overviewProjector,
        protected SubgraphProjector $subgraphProjector,
        protected SearchProjector $searchProjector,
        protected MetaProjector $metaProjector,
        protected GraphDiffProjector $diffProjector,
        protected FileDiscovery $discovery,
        protected Fingerprint $fingerprint,
        protected ArchitectureAnalyzer $architectureAnalyzer,
    ) {
    }

    /**
     * Get overview projection.
     *
     * @return array{ok: bool, data: ?array, message: ?string}
     */
    public function getOverview(array $filters = [], ?string $snapshot = null): array
    {
        if (($error = $this->validateSnapshot($snapshot)) !== null) {
            return $error;
        }

        $graph = $this->getCurrentGraph($snapshot);

        if (!$graph) {
            return $this->errorResponse('No snapshot found. Run `php artisan logic-map:build` first.');
        }

        return $this->successResponse(
            $this->overviewProjector->overview($graph, $filters)
        );
    }

    /**
     * Get subgraph for a specific node.
     *
     * @return array{ok: bool, data: ?array, message: ?string}
     */
    public function getSubgraph(string $id, array $filters = [], ?string $snapshot = null): array
    {
        if (($error = $this->validateSnapshot($snapshot)) !== null) {
            return $error;
        }

        $graph = $this->getCurrentGraph($snapshot);

        if (!$graph) {
            return $this->errorResponse('No snapshot found. Run `php artisan logic-map:build` first.');
        }

        $nodes = $graph->getNodes();
        if (!isset($nodes[$id])) {
            return $this->errorResponse("Node '{$id}' not found.", 404);
        }

        return $this->successResponse(
            $this->subgraphProjector->subgraph($graph, $id, $filters)
        );
    }

    /**
     * Search nodes.
     *
     * @return array{ok: bool, data: ?array, message: ?string}
     */
    public function search(string $query, array $filters = [], ?string $snapshot = null): array
    {
        if (($error = $this->validateSnapshot($snapshot)) !== null) {
            return $error;
        }

        $graph = $this->getCurrentGraph($snapshot);

        if (!$graph) {
            return $this->errorResponse('No snapshot found. Run `php artisan logic-map:build` first.');
        }

        return $this->successResponse(
            $this->searchProjector->search($graph, $query, $filters)
        );
    }

    /**
     * Get graph metadata.
     *
     * @return array{ok: bool, data: ?array, message: ?string}
     */
    public function getMeta(?string $snapshot = null): array
    {
        if (($error = $this->validateSnapshot($snapshot)) !== null) {
            return $error;
        }

        $graph = $this->getCurrentGraph($snapshot);

        if (!$graph) {
            return $this->errorResponse('No snapshot found. Run `php artisan logic-map:build` first.');
        }

        $meta = $this->metaProjector->getMeta($graph);
        $meta['kind_labels'] = config('logic-map.analysis.kind_labels', []);
        $meta['ui_thresholds'] = config('logic-map.analysis.ui_thresholds', ['large_graph' => 150]);

        return $this->successResponse($meta);
    }

    /**
     * List available snapshots for time-travel view.
     *
     * @return array{ok: bool, data: ?array, message: ?string}
     */
    public function getSnapshots(): array
    {
        $fingerprints = $this->repository->listFingerprints();
        $latest = $this->repository->getLatestFingerprint();

        $scanPaths = config('logic-map.scan_paths');
        $files = $this->discovery->findFiles($scanPaths);
        $current = $this->fingerprint->generate($files);

        $items = array_map(function (string $fingerprint) use ($latest, $current): array {
            return [
                'fingerprint' => $fingerprint,
                'is_latest' => $fingerprint === $latest,
                'is_current' => $fingerprint === $current,
            ];
        }, array_reverse($fingerprints));

        return $this->successResponse([
            'snapshots' => $items,
            'latest_fingerprint' => $latest,
            'current_fingerprint' => $current,
            'count' => count($items),
        ]);
    }

    /**
     * Get structural diff between two snapshots.
     *
     * @return array{ok: bool, data: ?array, message: ?string}
     */
    public function getDiff(?string $from = null, ?string $to = null): array
    {
        $fingerprints = $this->repository->listFingerprints();
        if (count($fingerprints) < 2) {
            return $this->errorResponse('At least two snapshots are required. Run `php artisan logic-map:build` after code changes.', 422);
        }

        $toFingerprint = $to ?: $this->repository->getLatestFingerprint() ?: end($fingerprints);
        if (!is_string($toFingerprint) || !in_array($toFingerprint, $fingerprints, true)) {
            return $this->errorResponse("Target snapshot '{$toFingerprint}' not found.", 404);
        }

        $fromFingerprint = $from;
        if (!$fromFingerprint) {
            $toIndex = array_search($toFingerprint, $fingerprints, true);
            if ($toIndex === false || $toIndex === 0) {
                return $this->errorResponse('Could not infer source snapshot. Please provide `from` query parameter explicitly.', 422);
            }

            $fromFingerprint = $fingerprints[$toIndex - 1];
        }

        if (!in_array($fromFingerprint, $fingerprints, true)) {
            return $this->errorResponse("Source snapshot '{$fromFingerprint}' not found.", 404);
        }

        if ($fromFingerprint === $toFingerprint) {
            return $this->errorResponse('Source and target snapshots must be different.', 422);
        }

        $fromGraph = $this->repository->getSnapshot($fromFingerprint);
        $toGraph = $this->repository->getSnapshot($toFingerprint);

        if (!$fromGraph || !$toGraph) {
            return $this->errorResponse('Could not load one or both snapshots for diff.', 404);
        }

        $data = $this->diffProjector->diff($fromGraph, $toGraph);
        $data['from_fingerprint'] = $fromFingerprint;
        $data['to_fingerprint'] = $toFingerprint;
        $data['available_snapshots'] = array_reverse($fingerprints);

        return $this->successResponse($data);
    }

    /**
     * Get violations from cached AnalysisReport.
     *
     * @return array{ok: bool, data: ?array, message: ?string}
     */
    public function getViolations(array $filters = [], ?string $snapshot = null): array
    {
        if (($error = $this->validateSnapshot($snapshot)) !== null) {
            return $error;
        }

        $report = $this->getCurrentAnalysisReport($snapshot);

        if (!$report) {
            return $this->errorResponse('No analysis report found. Run `php artisan logic-map:build` first.');
        }

        $violations = $report->violations;

        // Filter by severity if requested
        if (!empty($filters['severity'])) {
            $violations = array_values(array_filter(
                $violations,
                fn($v) => $v->severity === $filters['severity']
            ));
        }

        // Filter by type if requested
        if (!empty($filters['type'])) {
            $violations = array_values(array_filter(
                $violations,
                fn($v) => $v->type === $filters['type']
            ));
        }

        return $this->successResponse([
            'violations' => array_map(fn($v) => $v->toArray(), $violations),
            'summary' => $report->summary,
        ]);
    }

    /**
     * Get health score from cached AnalysisReport.
     *
     * @return array{ok: bool, data: ?array, message: ?string}
     */
    public function getHealth(?string $snapshot = null): array
    {
        if (($error = $this->validateSnapshot($snapshot)) !== null) {
            return $error;
        }

        $report = $this->getCurrentAnalysisReport($snapshot);
        $graph = $this->getCurrentGraph($snapshot);

        if (!$report || !$graph) {
            return $this->errorResponse('No analysis report found. Run `php artisan logic-map:build` first.');
        }

        $nodes = $graph->getNodes();
        $edges = $graph->getEdges();

        // Calculate aggregate stats
        $fanOuts = array_map(fn($n) => $n->metrics['fan_out'] ?? 0, array_values($nodes));
        $depths = array_filter(array_map(fn($n) => $n->metrics['depth'] ?? null, array_values($nodes)));

        return $this->successResponse([
            'score' => $report->healthScore,
            'grade' => $report->grade,
            'summary' => $report->summary,
            'weights' => config('logic-map.analysis.weights', [
                'critical' => 25,
                'high' => 10,
                'medium' => 5,
                'low' => 1,
            ]),
            'labels' => config('logic-map.analysis.labels', []),
            'descriptions' => config('logic-map.analysis.descriptions', []),
            'severity_descriptions' => config('logic-map.analysis.severity_descriptions', []),
            'grade_scales' => config('logic-map.analysis.grade_scales', [
                90 => 'A', 80 => 'B', 70 => 'C', 60 => 'D', 0 => 'F'
            ]),
            'colors' => config('logic-map.analysis.colors', []),
            'graph_stats' => [
                'total_nodes' => count($nodes),
                'total_edges' => count($edges),
                'avg_fan_out' => count($fanOuts) > 0 ? round(array_sum($fanOuts) / count($fanOuts), 1) : 0,
                'max_depth' => !empty($depths) ? max($depths) : 0,
            ],
            'coverage_correlation' => $this->buildCoverageCorrelation($nodes, $report),
        ]);
    }

    /**
     * Export full analysis data as JSON — graph + analysis report combined.
     *
     * @return array{ok: bool, data: ?array, message: ?string}
     */
    public function exportJson(?string $snapshot = null): array
    {
        if (($error = $this->validateSnapshot($snapshot)) !== null) {
            return $error;
        }

        $graph = $this->getCurrentGraph($snapshot);
        $report = $this->getCurrentAnalysisReport($snapshot);

        if (!$graph) {
            return $this->errorResponse('No snapshot found. Run `php artisan logic-map:build` first.');
        }

        $nodes = [];
        foreach ($graph->getNodes() as $node) {
            $nodeData = $node->toArray();

            // Annotate with risk if available
            if ($report && isset($report->nodeRiskMap[$node->id])) {
                $nodeData['risk'] = $report->nodeRiskMap[$node->id];
            }

            $nodes[] = $nodeData;
        }

        $data = [
            'export_version' => '1.0',
            'generated_at' => now()->toIso8601String(),
            'graph' => [
                'nodes' => $nodes,
                'edges' => array_map(fn($e) => $e->toArray(), $graph->getEdges()),
            ],
        ];

        if ($report) {
            $data['analysis'] = [
                'health_score' => $report->healthScore,
                'grade' => $report->grade,
                'summary' => $report->summary,
                'violations' => array_map(fn($v) => $v->toArray(), $report->violations),
            ];
        }

        return $this->successResponse($data);
    }

    /**
     * Export analysis data as CSV rows — one row per node with metrics + risk.
     *
     * @return array{ok: bool, data: ?string, message: ?string}
     */
    public function exportCsv(?string $snapshot = null): array
    {
        if (($error = $this->validateSnapshot($snapshot)) !== null) {
            return $error;
        }

        $graph = $this->getCurrentGraph($snapshot);
        $report = $this->getCurrentAnalysisReport($snapshot);

        if (!$graph) {
            return $this->errorResponse('No snapshot found. Run `php artisan logic-map:build` first.');
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
        foreach ($graph->getNodes() as $node) {
            $risk = ($report && isset($report->nodeRiskMap[$node->id]))
                ? $report->nodeRiskMap[$node->id]
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

        // Build CSV string
        $output = fopen('php://temp', 'r+');
        fputcsv($output, $headers, $delimiter);
        foreach ($rows as $row) {
            fputcsv($output, $row, $delimiter);
        }
        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return [
            'ok' => true,
            'data' => $csv,
            'message' => null,
            'content_type' => 'text/csv',
            'filename' => 'logic-map-export.csv',
        ];
    }

    /**
     * Check if a snapshot exists.
     */
    public function hasSnapshot(): bool
    {
        return $this->getCurrentGraph() !== null;
    }

    protected function getCurrentGraph(?string $snapshot = null): ?Graph
    {
        if ($snapshot !== null && $snapshot !== '') {
            return $this->repository->getSnapshot($snapshot);
        }

        $scanPaths = config('logic-map.scan_paths');
        $files = $this->discovery->findFiles($scanPaths);
        $fingerprint = $this->fingerprint->generate($files);

        // Try current fingerprint first
        $graph = $this->repository->getSnapshot($fingerprint);

        // Fallback to latest if fingerprint changed
        if (!$graph) {
            $graph = $this->repository->getLatestSnapshot();
        }

        return $graph;
    }

    protected function getCurrentAnalysisReport(?string $snapshot = null): ?AnalysisReport
    {
        if ($snapshot !== null && $snapshot !== '') {
            $configHash = $this->architectureAnalyzer->getConfigHash();
            $report = $this->repository->getAnalysisReport($snapshot, $configHash);
            if (!$report) {
                $report = $this->repository->getAnalysisReport($snapshot);
            }

            return $report;
        }

        $scanPaths = config('logic-map.scan_paths');
        $files = $this->discovery->findFiles($scanPaths);
        $fingerprint = $this->fingerprint->generate($files);

        $configHash = $this->architectureAnalyzer->getConfigHash();

        // Try exact match first (fingerprint + configHash)
        $report = $this->repository->getAnalysisReport($fingerprint, $configHash);

        // Fallback to any report for this fingerprint
        if (!$report) {
            $report = $this->repository->getAnalysisReport($fingerprint);
        }

        return $report;
    }

    protected function successResponse(array $data): array
    {
        return [
            'ok' => true,
            'data' => $data,
            'message' => null,
        ];
    }

    protected function errorResponse(string $message, int $code = 400): array
    {
        return [
            'ok' => false,
            'data' => null,
            'message' => $message,
            'code' => $code,
        ];
    }

    protected function validateSnapshot(?string $snapshot): ?array
    {
        if ($snapshot === null || $snapshot === '') {
            return null;
        }

        if (!$this->repository->hasSnapshot($snapshot)) {
            return $this->errorResponse("Snapshot '{$snapshot}' not found.", 404);
        }

        return null;
    }

    /**
     * @param array<string, \dndark\LogicMap\Domain\Node> $nodes
     * @return array<string, mixed>|null
     */
    protected function buildCoverageCorrelation(array $nodes, AnalysisReport $report): ?array
    {
        if (!(bool)config('logic-map.coverage.enabled', true)) {
            return null;
        }

        $lowThreshold = (float)config('logic-map.coverage.low_threshold', 0.5);
        $highThreshold = (float)config('logic-map.coverage.high_threshold', 0.8);
        if ($highThreshold < $lowThreshold) {
            $highThreshold = $lowThreshold;
        }

        $assumeUncovered = (bool)config('logic-map.coverage.assume_uncovered_when_missing', false);
        $kinds = config('logic-map.coverage.correlation_kinds', ['controller', 'service', 'repository', 'model', 'job', 'component']);
        $riskLevels = config('logic-map.coverage.correlation_risk_levels', ['critical', 'high']);
        $kinds = is_array($kinds) ? array_values($kinds) : [];
        $riskLevels = is_array($riskLevels) ? array_values($riskLevels) : [];

        $eligibleNodes = 0;
        $nodesWithKnownCoverage = 0;
        $unknownCoverageNodes = 0;
        $uncoveredNodes = 0;
        $lowCoverageNodes = 0;
        $sumKnownRates = 0.0;

        $highRiskNodes = 0;
        $highRiskLowCoverage = 0;
        $highRiskUnknownCoverage = 0;
        $offenders = [];

        foreach ($nodes as $node) {
            if (!in_array($node->kind->value, $kinds, true)) {
                continue;
            }

            $eligibleNodes++;
            $coverage = is_array($node->metadata['coverage'] ?? null) ? $node->metadata['coverage'] : null;
            $rate = $this->extractCoverageRate($coverage);
            if ($rate === null && $assumeUncovered) {
                $rate = 0.0;
            }

            if ($rate === null) {
                $unknownCoverageNodes++;
            } else {
                $nodesWithKnownCoverage++;
                $sumKnownRates += $rate;

                if ($rate <= 0.0) {
                    $uncoveredNodes++;
                }

                if ($rate < $lowThreshold) {
                    $lowCoverageNodes++;
                }
            }

            $risk = $report->nodeRiskMap[$node->id]['risk'] ?? 'healthy';
            if (!in_array($risk, $riskLevels, true)) {
                continue;
            }

            $highRiskNodes++;
            if ($rate === null) {
                $highRiskUnknownCoverage++;
                continue;
            }

            if ($rate < $lowThreshold) {
                $highRiskLowCoverage++;
                $offenders[] = [
                    'node_id' => $node->id,
                    'kind' => $node->kind->value,
                    'name' => $node->metadata['shortLabel'] ?? $node->name ?? $node->id,
                    'risk' => $risk,
                    'coverage_percent' => (int)round($rate * 100),
                    'coverage_level' => $coverage['coverage_level'] ?? $this->rateToLevel($rate, $lowThreshold, $highThreshold),
                ];
            }
        }

        usort($offenders, function (array $a, array $b): int {
            $riskWeight = ['critical' => 3, 'high' => 2, 'medium' => 1, 'low' => 0];
            $aRisk = $riskWeight[$a['risk']] ?? -1;
            $bRisk = $riskWeight[$b['risk']] ?? -1;
            if ($aRisk !== $bRisk) {
                return $bRisk <=> $aRisk;
            }

            if ($a['coverage_percent'] !== $b['coverage_percent']) {
                return $a['coverage_percent'] <=> $b['coverage_percent'];
            }

            return $a['node_id'] <=> $b['node_id'];
        });

        $knownCoveragePercent = $nodesWithKnownCoverage > 0
            ? round(($sumKnownRates / $nodesWithKnownCoverage) * 100, 1)
            : null;
        $highRiskLowCoverageRate = $highRiskNodes > 0
            ? round(($highRiskLowCoverage / $highRiskNodes) * 100, 1)
            : 0.0;

        return [
            'enabled' => true,
            'report_loaded' => is_file((string)config('logic-map.coverage.clover_path', '')),
            'clover_path' => config('logic-map.coverage.clover_path'),
            'assume_uncovered_when_missing' => $assumeUncovered,
            'low_threshold' => $lowThreshold,
            'high_threshold' => $highThreshold,
            'eligible_nodes' => $eligibleNodes,
            'known_coverage_nodes' => $nodesWithKnownCoverage,
            'unknown_coverage_nodes' => $unknownCoverageNodes,
            'uncovered_nodes' => $uncoveredNodes,
            'low_coverage_nodes' => $lowCoverageNodes,
            'avg_known_coverage_percent' => $knownCoveragePercent,
            'high_risk_nodes' => $highRiskNodes,
            'high_risk_low_coverage' => $highRiskLowCoverage,
            'high_risk_unknown_coverage' => $highRiskUnknownCoverage,
            'high_risk_low_coverage_rate' => $highRiskLowCoverageRate,
            'top_offenders' => array_slice($offenders, 0, 8),
        ];
    }

    /**
     * @param array<string, mixed>|null $coverage
     */
    protected function extractCoverageRate(?array $coverage): ?float
    {
        if (!is_array($coverage) || !array_key_exists('line_rate', $coverage) || !is_numeric($coverage['line_rate'])) {
            return null;
        }

        return max(0.0, min(1.0, (float)$coverage['line_rate']));
    }

    protected function rateToLevel(float $rate, float $lowThreshold, float $highThreshold): string
    {
        if ($rate <= 0.0) {
            return 'none';
        }
        if ($rate < $lowThreshold) {
            return 'low';
        }
        if ($rate < $highThreshold) {
            return 'medium';
        }
        if ($rate < 1.0) {
            return 'high';
        }

        return 'full';
    }
}
