<?php

namespace DNDark\LogicMap\Projectors;

use DNDark\LogicMap\Domain\Snapshot\Diagnostic;
use DNDark\LogicMap\Domain\Workflow\ModuleWorkflow;

final class ModuleWorkflowJsonProjector
{
    public function project(ModuleWorkflow $moduleWorkflow, string $snapshotId, array $entryEvidence = []): array
    {
        $workflowProjector = new WorkflowJsonProjector();
        $entries = [];
        $summary = [
            'entrypoint_count' => count($moduleWorkflow->entryWorkflows),
            'step_count' => 0,
            'transition_count' => 0,
            'branch_count' => 0,
            'async_boundary_count' => 0,
            'transaction_count' => 0,
            'effect_count' => 0,
            'gap_count' => count($moduleWorkflow->gaps),
        ];

        foreach ($moduleWorkflow->entryWorkflows as $workflow) {
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
        }

        return [
            'identity' => [
                'schema_version' => 2,
                'snapshot_id' => $snapshotId,
                'workflow_id' => 'module-workflow:'.$moduleWorkflow->moduleId->value,
                'workflow_type' => 'module',
            ],
            'module' => [
                'node_id' => $moduleWorkflow->moduleId->value,
                'name' => $moduleWorkflow->name,
            ],
            'summary' => $summary,
            'entry_workflows' => $entries,
            'inbound_relations' => $moduleWorkflow->inboundRelations,
            'outbound_relations' => $moduleWorkflow->outboundRelations,
            'shared_resources' => $moduleWorkflow->sharedResources,
            'gaps' => array_map(static fn ($gap): array => $gap->toArray(), $moduleWorkflow->gaps),
            'diagnostics' => array_map(
                static fn ($diagnostic): array => $diagnostic instanceof Diagnostic
                    ? $diagnostic->toArray()
                    : (array) $diagnostic,
                $moduleWorkflow->diagnostics,
            ),
        ];
    }
}
