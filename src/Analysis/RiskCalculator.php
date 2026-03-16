<?php

namespace dndark\LogicMap\Analysis;

use dndark\LogicMap\Domain\Graph;
use dndark\LogicMap\Domain\Violation;

/**
 * RiskCalculator — builds a NodeRiskMap with explainable risk assessments.
 *
 * Uses Cách B (ADR-013): Risk derives from BOTH violations AND metrics directly.
 * This ensures medium risk exists in Cut A even before HighInstability/HighCoupling analyzers ship.
 */
class RiskCalculator
{
    /**
     * Calculate risk for each node based on violations and metrics.
     *
     * @param Violation[] $violations
     * @return array<string, array{risk: string, score: int, reasons: string[]}>
     */
    public function calculate(Graph $graph, array $violations): array
    {
        $nodeRiskMap = [];

        // Index violations by nodeId
        $violationsByNode = [];
        foreach ($violations as $violation) {
            $violationsByNode[$violation->nodeId][] = $violation;
        }

        $thresholds = config('logic-map.analysis.thresholds', []);
        $weights = config('logic-map.analysis.weights', []);

        foreach ($graph->getNodes() as $node) {
            $score = 0;
            $reasons = [];

            // Score from violations
            $nodeViolations = $violationsByNode[$node->id] ?? [];
            foreach ($nodeViolations as $violation) {
                $weight = $weights[$violation->severity] ?? 1;
                $score += $weight;
                $reasons[] = $violation->type;
            }

            // Score from metrics directly (Cách B — ADR-013)
            // This allows medium risk without requiring Cut B analyzers
            $instability = $node->metrics['instability'] ?? 0;
            $coupling = $node->metrics['coupling'] ?? 0;
            $instabilityThreshold = $thresholds['high_instability'] ?? 0.9;
            $couplingThreshold = $thresholds['high_coupling'] ?? 20;

            if ($instability > $instabilityThreshold && ! $this->hasViolationType($nodeViolations, 'high_instability')) {
                $score += ($weights['medium'] ?? 5);
                $reasons[] = "instability={$instability} (threshold={$instabilityThreshold})";
            }

            if ($coupling > $couplingThreshold && ! $this->hasViolationType($nodeViolations, 'high_coupling')) {
                $score += ($weights['medium'] ?? 5);
                $reasons[] = "coupling={$coupling} (threshold={$couplingThreshold})";
            }

            // Only include nodes with non-zero risk
            if ($score > 0) {
                $nodeRiskMap[$node->id] = [
                    'risk' => $this->scoreToBucket($score),
                    'score' => $score,
                    'reasons' => $reasons,
                ];
            }
        }

        return $nodeRiskMap;
    }

    /**
     * Map numeric score to risk bucket.
     */
    protected function scoreToBucket(int $score): string
    {
        return match (true) {
            $score >= 20 => 'critical',
            $score >= 15 => 'high',
            $score >= 5  => 'medium',
            $score >= 1  => 'low',
            default      => 'healthy',
        };
    }

    /**
     * Check if node already has a violation of given type.
     *
     * @param Violation[] $violations
     */
    protected function hasViolationType(array $violations, string $type): bool
    {
        foreach ($violations as $v) {
            if ($v->type === $type) {
                return true;
            }
        }
        return false;
    }
}
