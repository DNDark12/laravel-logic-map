<?php

namespace dndark\LogicMap\Analysis\Analyzers;

use dndark\LogicMap\Contracts\ViolationAnalyzer;
use dndark\LogicMap\Domain\Enums\NodeKind;
use dndark\LogicMap\Domain\Graph;
use dndark\LogicMap\Domain\Violation;

class FatControllerAnalyzer implements ViolationAnalyzer
{
    public function analyze(Graph $graph): array
    {
        $threshold = config('logic-map.analysis.thresholds.fat_controller_fan_out', 10);
        $violations = [];

        foreach ($graph->getNodesByKind(NodeKind::CONTROLLER) as $node) {
            $fanOut = $node->metrics['fan_out'] ?? 0;

            if ($fanOut > $threshold) {
                $violations[] = new Violation(
                    type: 'fat_controller',
                    severity: 'high',
                    nodeId: $node->id,
                    message: "Controller has {$fanOut} dependencies (threshold: {$threshold})",
                    details: [
                        'fan_out' => $fanOut,
                        'threshold' => $threshold,
                    ],
                );
            }
        }

        return $violations;
    }

    public function getName(): string
    {
        return 'fat_controller';
    }

    public function isEnabled(): bool
    {
        return (bool) config('logic-map.analysis.analyzers.fat_controller', true);
    }
}
