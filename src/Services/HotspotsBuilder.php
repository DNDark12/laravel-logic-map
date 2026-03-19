<?php

namespace dndark\LogicMap\Services;

use dndark\LogicMap\Analysis\Support\ModuleExtractor;
use dndark\LogicMap\Domain\AnalysisReport;
use dndark\LogicMap\Domain\Graph;

class HotspotsBuilder
{
    public function build(Graph $graph, AnalysisReport $report, array $filters = []): array
    {
        $limit = (int) ($filters['limit'] ?? 10);
        $limit = $limit > 0 ? min($limit, 100) : 10;
        $kindFilter = isset($filters['kind']) && is_string($filters['kind']) && $filters['kind'] !== ''
            ? $filters['kind']
            : null;
        $moduleFilter = isset($filters['module']) && is_string($filters['module']) && $filters['module'] !== ''
            ? $filters['module']
            : null;
        $riskFilter = isset($filters['risk']) && is_string($filters['risk']) && $filters['risk'] !== ''
            ? $filters['risk']
            : null;

        $items = [];
        foreach ($graph->getNodes() as $node) {
            $riskInfo = $report->nodeRiskMap[$node->id] ?? null;
            if (!is_array($riskInfo)) {
                continue;
            }

            $kind = $node->kind->value;
            $module = $node->metadata['module'] ?? ModuleExtractor::moduleOf($node->id);
            $risk = (string) ($riskInfo['risk'] ?? 'none');
            $riskScore = (int) ($riskInfo['score'] ?? 0);

            if ($kindFilter !== null && $kind !== $kindFilter) {
                continue;
            }

            if ($moduleFilter !== null && $module !== $moduleFilter) {
                continue;
            }

            if ($riskFilter !== null && $risk !== $riskFilter) {
                continue;
            }

            $items[] = [
                'node_id' => $node->id,
                'kind' => $kind,
                'name' => $node->metadata['shortLabel'] ?? $node->name ?? $node->id,
                'module' => $module,
                'risk' => $risk,
                'risk_score' => $riskScore,
                'coupling' => (float) ($node->metrics['coupling'] ?? 0),
                'instability' => (float) ($node->metrics['instability'] ?? 0),
                'fan_out' => (int) ($node->metrics['fan_out'] ?? 0),
                'depth' => $node->metrics['depth'] ?? null,
                'reasons' => array_values($riskInfo['reasons'] ?? []),
            ];
        }

        usort($items, function (array $a, array $b): int {
            if ($a['risk_score'] !== $b['risk_score']) {
                return $b['risk_score'] <=> $a['risk_score'];
            }

            if ($a['coupling'] !== $b['coupling']) {
                return $b['coupling'] <=> $a['coupling'];
            }

            if ($a['instability'] !== $b['instability']) {
                return $b['instability'] <=> $a['instability'];
            }

            if ($a['fan_out'] !== $b['fan_out']) {
                return $b['fan_out'] <=> $a['fan_out'];
            }

            return $a['node_id'] <=> $b['node_id'];
        });

        return [
            'items' => array_slice($items, 0, $limit),
            'meta' => [
                'total' => count($items),
                'count' => min(count($items), $limit),
                'limit' => $limit,
                'filters' => [
                    'kind' => $kindFilter,
                    'module' => $moduleFilter,
                    'risk' => $riskFilter,
                ],
            ],
        ];
    }
}
