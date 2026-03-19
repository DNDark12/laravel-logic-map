<?php

namespace dndark\LogicMap\Services;

use dndark\LogicMap\Domain\AnalysisReport;
use dndark\LogicMap\Domain\QueryResult;
use dndark\LogicMap\Domain\SnapshotResolution;

class AnalysisReadService
{
    public function __construct(
        protected SnapshotResolver $snapshotResolver,
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

        $nodes = $resolution->graph->getNodes();
        $edges = $resolution->graph->getEdges();
        $fanOuts = array_map(fn($node) => $node->metrics['fan_out'] ?? 0, array_values($nodes));
        $depths = array_filter(array_map(fn($node) => $node->metrics['depth'] ?? null, array_values($nodes)));

        return QueryResult::success([
            'score' => $resolution->analysis->healthScore,
            'grade' => $resolution->analysis->grade,
            'summary' => $resolution->analysis->summary,
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
                90 => 'A', 80 => 'B', 70 => 'C', 60 => 'D', 0 => 'F',
            ]),
            'colors' => config('logic-map.analysis.colors', []),
            'graph_stats' => [
                'total_nodes' => count($nodes),
                'total_edges' => count($edges),
                'avg_fan_out' => count($fanOuts) > 0 ? round(array_sum($fanOuts) / count($fanOuts), 1) : 0,
                'max_depth' => !empty($depths) ? max($depths) : 0,
            ],
            'coverage_correlation' => $this->buildCoverageCorrelation($nodes, $resolution->analysis),
        ])->withResolution($resolution->context());
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

    /**
     * @param array<string, \dndark\LogicMap\Domain\Node> $nodes
     * @return array<string, mixed>|null
     */
    protected function buildCoverageCorrelation(array $nodes, AnalysisReport $report): ?array
    {
        if (!(bool) config('logic-map.coverage.enabled', true)) {
            return null;
        }

        $lowThreshold = (float) config('logic-map.coverage.low_threshold', 0.5);
        $highThreshold = (float) config('logic-map.coverage.high_threshold', 0.8);
        if ($highThreshold < $lowThreshold) {
            $highThreshold = $lowThreshold;
        }

        $assumeUncovered = (bool) config('logic-map.coverage.assume_uncovered_when_missing', false);
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
                    'coverage_percent' => (int) round($rate * 100),
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
            'report_loaded' => is_file((string) config('logic-map.coverage.clover_path', '')),
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

        return max(0.0, min(1.0, (float) $coverage['line_rate']));
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
