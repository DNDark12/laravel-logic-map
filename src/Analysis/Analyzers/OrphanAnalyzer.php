<?php

namespace dndark\LogicMap\Analysis\Analyzers;

use dndark\LogicMap\Contracts\ViolationAnalyzer;
use dndark\LogicMap\Domain\Enums\NodeKind;
use dndark\LogicMap\Domain\Graph;
use dndark\LogicMap\Domain\Violation;

/**
 * Detects orphan nodes — classes that nothing depends on (fan_in = 0).
 *
 * Scoped to eligible_kinds per ADR-014 to prevent false positives
 * from framework-resolved nodes (commands, events, listeners, etc.).
 */
class OrphanAnalyzer implements ViolationAnalyzer
{
    public function analyze(Graph $graph): array
    {
        $eligibleKinds = $this->getEligibleKinds();
        $ignoreIds = $this->getIgnoreNodeIds();
        $violations = [];

        foreach ($graph->getNodes() as $node) {
            // Only check eligible kinds
            if (! in_array($node->kind->value, $eligibleKinds, true)) {
                continue;
            }

            // Skip explicitly ignored nodes
            if (in_array($node->id, $ignoreIds, true)) {
                continue;
            }

            $fanIn = $node->metrics['fan_in'] ?? 0;

            if ($fanIn === 0) {
                $violations[] = new Violation(
                    type: 'orphan_class',
                    severity: 'low',
                    nodeId: $node->id,
                    message: "No other class depends on this {$node->kind->value} (fan_in = 0)",
                    details: [
                        'kind' => $node->kind->value,
                        'fan_in' => 0,
                    ],
                );
            }
        }

        return $violations;
    }

    /**
     * @return string[]
     */
    protected function getEligibleKinds(): array
    {
        return config(
            'logic-map.analysis.orphan.eligible_kinds',
            ['controller', 'service', 'model']
        );
    }

    /**
     * @return string[]
     */
    protected function getIgnoreNodeIds(): array
    {
        return config('logic-map.analysis.orphan.ignore_node_ids', []);
    }

    public function getName(): string
    {
        return 'orphan';
    }

    public function isEnabled(): bool
    {
        return (bool) config('logic-map.analysis.analyzers.orphan', true);
    }
}
