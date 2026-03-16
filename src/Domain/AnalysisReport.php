<?php

namespace dndark\LogicMap\Domain;

class AnalysisReport
{
    public function __construct(
        public array $violations,
        public int $healthScore,
        public string $grade,
        public array $summary,
        public array $nodeRiskMap,
        public array $metadata = [],
    ) {}

    public function toArray(): array
    {
        return [
            'violations' => array_map(
                fn(Violation $v) => $v->toArray(),
                $this->violations
            ),
            'health_score' => $this->healthScore,
            'grade' => $this->grade,
            'summary' => $this->summary,
            'node_risk_map' => $this->nodeRiskMap,
            'metadata' => $this->metadata,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            violations: array_map(
                fn(array $v) => Violation::fromArray($v),
                $data['violations'] ?? []
            ),
            healthScore: $data['health_score'] ?? 0,
            grade: $data['grade'] ?? 'F',
            summary: $data['summary'] ?? [],
            nodeRiskMap: $data['node_risk_map'] ?? [],
            metadata: $data['metadata'] ?? [],
        );
    }

    /**
     * Get violations filtered by severity.
     *
     * @return Violation[]
     */
    public function getViolationsBySeverity(string $severity): array
    {
        return array_values(array_filter(
            $this->violations,
            fn(Violation $v) => $v->severity === $severity
        ));
    }

    /**
     * Get violations for a specific node.
     *
     * @return Violation[]
     */
    public function getViolationsForNode(string $nodeId): array
    {
        return array_values(array_filter(
            $this->violations,
            fn(Violation $v) => $v->nodeId === $nodeId
        ));
    }

    /**
     * Get the risk info for a specific node.
     */
    public function getNodeRisk(string $nodeId): ?array
    {
        return $this->nodeRiskMap[$nodeId] ?? null;
    }
}
