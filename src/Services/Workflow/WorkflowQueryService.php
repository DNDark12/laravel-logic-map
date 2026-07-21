<?php

namespace DNDark\LogicMap\Services\Workflow;

use DNDark\LogicMap\Domain\Graph\NodeId;
use DNDark\LogicMap\Domain\Graph\NodeKind;
use DNDark\LogicMap\Domain\Graph\EdgeType;
use DNDark\LogicMap\Domain\Snapshot\GraphSnapshot;
use DNDark\LogicMap\Domain\Snapshot\ProcessStepRecord;
use DNDark\LogicMap\Domain\Workflow\ExecutionBoundary;
use DNDark\LogicMap\Domain\Workflow\ModuleWorkflow;
use DNDark\LogicMap\Domain\Workflow\TransactionSegment;
use DNDark\LogicMap\Domain\Workflow\WorkflowDefinition;
use DNDark\LogicMap\Domain\Workflow\WorkflowGap;
use DNDark\LogicMap\Domain\Workflow\WorkflowId;
use DNDark\LogicMap\Domain\Workflow\WorkflowStep;
use DNDark\LogicMap\Domain\Workflow\WorkflowStepKind;
use DNDark\LogicMap\Domain\Workflow\WorkflowTransition;
use InvalidArgumentException;

