<?php

namespace dndark\LogicMap\Analysis\Analyzers;

use dndark\LogicMap\Contracts\ViolationAnalyzer;
use dndark\LogicMap\Domain\Graph;
use dndark\LogicMap\Domain\Violation;

/**
 * Detects nodes with instability > threshold.
 * Instability = fan_out / (fan_in + fan_out). Value 1.0 = maximally unstable.
 */
class HighInstabilityAnalyzer implements ViolationAnalyzer
{
    public function analyze(Graph $graph): array
    {
        $threshold = config('logic-map.analysis.thresholds.high_instability', 0.9);
        $violations = [];

        foreach ($graph->getAnalyzableNodes() as $node) {
            $instability = $node->metrics['instability'] ?? 0;
            $coupling = $node->metrics['coupling'] ?? 0;

            // Skip isolated nodes (coupling=0 means instability is meaningless)
            if ($coupling === 0) {
                continue;
            }

            if ($instability > $threshold) {
                $violations[] = new Violation(
                    type: 'high_instability',
                    severity: 'medium',
                    nodeId: $node->id,
                    message: "Instability {$instability} exceeds threshold {$threshold}",
                    details: [
                        'instability' => $instability,
                        'threshold' => $threshold,
                        'fan_in' => $node->metrics['fan_in'] ?? 0,
                        'fan_out' => $node->metrics['fan_out'] ?? 0,
                    ],
                );
            }
        }

        return $violations;
    }

    public function getName(): string
    {
        return 'high_instability';
    }

    public function isEnabled(): bool
    {
        return (bool)config('logic-map.analysis.analyzers.high_instability', false);
    }
}
