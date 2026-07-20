<?php

namespace DNDark\LogicMap\Services\Workflow;

use DNDark\LogicMap\Domain\Graph\EdgeType;
use DNDark\LogicMap\Domain\Graph\GraphEdge;
use DNDark\LogicMap\Domain\Graph\GraphNode;
use DNDark\LogicMap\Domain\Graph\GraphReader;
use DNDark\LogicMap\Domain\Graph\NodeId;
use DNDark\LogicMap\Domain\Graph\NodeKind;
use DNDark\LogicMap\Domain\Workflow\ModuleWorkflow;
use DNDark\LogicMap\Domain\Workflow\WorkflowDefinition;
use InvalidArgumentException;

final class ModuleWorkflowBuilder
{
    /** @var array<string, GraphNode> */
    private array $nodes = [];

    /** @var array<string, string> symbol ID to module ID */
    private array $memberships = [];

    private int $maxSteps;

    private int $maxDepth;

    /** @var list<string> */
    private array $entrypointIds;

    /** @var list<WorkflowDefinition> */
    private array $persistedEntryWorkflows;

    public function __construct(
        private readonly GraphReader $graph,
        private readonly array $semanticOutputs,
        private readonly array $diagnostics,
        array $limits,
        array $entrypointIds = [],
        array $persistedEntryWorkflows = [],
    ) {
        $this->maxSteps = (int) ($limits['max_nodes'] ?? 0);
        $this->maxDepth = (int) ($limits['max_depth'] ?? 0);

        if ($this->maxSteps < 1 || $this->maxDepth < 1) {
            throw new InvalidArgumentException('Module workflow limits must define positive max_nodes and max_depth.');
        }

        $this->entrypointIds = array_values(array_unique(array_filter(
            $entrypointIds,
            static fn (mixed $id): bool => is_string($id) && $id !== '',
        )));
        sort($this->entrypointIds, SORT_STRING);
        $this->persistedEntryWorkflows = array_values(array_filter(
            $persistedEntryWorkflows,
            static fn (mixed $workflow): bool => $workflow instanceof WorkflowDefinition,
        ));

        foreach ($graph->nodes() as $node) {
            $this->nodes[$node->id->value] = $node;
        }

        foreach ($graph->edges() as $edge) {
            if ($edge->type === EdgeType::MemberOfModule) {
                $this->memberships[$edge->source->value] = $edge->target->value;
            }
        }
    }

    public function build(NodeId $moduleId): ModuleWorkflow
    {
        $module = $this->nodes[$moduleId->value] ?? null;

        if (! $module instanceof GraphNode || $module->kind !== NodeKind::Module) {
            throw new InvalidArgumentException("Unknown module {$moduleId->value}.");
        }

        $entries = $this->entryWorkflows($moduleId);
        [$inbound, $outbound, $shared] = $this->relations($moduleId);
        $gaps = [];

        foreach ($entries as $workflow) {
            foreach ($workflow->gaps as $gap) {
                $gaps[$gap->stepId] = $gap;
            }
        }

        ksort($gaps, SORT_STRING);

        return new ModuleWorkflow(
            $moduleId,
            $module->name,
            $entries,
            $inbound,
            $outbound,
            $shared,
            array_values($gaps),
            $this->diagnostics,
        );
    }

    /** @return list<WorkflowDefinition> */
    private function entryWorkflows(NodeId $moduleId): array
    {
        if ($this->persistedEntryWorkflows !== []) {
            return $this->persistedEntryWorkflows;
        }

        $builder = new WorkflowBuilder($this->graph, $this->semanticOutputs, $this->diagnostics);
        $entries = [];

        $candidates = $this->entrypointIds === []
            ? $this->graph->nodes()
            : array_values($this->graph->nodesByIds($this->entrypointIds));

        foreach ($candidates as $node) {
            if (! $this->stableEntry($node)) {
                continue;
            }

            $workflow = $builder->build(new WorkflowRequest($node->id, $this->maxSteps, $this->maxDepth));
            $belongs = array_filter(
                $workflow->steps,
                static fn ($step): bool => $step->module === substr($moduleId->value, strlen('module:')),
            );

            if ($belongs !== []) {
                $entries[] = $workflow;
            }
        }

        usort($entries, static fn (WorkflowDefinition $left, WorkflowDefinition $right): int =>
            $left->entrypoint->value <=> $right->entrypoint->value);

        return $entries;
    }

    private function stableEntry(GraphNode $node): bool
    {
        return match ($node->kind) {
            NodeKind::Route, NodeKind::Schedule, NodeKind::Job, NodeKind::Event => true,
            NodeKind::Command => str_starts_with($node->id->value, 'command:'),
            default => false,
        };
    }