final readonly class WorkflowQueryService
{
    public function __construct(
        private int $maxSteps,
        private int $maxDepth,
    ) {
        if ($maxSteps < 1 || $maxDepth < 1) {
            throw new InvalidArgumentException('Workflow queries require positive shared limits.');
        }
    }

    public function resolve(GraphSnapshot $snapshot, string $selection): NodeId
    {
        $selection = trim($selection);
        $matches = [];

        try {
            $candidate = NodeId::fromString($selection);

            if ($snapshot->graph->hasNode($candidate)) {
                $matches[$candidate->value] = $candidate;
            }
        } catch (InvalidArgumentException) {
        }

        foreach ($snapshot->graph->nodesByQualifiedName(ltrim($selection, '\\')) as $node) {
            $matches[$node->id->value] = $node->id;
        }

        if ($matches === []) {
            throw new InvalidArgumentException('Workflow symbol does not exist in the active snapshot.');
        }

        if (count($matches) !== 1) {
            throw new InvalidArgumentException('Workflow symbol is ambiguous; use a canonical node ID.');
        }

        return array_values($matches)[0];
    }

    public function build(GraphSnapshot $snapshot, NodeId $entrypoint, array $relationOverlays = []): WorkflowDefinition
    {
        $process = null;

        foreach ($snapshot->graph->nodesByKind(NodeKind::Process) as $node) {
            if (($node->attributes['entrypoint_id'] ?? null) === $entrypoint->value) {
                $process = $node;
                break;
            }
        }

        if ($process === null) {
            return (new WorkflowBuilder(
                $snapshot->graph,
                [],
                $snapshot->diagnostics,
                new EdgeDirectionPolicy(),
                $relationOverlays,
            ))->build(
                new WorkflowRequest($entrypoint, $this->maxSteps, $this->maxDepth),
            );
        }

        $records = array_values(array_filter(
            $snapshot->processSteps,
            static fn (ProcessStepRecord $step): bool => $step->processId->value === $process->id->value,
        ));

        if ($records === []) {
            throw new InvalidArgumentException('Persisted workflow process has no steps.');
        }

        $steps = [];
        $transitions = [];
        $transactions = [];
        $gaps = [];

        foreach ($records as $record) {
            $attributes = $record->attributes;
            $label = (string) ($attributes['label'] ?? $record->nodeId?->value ?? $record->stepId);
            $module = is_string($attributes['module'] ?? null) ? $attributes['module'] : null;
            $outgoing = is_array($attributes['outgoing_transitions'] ?? null)
                ? $attributes['outgoing_transitions']
                : [];
            $transactionIds = is_array($attributes['transaction_ids'] ?? null)
                ? $attributes['transaction_ids']
                : [];
            unset(
                $attributes['label'],
                $attributes['module'],
                $attributes['incoming_transitions'],
                $attributes['outgoing_transitions'],
                $attributes['transaction_ids'],
            );
            $steps[] = new WorkflowStep(
                $record->stepId,
                $record->stepKind,
                $label,
                $record->nodeId,
                $module,
                $record->evidenceIds,
                $attributes,
            );

            if ($record->stepKind === WorkflowStepKind::Gap) {
                $gaps[] = new WorkflowGap($record->stepId, $label, $record->evidenceIds, $attributes);
            }

            foreach ($outgoing as $transition) {
                $object = new WorkflowTransition(
                    (string) $transition['from'],
                    (string) $transition['to'],
                    ExecutionBoundary::from((string) $transition['boundary']),
                    is_string($transition['condition'] ?? null) ? $transition['condition'] : null,
                    is_string($transition['branch'] ?? null) ? $transition['branch'] : null,
                    (bool) ($transition['is_cycle'] ?? false),
                    (array) ($transition['evidence_ids'] ?? []),
                );
                $transitions[hash('sha256', json_encode($object->toArray(), JSON_THROW_ON_ERROR))] = $object;
            }

            foreach ($transactionIds as $transactionId) {
                if (is_string($transactionId) && $transactionId !== '') {
                    $transactions[$transactionId]['steps'][] = $record->stepId;
                    $transactions[$transactionId]['evidence'] = [
                        ...($transactions[$transactionId]['evidence'] ?? []),
                        ...$record->evidenceIds,
                    ];
                }
            }
        }

        $segments = [];

        foreach ($transactions as $id => $values) {
            $stepIds = array_values(array_unique($values['steps']));
            $evidenceIds = array_values(array_unique($values['evidence']));
            sort($stepIds, SORT_STRING);
            sort($evidenceIds, SORT_STRING);

            if ($stepIds !== [] && $evidenceIds !== []) {
                $segments[] = new TransactionSegment($id, $stepIds, $evidenceIds);
            }
        }

        $entryStepId = is_string($process->attributes['entry_step_id'] ?? null)
            ? $process->attributes['entry_step_id']
            : $records[0]->stepId;
        $truncation = is_array($process->attributes['truncation'] ?? null)
            ? $process->attributes['truncation']
            : ['truncated' => false, 'omitted_count' => 0, 'frontier' => []];

        return new WorkflowDefinition(
            new WorkflowId((string) ($process->attributes['workflow_id'] ?? WorkflowId::fromEntry($entrypoint)->value)),
            $entrypoint,
            $entryStepId,
            $steps,
            array_values($transitions),
            $segments,
            $gaps,
            $truncation,
        );
    }

    public function buildModule(GraphSnapshot $snapshot, NodeId $moduleId): ModuleWorkflow
    {
        $entrypointIds = $this->moduleEntrypointIds($snapshot, $moduleId);
        $workflows = array_map(
            fn (string $entrypointId): WorkflowDefinition => $this->build(
                $snapshot,
                NodeId::fromString($entrypointId),
            ),
            $entrypointIds,
        );

        return (new ModuleWorkflowBuilder(
            $snapshot->graph,
            [],
            [],
            ['max_nodes' => $this->maxSteps, 'max_depth' => $this->maxDepth],
            $entrypointIds,
            $workflows,
        ))->build($moduleId);
    }

    /** @return list<WorkflowDefinition> */
    public function buildSymbolCollection(GraphSnapshot $snapshot, NodeId $selection, array $relationOverlays = []): array
    {
        $methodIds = [];

        foreach ($snapshot->graph->outgoing($selection, [EdgeType::Defines]) as $definition) {
            $method = $snapshot->graph->findNode($definition->target);

            if ($method?->kind === NodeKind::Method) {
                $methodIds[$method->id->value] = true;
            }
        }

        if ($methodIds === []) {
            return [];
        }

        $processIds = [];

        foreach ($snapshot->processSteps as $step) {
            if ($step->nodeId !== null && isset($methodIds[$step->nodeId->value])) {
                $processIds[$step->processId->value] = true;
            }
        }

        $entrypointIds = [];

        foreach ($snapshot->graph->nodesByIds(array_keys($processIds)) as $process) {
            $entrypointId = $process->attributes['entrypoint_id'] ?? null;

            if (is_string($entrypointId) && $entrypointId !== '') {
                $entrypointIds[$entrypointId] = true;
            }
        }

        if ($entrypointIds === []) {
            $entrypointIds = $methodIds;
        }

        $ids = array_keys($entrypointIds);
        sort($ids, SORT_STRING);

        return array_map(
            fn (string $entrypointId): WorkflowDefinition => $this->build(
                $snapshot,
                NodeId::fromString($entrypointId),
                $relationOverlays,
            ),
            $ids,
        );
    }

    /** @return list<string> */
    private function moduleEntrypointIds(GraphSnapshot $snapshot, NodeId $moduleId): array
    {
        $moduleName = substr($moduleId->value, strlen('module:'));
        $memberIds = [];

        foreach ($snapshot->graph->incoming($moduleId, [EdgeType::MemberOfModule]) as $membership) {
            $memberIds[$membership->source->value] = true;
        }

        $processIds = [];

        foreach ($snapshot->processSteps as $step) {
            $stepModule = $step->attributes['module'] ?? null;

            if (
                $stepModule === $moduleName
                || ($step->nodeId !== null && isset($memberIds[$step->nodeId->value]))
            ) {
                $processIds[$step->processId->value] = true;
            }
        }

        if ($processIds === []) {
            return [];
        }

        $entrypoints = [];

        foreach ($snapshot->graph->nodesByIds(array_keys($processIds)) as $process) {
            $entrypointId = $process->attributes['entrypoint_id'] ?? null;

            if (is_string($entrypointId) && $entrypointId !== '') {
                $entrypoints[$entrypointId] = true;
            }
        }

        $entrypointIds = array_keys($entrypoints);
        sort($entrypointIds, SORT_STRING);

        return $entrypointIds;
    }

    public function evidence(GraphSnapshot $snapshot, WorkflowDefinition $workflow): array
    {
        $ids = [];

        foreach ($workflow->steps as $step) {
            foreach ($step->evidenceIds as $id) {
                $ids[$id] = true;
            }
        }

        foreach ($workflow->transitions as $transition) {
            foreach ($transition->evidenceIds as $id) {
                $ids[$id] = true;
            }
        }

        return array_values($snapshot->graph->evidenceByIds(array_keys($ids)));
    }
}
