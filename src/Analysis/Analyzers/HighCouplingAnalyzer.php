<?php

namespace dndark\LogicMap\Analysis\Analyzers;

use dndark\LogicMap\Contracts\ViolationAnalyzer;
use dndark\LogicMap\Domain\Graph;
use dndark\LogicMap\Domain\Violation;

/**
 * Detects nodes with coupling (fan_in + fan_out) > threshold.
 * High coupling indicates a node is a central bottleneck.
 */
class HighCouplingAnalyzer implements ViolationAnalyzer
{
    public function analyze(Graph $graph): array
    {
        $threshold = config('logic-map.analysis.thresholds.high_coupling', 20);
        $violations = [];

        foreach ($graph->getAnalyzableNodes() as $node) {
            $coupling = $node->metrics['coupling'] ?? 0;

            if ($coupling > $threshold) {
                $violations[] = new Violation(
                    type: 'high_coupling',
                    severity: 'medium',
                    nodeId: $node->id,
                    message: "Coupling {$coupling} exceeds threshold {$threshold}",
                    details: [
                        'coupling' => $coupling,
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
        return 'high_coupling';
    }

    public function isEnabled(): bool
    {
        return (bool)config('logic-map.analysis.analyzers.high_coupling', false);
    }
}