    private function relations(NodeId $moduleId): array
    {
        $inbound = [];
        $outbound = [];
        $shared = [];

        foreach ($this->graph->edges() as $edge) {
            $sourceModule = $this->memberships[$edge->source->value] ?? null;
            $targetModule = $this->memberships[$edge->target->value] ?? null;

            if ($sourceModule === null || $targetModule === null || $sourceModule === $targetModule) {
                continue;
            }

            $relation = $this->directRelation($edge, $sourceModule, $targetModule);

            if ($sourceModule === $moduleId->value) {
                $outbound[$edge->type->value][] = $relation;
            }

            if ($targetModule === $moduleId->value) {
                $inbound[$edge->type->value][] = $relation;
            }
        }

        foreach ($this->sharedResourceRelations() as $relation) {
            if ($relation['source_module'] === $moduleId->value) {
                $outbound[$relation['edge_type']][] = $relation;
                $shared[] = $relation;
            }

            if ($relation['target_module'] === $moduleId->value) {
                $inbound[$relation['edge_type']][] = $relation;
                $shared[] = $relation;
            }
        }

        return [
            $this->uniqueRelations($inbound),
            $this->uniqueRelations($outbound),
            array_values($this->unique($shared)),
        ];
    }

    private function directRelation(GraphEdge $edge, string $sourceModule, string $targetModule): array
    {
        return [
            'source_module' => $sourceModule,
            'target_module' => $targetModule,
            'edge_type' => $edge->type->value,
            'source_id' => $edge->source->value,
            'target_id' => $edge->target->value,
            'resource_id' => null,
            'evidence_ids' => array_map(static fn ($record): string => $record->id(), $edge->evidence),
        ];
    }

    private function sharedResourceRelations(): array
    {
        $pairs = [
            EdgeType::WritesModel->value => EdgeType::ReadsModel,
            EdgeType::WritesTable->value => EdgeType::ReadsTable,
            EdgeType::WritesColumn->value => EdgeType::ReadsColumn,
            EdgeType::WritesCache->value => EdgeType::ReadsCache,
            EdgeType::InvalidatesCache->value => EdgeType::ReadsCache,
            EdgeType::WritesStorage->value => EdgeType::ReadsStorage,
        ];
        $writeTypes = array_map(static fn (string $value): EdgeType => EdgeType::from($value), array_keys($pairs));
        $readTypes = array_values($pairs);
        $relations = [];

        foreach ($this->graph->nodes() as $resource) {
            if (! in_array($resource->kind, [
                NodeKind::Model, NodeKind::Table, NodeKind::Column, NodeKind::CacheKey,
                NodeKind::StoragePath,
            ], true)) {
                continue;
            }

            $writes = $this->graph->incoming($resource->id, $writeTypes);
            $reads = $this->graph->incoming($resource->id, $readTypes);

            foreach ($writes as $write) {
                foreach ($reads as $read) {
                    if ($pairs[$write->type->value] !== $read->type) {
                        continue;
                    }

                    $sourceModule = $this->memberships[$write->source->value] ?? null;
                    $targetModule = $this->memberships[$read->source->value] ?? null;

                    if ($sourceModule === null || $targetModule === null || $sourceModule === $targetModule) {
                        continue;
                    }

                    $evidenceIds = [
                        ...array_map(static fn ($record): string => $record->id(), $write->evidence),
                        ...array_map(static fn ($record): string => $record->id(), $read->evidence),
                    ];
                    $evidenceIds = array_values(array_unique($evidenceIds));
                    sort($evidenceIds, SORT_STRING);
                    $relations[] = [
                        'source_module' => $sourceModule,
                        'target_module' => $targetModule,
                        'edge_type' => $write->type->value,
                        'read_edge_type' => $read->type->value,
                        'source_id' => $write->source->value,
                        'target_id' => $read->source->value,
                        'resource_id' => $resource->id->value,
                        'evidence_ids' => $evidenceIds,
                    ];
                }
            }
        }

        return array_values($this->unique($relations));
    }

    private function uniqueRelations(array $groups): array
    {
        foreach ($groups as $type => $relations) {
            $groups[$type] = array_values($this->unique($relations));
        }

        return $groups;
    }

    private function unique(array $relations): array
    {
        $unique = [];

        foreach ($relations as $relation) {
            $key = hash('sha256', json_encode($relation, JSON_THROW_ON_ERROR));
            $unique[$key] = $relation;
        }

        ksort($unique, SORT_STRING);

        return $unique;
    }
}
