<?php

namespace DNDark\LogicMap\Projectors;

use DNDark\LogicMap\Domain\Graph\EvidenceRecord;
use DNDark\LogicMap\Domain\Workflow\WorkflowDefinition;
use DNDark\LogicMap\Domain\Workflow\WorkflowStep;
use DNDark\LogicMap\Domain\Workflow\WorkflowStepKind;
use DNDark\LogicMap\Support\CanonicalJson;
use InvalidArgumentException;

final class WorkflowJsonProjector
{
    public function project(WorkflowDefinition $workflow, string $snapshotId, array $evidence = []): array
    {
        $steps = array_map(static fn (WorkflowStep $step): array => $step->toArray(), $workflow->steps);
        usort($steps, static fn (array $left, array $right): int => $left['id'] <=> $right['id']);
        $transitions = array_map(static fn ($transition): array => $transition->toArray(), $workflow->transitions);
        usort($transitions, static fn (array $left, array $right): int => CanonicalJson::encode($left) <=> CanonicalJson::encode($right));
        $transactions = array_map(static fn ($transaction): array => $transaction->toArray(), $workflow->transactions);
        usort($transactions, static fn (array $left, array $right): int => $left['id'] <=> $right['id']);
        $gaps = array_map(static fn ($gap): array => $gap->toArray(), $workflow->gaps);
        usort($gaps, static fn (array $left, array $right): int => $left['step_id'] <=> $right['step_id']);

        return [
            'identity' => [
                'schema_version' => 2,
                'snapshot_id' => $snapshotId,
                'workflow_id' => $workflow->id->value,
            ],
            'entrypoint' => [
                'node_id' => $workflow->entrypoint->value,
                'step_id' => $workflow->entryStepId,
            ],
            'summary' => $workflow->summary()->toArray(),
            'steps' => $steps,
            'transitions' => $transitions,
            'transactions' => $transactions,
            'modules' => $this->modules($workflow),
            'effects' => array_values(array_filter(
                $steps,
                static fn (array $step): bool => $step['kind'] === WorkflowStepKind::Effect->value,
            )),
            'gaps' => $gaps,
            'truncation' => $workflow->truncation,
            'evidence' => $this->evidence($evidence),
        ];
    }

    private function modules(WorkflowDefinition $workflow): array
    {
        $modules = [];

        foreach ($workflow->steps as $step) {
            $modules[$step->module ?? 'Unassigned'][] = $step->id;
        }

        ksort($modules, SORT_STRING);

        return array_map(static function (string $module, array $stepIds): array {
            sort($stepIds, SORT_STRING);

            return ['name' => $module, 'step_ids' => $stepIds];
        }, array_keys($modules), array_values($modules));
    }

    private function evidence(array $records): array
    {
        $projected = [];

        foreach ($records as $record) {
            if (! $record instanceof EvidenceRecord) {
                throw new InvalidArgumentException('Workflow evidence must contain EvidenceRecord values.');
            }

            $projected[$record->id()] = ['id' => $record->id(), ...$record->toArray()];
        }

        ksort($projected, SORT_STRING);

        return array_values($projected);
    }
}
