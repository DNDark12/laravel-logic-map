<?php

namespace DNDark\LogicMap\Projectors;

use DNDark\LogicMap\Domain\Impact\AffectedSymbol;
use DNDark\LogicMap\Domain\Impact\ChangedSymbol;
use DNDark\LogicMap\Domain\Impact\ChangeType;
use DNDark\LogicMap\Domain\Impact\ImpactCategory;
use DNDark\LogicMap\Domain\Impact\ImpactReason;
use DNDark\LogicMap\Domain\Impact\ImpactReport;

final class ImpactJsonProjector
{
    public function project(ImpactReport $report): array
    {
        $counts = array_fill_keys(array_map(static fn (ChangeType $type): string => $type->value, ChangeType::cases()), 0);

        foreach ($report->changedSymbols as $change) {
            $counts[$change->changeType->value]++;
        }

        $affected = array_map(static fn (AffectedSymbol $symbol): array => $symbol->toArray(), $report->affectedSymbols);

        return [
            'change_set' => [
                'count' => count($report->changedSymbols),
                'by_type' => $counts,
            ],
            'summary' => [
                'changed_symbol_count' => count($report->changedSymbols),
                'affected_symbol_count' => count($report->affectedSymbols),
                'affected_module_count' => count(array_unique(array_column(
                    $this->category($report, ImpactCategory::Module),
                    'node_id',
                ))),
                'selected_test_count' => count($report->selectedTests),
                'uncertainty_count' => count($this->category($report, ImpactCategory::Uncertainty)),
            ],
            'changed_symbols' => array_map($this->change(...), $report->changedSymbols),
            'affected_symbols' => $affected,
            'workflows' => $this->category($report, ImpactCategory::Workflow),
            'modules' => $this->category($report, ImpactCategory::Module),
            'shared_resources' => $this->category($report, ImpactCategory::SharedState),
            'external_contracts' => $this->category($report, ImpactCategory::ExternalContract),
            'tests' => $report->selectedTests,
            'uncertainty' => $this->category($report, ImpactCategory::Uncertainty),
            'truncation' => $report->truncation,
            'evidence' => array_map(static fn ($record): array => [
                'id' => $record->id(),
                ...$record->toArray(),
            ], $report->evidence),
        ];
    }

    private function change(ChangedSymbol $change): array
    {
        return [
            'change_type' => $change->changeType->value,
            'old_node_id' => $change->oldNodeId?->value,
            'new_node_id' => $change->newNodeId?->value,
            'old_path' => $change->oldPath,
            'new_path' => $change->newPath,
            'old_start_line' => $change->oldStartLine,
            'old_end_line' => $change->oldEndLine,
            'new_start_line' => $change->newStartLine,
            'new_end_line' => $change->newEndLine,
            'evidence_id' => $change->evidence->id(),
            'attributes' => $change->attributes,
        ];
    }

    private function category(ImpactReport $report, ImpactCategory $category): array
    {
        $rows = [];

        foreach ($report->affectedSymbols as $symbol) {
            foreach ($symbol->reasons as $reason) {
                if ($reason->category !== $category) {
                    continue;
                }

                $row = [
                    'node_id' => $symbol->nodeId->value,
                    'reason' => $reason->toArray(),
                ];

                if ($category === ImpactCategory::SharedState && count($reason->nodeChain) >= 3) {
                    $row['resource_node_id'] = $reason->nodeChain[count($reason->nodeChain) - 2];
                }

                $rows[] = $row;
            }
        }

        usort($rows, static fn (array $left, array $right): int => [
            $left['node_id'],
            $left['reason']['sentence'],
        ] <=> [
            $right['node_id'],
            $right['reason']['sentence'],
        ]);

        return $rows;
    }
}
