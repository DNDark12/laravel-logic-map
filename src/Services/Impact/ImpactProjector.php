<?php

namespace dndark\LogicMap\Services\Impact;

use dndark\LogicMap\Analysis\Support\ModuleExtractor;
use dndark\LogicMap\Domain\AnalysisReport;
use dndark\LogicMap\Domain\ChangeImpactReport;
use dndark\LogicMap\Domain\Graph;
use dndark\LogicMap\Domain\Node;
use dndark\LogicMap\Support\Traversal\GraphWalker;
use dndark\LogicMap\Support\Traversal\TraversalPolicy;
use dndark\LogicMap\Support\Traversal\WalkStep;

/**
 * Projects impact data for a target node.
 *
 * Scoring formula (from docs/v2/api/impact-analysis.md):
 *   blast_radius_score = min(100,
 *     2*downstream_count + 1*upstream_count
 *     + 6*persistence_touch_count + 5*async_boundary_count
 *     + 4*high_risk_touch_count + 3*cross_module_touch_count
 *     + 4*high_risk_low_coverage_touch_count
 *   )
 *
 * Risk bucket: critical>=70, high>=40, medium>=20, low>=1, healthy=0
 */
class ImpactProjector
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
    ): ChangeImpactReport {
        // 1 — Walk upstream, downstream, or both
        $upstreamSteps   = [];
        $downstreamSteps = [];

        if ($direction === 'upstream' || $direction === 'both') {
            $upstreamSteps = $this->walker->walk($graph, $targetNode->id, 'upstream', $maxDepth);
        }

        if ($direction === 'downstream' || $direction === 'both') {
            $downstreamSteps = $this->walker->walk($graph, $targetNode->id, 'downstream', $maxDepth);
        }

        $targetModule = $targetNode->metadata['module'] ?? ModuleExtractor::moduleOf($targetNode->id);

        // 2 — Project impacted node rows
        $upstreamItems   = $this->projectItems($upstreamSteps, $report, $targetModule);
        $downstreamItems = $this->projectItems($downstreamSteps, $report, $targetModule);

        $allItems = array_merge($upstreamItems, $downstreamItems);

        // 3 — Classify critical touches
        $criticalTouches = $this->classifyCriticalTouches($allItems, $downstreamSteps, $upstreamSteps);

        // 4 — Build review scope
        $reviewScope = $this->buildReviewScope(
            $targetNode,
            $criticalTouches,
            $downstreamSteps,
            $upstreamSteps,
            $allItems,
            $report,
            $direction,
        );

        // 5 — Score
        $persistenceCount    = 0;
        $asyncBoundaryCount  = 0;
        $highRiskCount       = 0;
        $crossModuleCount    = 0;
        $highRiskLowCovCount = 0;

        foreach ($allItems as $item) {
            if (TraversalPolicy::isPersistenceKind($item['kind'])) {
                $persistenceCount++;
            }
            if ($item['async_boundary'] ?? false) {
                $asyncBoundaryCount++;
            }
            if (TraversalPolicy::isHighRisk($item['risk'] ?? null)) {
                $highRiskCount++;
                if (TraversalPolicy::isLowCoverage($item['coverage_level'] ?? null)) {
                    $highRiskLowCovCount++;
                }
            }
            if (($item['module'] ?? null) !== null && $item['module'] !== $targetModule) {
                $crossModuleCount++;
            }
        }

        $downstreamCount = count($downstreamItems);
        $upstreamCount   = count($upstreamItems);

        $score = min(100,
            2 * $downstreamCount +
            1 * $upstreamCount +
            6 * $persistenceCount +
            5 * $asyncBoundaryCount +
            4 * $highRiskCount +
            3 * $crossModuleCount +
            4 * $highRiskLowCovCount
        );

        $riskBucket = match (true) {
            $score >= 70 => 'critical',
            $score >= 40 => 'high',
            $score >= 20 => 'medium',
            $score >= 1  => 'low',
            default      => 'healthy',
        };

        // 6 — Target identity
        $riskInfo = $report->getNodeRisk($targetNode->id);

        $target = [
            'node_id' => $targetNode->id,
            'kind'    => $targetNode->kind->value,
            'name'    => $targetNode->metadata['shortLabel'] ?? $targetNode->name ?? $targetNode->id,
            'module'  => $targetModule,
            'risk'    => $riskInfo['risk'] ?? 'none',
        ];

        $summary = [
            'direction'                       => $direction,
            'max_depth'                       => $maxDepth,
            'upstream_count'                  => $upstreamCount,
            'downstream_count'                => $downstreamCount,
            'async_boundary_count'            => $asyncBoundaryCount,
            'persistence_touch_count'         => $persistenceCount,
            'cross_module_touch_count'        => $crossModuleCount,
            'high_risk_touch_count'           => $highRiskCount,
            'high_risk_low_coverage_touch_count' => $highRiskLowCovCount,
            'blast_radius_score'              => $score,
            'risk_bucket'                     => $riskBucket,
        ];

        return new ChangeImpactReport(
            target:          $target,
            summary:         $summary,
            upstream:        $upstreamItems,
            downstream:      $downstreamItems,
            criticalTouches: $criticalTouches,
            reviewScope:     $reviewScope,
        );
    }

    /**
     * Project WalkSteps into impacted-node row arrays.
     */
    private function projectItems(array $steps, AnalysisReport $report, string $targetModule): array
    {
        $items = [];
        foreach ($steps as $step) {
            /** @var WalkStep $step */
            $node     = $step->node;
            $riskInfo = $report->getNodeRisk($node->id);
            $module   = $node->metadata['module'] ?? ModuleExtractor::moduleOf($node->id);

            $reasons = [];
            if ($step->asyncBoundary) {
                $reasons[] = 'async_boundary';
            }
            if (TraversalPolicy::isPersistenceKind($node->kind->value)) {
                $reasons[] = 'persistence';
            }
            if ($module !== $targetModule) {
                $reasons[] = 'cross_module';
            }

            $items[] = [
                'node_id'        => $node->id,
                'kind'           => $node->kind->value,
                'name'           => $node->metadata['shortLabel'] ?? $node->name ?? $node->id,
                'module'         => $module,
                'depth'          => $step->depth,
                'risk'           => $riskInfo['risk'] ?? 'none',
                'risk_score'     => $riskInfo['score'] ?? 0,
                'coverage_level' => $node->metadata['coverage_level'] ?? null,
                'async_boundary' => $step->asyncBoundary,
                'reasons'        => $reasons,
            ];
        }
        return $items;
    }

    /**
     * Classify critical touches from projected items.
     */
    private function classifyCriticalTouches(array $allItems, array $downstreamSteps, array $upstreamSteps): array
    {
        $critical = [];
        foreach ($allItems as $item) {
            $isCritical =
                TraversalPolicy::isPersistenceKind($item['kind']) ||
                ($item['async_boundary'] ?? false) ||
                TraversalPolicy::isHighRisk($item['risk'] ?? null);

            if ($isCritical) {
                $critical[$item['node_id']] = $item;
            }
        }
        return array_values($critical);
    }

    /**
     * Build review_scope from target + critical touches + direct 1-hop neighbors.
     */
    private function buildReviewScope(
        Node           $targetNode,
        array          $criticalTouches,
        array          $downstreamSteps,
        array          $upstreamSteps,
        array          $allItems,
        AnalysisReport $report,
        string         $direction,
    ): array {
        // Map all projected items by node ID for fast lookup
        $itemMap = [];
        foreach ($allItems as $item) {
            $itemMap[$item['node_id']] = $item;
        }

        // Add target node to the map if not already present
        if (!isset($itemMap[$targetNode->id])) {
            $riskInfo = $report->getNodeRisk($targetNode->id);
            $itemMap[$targetNode->id] = [
                'node_id'        => $targetNode->id,
                'kind'           => $targetNode->kind->value,
                'name'           => $targetNode->metadata['shortLabel'] ?? $targetNode->name ?? $targetNode->id,
                'module'         => $targetNode->metadata['module'] ?? ModuleExtractor::moduleOf($targetNode->id),
                'risk'           => $riskInfo['risk'] ?? 'none',
                'risk_score'     => $riskInfo['score'] ?? 0,
                'coverage_level' => $targetNode->metadata['coverage_level'] ?? null,
                'depth'          => 0,
            ];
        }

        // must_review: target + critical-touch IDs + direct 1-hop neighbors
        $mustReviewIds = [$targetNode->id => 'Target Entrypoint'];

        foreach ($criticalTouches as $ct) {
            $mustReviewIds[$ct['node_id']] = 'Critical Impact Area';
        }

        // Direct 1-hop neighbors in chosen direction
        $directSteps = array_filter(
            array_merge($downstreamSteps, $upstreamSteps),
            fn(WalkStep $s) => $s->depth === 1
        );
        foreach ($directSteps as $step) {
            if (!isset($mustReviewIds[$step->node->id])) {
                $mustReviewIds[$step->node->id] = 'Direct Immediate Neighbor';
            }
        }

        // should_review: remaining impacted nodes not in must_review
        $shouldReviewIds = [];
        foreach ($allItems as $item) {
            if (!isset($mustReviewIds[$item['node_id']])) {
                $shouldReviewIds[$item['node_id']] = 'Within Blast Radius';
            }
        }

        // test_focus: high-risk low/unknown coverage, ordered by risk desc then coverage
        $testFocusIds = [];
        foreach ($allItems as $item) {
            if (TraversalPolicy::isHighRisk($item['risk'] ?? null) &&
                TraversalPolicy::isLowCoverage($item['coverage_level'] ?? null)) {
                $testFocusIds[] = $item;
            }
        }

        usort($testFocusIds, function (array $a, array $b): int {
            $riskOrder = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3, 'none' => 4];
            $rA = $riskOrder[$a['risk'] ?? 'none'] ?? 4;
            $rB = $riskOrder[$b['risk'] ?? 'none'] ?? 4;
            return $rA !== $rB ? $rA <=> $rB : $a['node_id'] <=> $b['node_id'];
        });

        $mapToRow = function (string $nodeId, string $why) use ($itemMap): array {
            $item = $itemMap[$nodeId];
            return (new \dndark\LogicMap\Domain\Impact\ReviewScopeRow(
                node_id: $item['node_id'],
                kind: $item['kind'],
                name: $item['name'],
                module: $item['module'] ?? 'unknown',
                risk: $item['risk'] ?? 'none',
                risk_score: $item['risk_score'] ?? 0,
                coverage_level: $item['coverage_level'] ?? 'unknown',
                depth: $item['depth'] ?? null,
                why_included: $why,
            ))->toArray();
        };

        $mustReview = [];
        foreach ($mustReviewIds as $id => $why) {
            $mustReview[] = $mapToRow($id, $why);
        }

        $shouldReview = [];
        foreach ($shouldReviewIds as $id => $why) {
            $shouldReview[] = $mapToRow($id, $why);
        }

        $testFocus = [];
        foreach ($testFocusIds as $item) {
            $testFocus[] = $mapToRow($item['node_id'], 'High Risk & Low Coverage Code');
        }

        return [
            'must_review'   => $mustReview,
            'should_review' => $shouldReview,
            'test_focus'    => $testFocus,
        ];
    }
}
