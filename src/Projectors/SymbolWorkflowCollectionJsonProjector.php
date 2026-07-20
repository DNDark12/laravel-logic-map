<?php

namespace DNDark\LogicMap\Projectors;

use DNDark\LogicMap\Domain\Graph\GraphNode;
use DNDark\LogicMap\Domain\Workflow\WorkflowDefinition;
use InvalidArgumentException;

final class SymbolWorkflowCollectionJsonProjector
{
    /** @param list<WorkflowDefinition> $workflows */
    public function project(GraphNode $selection, array $workflows, string $snapshotId, array $entryEvidence = []): array
    {
        $workflowProjector = new WorkflowJsonProjector();
        $entries = [];
        $summary = [
            'entrypoint_count' => count($workflows),
            'step_count' => 0,
            'transition_count' => 0,
            'branch_count' => 0,
            'async_boundary_count' => 0,
            'transaction_count' => 0,
            'effect_count' => 0,
            'gap_count' => 0,
        ];

        foreach ($workflows as $workflow) {
            if (! $workflow instanceof WorkflowDefinition) {
                throw new InvalidArgumentException('Symbol workflow collections require workflow definitions.');
            }

            $projected = $workflowProjector->project(
                $workflow,
                $snapshotId,
                $entryEvidence[$workflow->id->value] ?? [],
            );
            $entries[] = $projected;
            $summary['step_count'] += count($projected['steps']);
            $summary['transition_count'] += count($projected['transitions']);
            $summary['branch_count'] += (int) ($projected['summary']['branch_count'] ?? 0);
            $summary['async_boundary_count'] += (int) ($projected['summary']['async_boundary_count'] ?? 0);
            $summary['transaction_count'] += count($projected['transactions']);
            $summary['effect_count'] += count($projected['effects']);
            $summary['gap_count'] += count($projected['gaps']);
        }

        return [
            'identity' => [
                'schema_version' => 2,
                'snapshot_id' => $snapshotId,
                'workflow_id' => 'symbol-workflow:'.$selection->id->value,
                'workflow_type' => 'symbol_collection',
            ],
            'selection' => [
                'node_id' => $selection->id->value,
                'kind' => $selection->kind->value,
                'name' => $selection->name,
                'qualified_name' => $selection->qualifiedName,
            ],
            'summary' => $summary,
            'entry_workflows' => $entries,
            'gaps' => [],
        ];
    }
}
