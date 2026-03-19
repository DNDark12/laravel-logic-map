<?php

namespace dndark\LogicMap\Analysis\Analyzers;

use dndark\LogicMap\Contracts\ViolationAnalyzer;
use dndark\LogicMap\Domain\Graph;
use dndark\LogicMap\Domain\Violation;

/**
 * Detects nodes that are unreachable from route entrypoints.
 *
 * Uses precomputed MetricsCalculator depth:
 * - depth = null means node is not reachable from any configured entrypoint.
 * - Scoped to eligible kinds to avoid framework/runtime noise.
 */
class DeadCodeAnalyzer implements ViolationAnalyzer
{
    public function analyze(Graph $graph): array
    {
        $eligibleKinds = $this->getEligibleKinds();
        $ignoreIds = $this->getIgnoreNodeIds();
        $violations = [];

        foreach ($graph->getNodes() as $node) {
            if (!in_array($node->kind->value, $eligibleKinds, true)) {
                continue;
            }

            if (in_array($node->id, $ignoreIds, true)) {
                continue;
            }

            $depth = $node->metrics['depth'] ?? null;
            if ($depth !== null) {
                continue;
            }

            $fanIn = (int)($node->metrics['fan_in'] ?? 0);
            $fanOut = (int)($node->metrics['fan_out'] ?? 0);

            $violations[] = new Violation(
                type: 'dead_code',
                severity: 'low',
                nodeId: $node->id,
                message: $this->buildMessage($node->kind->value, $fanIn, $fanOut),
                details: [
                    'kind' => $node->kind->value,
                    'depth' => null,
                    'fan_in' => $fanIn,
                    'fan_out' => $fanOut,
                    'unreachable_from' => 'route',
                ],
            );
        }

        return $violations;
    }

    /**
     * @return string[]
     */
    protected function getEligibleKinds(): array
    {
        return config(
            'logic-map.analysis.dead_code.eligible_kinds',
            ['controller', 'service', 'repository', 'model', 'job', 'component']
        );
    }

    /**
     * @return string[]
     */
    protected function getIgnoreNodeIds(): array
    {
        return config('logic-map.analysis.dead_code.ignore_node_ids', []);
    }

    protected function buildMessage(string $kind, int $fanIn, int $fanOut): string
    {
        if ($fanIn === 0 && $fanOut === 0) {
            return "Isolated {$kind} is unreachable from any route entrypoint.";
        }

        return "Unreachable {$kind} from any route entrypoint (depth = null).";
    }

    public function getName(): string
    {
        return 'dead_code';
    }

    public function isEnabled(): bool
    {
        return (bool)config('logic-map.analysis.analyzers.dead_code', true);
    }
}

