<?php

namespace DNDark\LogicMap\Projectors;

use DNDark\LogicMap\Domain\Workflow\ExecutionBoundary;
use DNDark\LogicMap\Domain\Workflow\WorkflowDefinition;
use DNDark\LogicMap\Domain\Workflow\WorkflowStep;
use DNDark\LogicMap\Domain\Workflow\WorkflowStepKind;

final class WorkflowMermaidProjector
{
    public function project(WorkflowDefinition $workflow): string
    {
        $steps = $workflow->steps;
        usort($steps, static fn (WorkflowStep $left, WorkflowStep $right): int => $left->id <=> $right->id);
        $aliases = [];

        foreach ($steps as $index => $step) {
            $aliases[$step->id] = 'n'.($index + 1);
        }

        $modules = [];

        foreach ($steps as $step) {
            $modules[$step->module ?? 'Unassigned'][] = $step;
        }

        ksort($modules, SORT_STRING);
        $lines = ['flowchart TD'];
        $moduleIndex = 0;

        foreach ($modules as $module => $moduleSteps) {
            $moduleIndex++;
            $lines[] = '  subgraph module_'.$moduleIndex.'["Module '.$this->escape($module).'"]';

            foreach ($moduleSteps as $step) {
                $lines[] = '    '.$this->node($aliases[$step->id], $step);
            }

            $lines[] = '  end';
        }

        $transactions = $workflow->transactions;
        usort($transactions, static fn ($left, $right): int => $left->id <=> $right->id);

        foreach ($transactions as $index => $transaction) {
            $lines[] = '  subgraph txn_'.($index + 1).'["Transaction '.$this->escape($transaction->id).'"]';
            $stepIds = $transaction->stepIds;
            sort($stepIds, SORT_STRING);

            foreach ($stepIds as $stepId) {
                if (isset($aliases[$stepId])) {
                    $lines[] = '    '.$aliases[$stepId];
                }
            }

            $lines[] = '  end';
        }

        $transitions = $workflow->transitions;
        usort($transitions, static fn ($left, $right): int => [
            $left->from,
            $left->to,
            $left->boundary->value,
            $left->condition ?? '',
            $left->branch ?? '',
        ] <=> [
            $right->from,
            $right->to,
            $right->boundary->value,
            $right->condition ?? '',
            $right->branch ?? '',
        ]);

        foreach ($transitions as $transition) {
            $from = $aliases[$transition->from];
            $to = $aliases[$transition->to];
            $label = $transition->condition ?? $transition->branch;
            $dashed = $transition->boundary !== ExecutionBoundary::Sync || $transition->isCycle;

            if ($dashed) {
                $label ??= $transition->isCycle ? 'cycle' : $transition->boundary->value;
                $lines[] = '  '.$from.' -. "'.$this->escape($label).'" .-> '.$to;
            } elseif ($label !== null) {
                $lines[] = '  '.$from.' -->|'.$this->escape($label).'| '.$to;
            } else {
                $lines[] = '  '.$from.' --> '.$to;
            }
        }

        return implode("\n", $lines)."\n";
    }

    private function node(string $alias, WorkflowStep $step): string
    {
        $prefix = match ($step->kind) {
            WorkflowStepKind::Decision => 'Decision: ',
            WorkflowStepKind::Terminal => 'Terminal: ',
            WorkflowStepKind::Cycle => 'Cycle: ',
            WorkflowStepKind::Gap => 'Gap: ',
            WorkflowStepKind::AsyncBoundary => 'Async: ',
            default => '',
        };
        $label = $this->escape($prefix.$step->label);
        $resource = $step->nodeId !== null && preg_match('/^(table|column|cache|storage):/', $step->nodeId->value) === 1;

        if ($resource) {
            return $alias.'[("'.$label.'")]';
        }

        return match ($step->kind) {
            WorkflowStepKind::Decision => $alias.'{"'.$label.'"}',
            WorkflowStepKind::AsyncBoundary => $alias.'{{"'.$label.'"}}',
            WorkflowStepKind::Terminal => $alias.'(["'.$label.'"])',
            WorkflowStepKind::Cycle => $alias.'(("'.$label.'"))',
            WorkflowStepKind::Gap => $alias.'[["'.$label.'"]]',
            default => $alias.'("'.$label.'")',
        };
    }

    private function escape(string $label): string
    {
        $label = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $label) ?? $label;
        $label = str_replace('&', '&amp;', $label);

        return str_replace(
            ["\r\n", "\r", "\n", '"', '[', ']', '{', '}', '`', '|'],
            ['<br/>', '<br/>', '<br/>', '&quot;', '&#91;', '&#93;', '&#123;', '&#125;', '&#96;', '&#124;'],
            $label,
        );
    }
}
