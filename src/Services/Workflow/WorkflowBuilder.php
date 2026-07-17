<?php

namespace DNDark\LogicMap\Services\Workflow;

use DNDark\LogicMap\Analysis\Facts\BranchConditionFact;
use DNDark\LogicMap\Analysis\Facts\DataEffectFact;
use DNDark\LogicMap\Analysis\Facts\EarlyReturnFact;
use DNDark\LogicMap\Analysis\Facts\ThrowFact;
use DNDark\LogicMap\Domain\Graph\EdgeType;
use DNDark\LogicMap\Domain\Graph\GraphEdge;
use DNDark\LogicMap\Domain\Graph\GraphNode;
use DNDark\LogicMap\Domain\Graph\KnowledgeGraph;
use DNDark\LogicMap\Domain\Graph\NodeId;
use DNDark\LogicMap\Domain\Graph\NodeKind;
use DNDark\LogicMap\Domain\Snapshot\DiagnosticCode;
use DNDark\LogicMap\Domain\Workflow\ExecutionBoundary;
use DNDark\LogicMap\Domain\Workflow\WorkflowDefinition;
use DNDark\LogicMap\Domain\Workflow\WorkflowGap;
use DNDark\LogicMap\Domain\Workflow\WorkflowId;
use DNDark\LogicMap\Domain\Workflow\WorkflowStep;
use DNDark\LogicMap\Domain\Workflow\WorkflowStepKind;
use DNDark\LogicMap\Domain\Workflow\WorkflowTransition;
use InvalidArgumentException;

final class WorkflowBuilder
{
    private array $nodes = [];

    private array $modules = [];

    private array $steps = [];

    private array $transitions = [];

    private array $expanded = [];

    private array $transactionMemberships = [];

    private array $gaps = [];

    private array $truncation = [];

    private WorkflowRequest $request;

    public function __construct(
        private readonly KnowledgeGraph $graph,
        private readonly array $semanticOutputs = [],
        private readonly array $diagnostics = [],
        private readonly EdgeDirectionPolicy $directions = new EdgeDirectionPolicy(),
        private readonly array $relationOverlays = [],
    ) {
        foreach ($graph->nodes() as $node) {
            $this->nodes[$node->id->value] = $node;
        }

        foreach ($graph->edges() as $edge) {
            if ($edge->type === EdgeType::MemberOfModule) {
                $this->modules[$edge->source->value] = substr($edge->target->value, strlen('module:'));
            }
        }

        foreach ($relationOverlays as $key => $edge) {
            if (! is_string($key) || ! $edge instanceof GraphEdge) {
                throw new InvalidArgumentException('Workflow runtime overlays must be relation-keyed graph edges.');
            }
        }
    }

    public function build(WorkflowRequest $request): WorkflowDefinition
    {
        if (! isset($this->nodes[$request->entrypoint->value])) {
            throw new InvalidArgumentException("Unknown workflow entrypoint {$request->entrypoint->value}.");
        }

        $this->request = $request;
        $this->steps = [];
        $this->transitions = [];
        $this->expanded = [];
        $this->transactionMemberships = [];
        $this->gaps = [];
        $this->truncation = ['truncated' => false, 'omitted_count' => 0, 'frontier' => []];
        $entry = $this->nodeStep($this->nodes[$request->entrypoint->value], null, WorkflowStepKind::Entry);
        $this->steps[$entry->id] = $entry;
        $this->expand($request->entrypoint, $entry->id, 0, [$request->entrypoint->value]);
        ksort($this->steps, SORT_STRING);
        ksort($this->transitions, SORT_STRING);
        sort($this->truncation['frontier'], SORT_STRING);
        $transactions = (new TransactionSegmentBuilder())->build(
            $this->transactionMemberships,
            array_map(static fn (WorkflowStep $step): array => $step->evidenceIds, $this->steps),
        );

        return new WorkflowDefinition(
            WorkflowId::fromEntry($request->entrypoint),
            $request->entrypoint,
            $entry->id,
            array_values($this->steps),
            array_values($this->transitions),
            $transactions,
            array_values($this->gaps),
            $this->truncation,
        );
    }

    private function expand(NodeId $nodeId, string $stepId, int $depth, array $path): void
    {
        if (isset($this->expanded[$nodeId->value]) || $depth >= $this->request->maxDepth) {
            return;
        }

        $candidates = $this->candidateEdges($nodeId);
        $special = $this->specialTargets($nodeId);

        if (count($this->steps) >= $this->request->maxSteps && ($candidates !== [] || $special !== [])) {
            $this->truncateAt($nodeId->value);

            return;
        }

        $this->expanded[$nodeId->value] = true;
        $originStep = $this->attachTerminals($nodeId, $stepId);

        foreach ($candidates as [$edge, $target]) {
            $this->follow($nodeId, $originStep, $target, $edge, $depth, $path);
        }

        foreach ($special as $target) {
            $this->follow($nodeId, $originStep, $target, null, $depth, $path);
        }

        $this->attachGap($nodeId, $originStep);
    }

    private function follow(
        NodeId $source,
        string $fromStep,
        NodeId $target,
        ?GraphEdge $edge,
        int $depth,
        array $path,
    ): void {
        $evidence = $edge === null ? [] : array_map(static fn ($record): string => $record->id(), $edge->evidence);

        if (in_array($target->value, $path, true)) {
            $cycleId = 'cycle:'.hash('sha256', $source->value."\0".$target->value);
            $this->steps[$cycleId] ??= new WorkflowStep(
                $cycleId,
                WorkflowStepKind::Cycle,
                'Cycle to '.$target->value,
                null,
                $this->modules[$source->value] ?? null,
                $evidence,
            );
            $this->transition($fromStep, $cycleId, ExecutionBoundary::Sync, null, null, true, $evidence);

            return;
        }

        if (! isset($this->nodes[$target->value])) {
            return;
        }

        $boundary = $this->boundary($edge);
        $targetStep = $this->stepForTarget($this->nodes[$target->value], $edge, $source);

        if ($boundary !== ExecutionBoundary::Sync && $boundary !== ExecutionBoundary::Scheduled) {
            $boundaryId = 'boundary:'.hash('sha256', ($edge?->id ?? '')."\0".$target->value);
            $this->steps[$boundaryId] ??= new WorkflowStep(
                $boundaryId,
                WorkflowStepKind::AsyncBoundary,
                $boundary->value,
                null,
                $targetStep->module,
                $evidence,
                ['boundary' => $boundary->value],
            );
            $this->transition($fromStep, $boundaryId, $boundary, null, null, false, $evidence);
            $fromStep = $boundaryId;
        }

        $alreadyKnown = isset($this->steps[$targetStep->id]);

        if (! $alreadyKnown && count($this->steps) >= $this->request->maxSteps) {
            $this->truncateAt($source->value);

            return;
        }

        $this->steps[$targetStep->id] ??= $targetStep;
        [$condition, $branch] = $this->decisionContinuation($fromStep);
        $this->transition($fromStep, $targetStep->id, $boundary, $condition, $branch, false, $evidence);
        $this->attachTransactionMembership($source, $target, $edge, $targetStep->id);

        if (! $alreadyKnown) {
            $this->expand($target, $targetStep->id, $depth + 1, [...$path, $target->value]);
        }
    }

    private function candidateEdges(NodeId $nodeId): array
    {
        $candidates = [];

        foreach ($this->relationEdges($nodeId, true) as $edge) {
            if (! $this->directions->workflow($edge->type) || $edge->type === EdgeType::ListensTo) {
                continue;
            }

            $candidates[] = [$edge, $edge->target];
        }

        foreach ($this->relationEdges($nodeId, false, [EdgeType::ListensTo]) as $edge) {
            $queued = array_filter(
                $this->relationEdges($nodeId, true, [EdgeType::Queues]),
                static fn (GraphEdge $queue): bool => $queue->target->equals($edge->source),
            );

            if ($queued === []) {
                $candidates[] = [$edge, $edge->source];
            }
        }

        usort($candidates, function (array $left, array $right): int {
            $leftEvidence = $left[0]->evidence[0];
            $rightEvidence = $right[0]->evidence[0];

            return [
                $leftEvidence->location?->startLine ?? PHP_INT_MAX,
                $this->boundary($left[0]) === ExecutionBoundary::Sync ? 0 : 1,
                $this->directions->priority($left[0]->type),
                $left[1]->value,
                $left[0]->id,
            ] <=> [
                $rightEvidence->location?->startLine ?? PHP_INT_MAX,
                $this->boundary($right[0]) === ExecutionBoundary::Sync ? 0 : 1,
                $this->directions->priority($right[0]->type),
                $right[1]->value,
                $right[0]->id,
            ];
        });
        $unique = [];

        foreach ($candidates as $candidate) {
            $key = $candidate[0]->type->value."\0".$candidate[1]->value;
            $unique[$key] ??= $candidate;
        }

        return array_values($unique);
    }

    /** @param null|list<EdgeType> $types
     *  @return list<GraphEdge>
     */
    private function relationEdges(NodeId $nodeId, bool $outgoing, ?array $types = null): array
    {
        $staticEdges = $outgoing
            ? $this->graph->outgoing($nodeId, $types)
            : $this->graph->incoming($nodeId, $types);
        $edges = [];

        foreach ($staticEdges as $edge) {
            $relationKey = $this->relationKey($edge);

            if (isset($this->relationOverlays[$relationKey])) {
                $edges['relation:'.$relationKey] = $this->relationOverlays[$relationKey];
            } else {
                $edges['edge:'.$edge->id] = $edge;
            }
        }

        foreach ($this->relationOverlays as $relationKey => $edge) {
            $matchesNode = $outgoing
                ? $edge->source->value === $nodeId->value
                : $edge->target->value === $nodeId->value;

            if ($matchesNode && ($types === null || in_array($edge->type, $types, true))) {
                $edges['relation:'.$relationKey] = $edge;
            }
        }

        ksort($edges, SORT_STRING);

        return array_values($edges);
    }

    private function relationKey(GraphEdge $edge): string
    {
        return implode("\0", [$edge->source->value, $edge->target->value, $edge->type->value]);
    }

    private function specialTargets(NodeId $nodeId): array
    {
        $node = $this->nodes[$nodeId->value];
        $targets = [];

        if ($node->kind === NodeKind::Command && str_starts_with($nodeId->value, 'command:')) {
            foreach ($this->nodes as $candidate) {
                if ($candidate->kind === NodeKind::Command && str_starts_with($candidate->id->value, 'class:')) {
                    $targets[] = $candidate->id;
                }
            }
        } elseif (in_array($node->kind, [NodeKind::Command, NodeKind::Job, NodeKind::Listener], true)
            && is_string($node->qualifiedName)) {
            $method = NodeId::method($node->qualifiedName, 'handle');

            if (isset($this->nodes[$method->value])) {
                $targets[] = $method;
            }
        }

        usort($targets, static fn (NodeId $left, NodeId $right): int => $left->value <=> $right->value);

        return $targets;
    }

    private function attachTerminals(NodeId $nodeId, string $fromStep): string
    {
        $module = $this->modules[$nodeId->value] ?? null;

        foreach ([...($this->semanticOutputs['throws'] ?? []), ...($this->semanticOutputs['early_returns'] ?? [])] as $terminal) {
            if (! $terminal instanceof ThrowFact && ! $terminal instanceof EarlyReturnFact) {
                continue;
            }

            if ($terminal->enclosingSymbol !== $nodeId->value || $terminal->guard === null) {
                continue;
            }

            $decision = (new DecisionStepFactory())->make($terminal->guard, $module);
            $this->steps[$decision->id] ??= $decision;
            $this->transition($fromStep, $decision->id, ExecutionBoundary::Sync, null, null, false, []);
            $terminalStep = (new TerminalStepFactory())->make($terminal, $module);
            $this->steps[$terminalStep->id] ??= $terminalStep;
            $this->transition(
                $decision->id,
                $terminalStep->id,
                ExecutionBoundary::Sync,
                $terminal->guard->expression,
                $terminal->guard->branch,
                false,
                [],
            );

            return $decision->id;
        }

        return $fromStep;
    }

    private function attachGap(NodeId $nodeId, string $fromStep): void
    {
        foreach ($this->diagnostics as $diagnostic) {
            if (! in_array($diagnostic->code, [DiagnosticCode::UnresolvedReceiver, DiagnosticCode::AmbiguousTarget], true)
                || ($diagnostic->attributes['enclosing_symbol_id'] ?? null) !== $nodeId->value) {
                continue;
            }

            $id = 'gap:'.hash('sha256', implode("\0", [
                $nodeId->value,
                $diagnostic->file ?? '',
                (string) ($diagnostic->startLine ?? 0),
                $diagnostic->message,
            ]));

            if (isset($this->steps[$id])) {
                continue;
            }

            $this->steps[$id] = new WorkflowStep(
                $id,
                WorkflowStepKind::Gap,
                $diagnostic->message,
                null,
                $this->modules[$nodeId->value] ?? null,
                [],
                ['diagnostic' => $diagnostic->toArray()],
            );
            $this->gaps[$id] = new WorkflowGap($id, $diagnostic->message, [], $diagnostic->attributes);
            $this->transition($fromStep, $id, ExecutionBoundary::Sync, null, null, false, []);
            break;
        }
    }

    private function stepForTarget(GraphNode $node, ?GraphEdge $edge, NodeId $source): WorkflowStep
    {
        if ($edge !== null && in_array($node->kind, [
            NodeKind::Model, NodeKind::Table, NodeKind::Column, NodeKind::CacheKey,
            NodeKind::ConfigKey, NodeKind::StoragePath, NodeKind::ExternalEndpoint, NodeKind::View,
            NodeKind::Notification, NodeKind::Mailable,
        ], true)) {
            return (new EffectStepFactory())->make($node, $edge, $this->modules[$source->value] ?? null);
        }

        return $this->nodeStep($node, $edge);
    }

    private function nodeStep(GraphNode $node, ?GraphEdge $edge, WorkflowStepKind $kind = WorkflowStepKind::Symbol): WorkflowStep
    {
        return new WorkflowStep(
            'step:'.hash('sha256', $node->id->value),
            $kind,
            $node->name,
            $node->id,
            $this->modules[$node->id->value] ?? null,
            $edge === null ? [] : array_map(static fn ($record): string => $record->id(), $edge->evidence),
            ['node_kind' => $node->kind->value],
        );
    }

    private function boundary(?GraphEdge $edge): ExecutionBoundary
    {
        if ($edge === null) {
            return ExecutionBoundary::Sync;
        }

        if ($edge->type === EdgeType::Schedules) {
            return ExecutionBoundary::Scheduled;
        }

        $execution = $edge->evidence[0]->attributes['execution'] ?? null;

        return match (true) {
            $edge->type === EdgeType::Queues, $execution === 'async' => ExecutionBoundary::Async,
            $execution === 'after_response' => ExecutionBoundary::AfterResponse,
            $execution === 'after_commit' => ExecutionBoundary::AfterCommit,
            default => ExecutionBoundary::Sync,
        };
    }

    private function decisionContinuation(string $stepId): array
    {
        $step = $this->steps[$stepId] ?? null;

        if (! $step instanceof WorkflowStep || $step->kind !== WorkflowStepKind::Decision) {
            return [null, null];
        }

        return ['not('.$step->label.')', 'falsy'];
    }

    private function attachTransactionMembership(NodeId $source, NodeId $target, ?GraphEdge $edge, string $stepId): void
    {
        if ($edge === null) {
            return;
        }

        foreach ($this->semanticOutputs['data_effects'] ?? [] as $fact) {
            if (! $fact instanceof DataEffectFact
                || $fact->enclosingSymbol !== $source->value
                || $fact->effect !== $edge->type->value
                || $fact->resource !== $this->resourceKey($target)) {
                continue;
            }

            foreach ($fact->controlContexts as $context) {
                if (($context['kind'] ?? null) === 'transaction' && is_string($context['boundary_id'] ?? null)) {
                    $this->transactionMemberships[$context['boundary_id']][] = $stepId;
                }
            }
        }
    }

    private function resourceKey(NodeId $target): string
    {
        $value = substr($target->value, strpos($target->value, ':') + 1);

        return str_starts_with($target->value, 'column:') ? $value : $value;
    }

    private function transition(
        string $from,
        string $to,
        ExecutionBoundary $boundary,
        ?string $condition,
        ?string $branch,
        bool $cycle,
        array $evidence,
    ): void {
        if ($condition === null && $branch === null) {
            [$condition, $branch] = $this->decisionContinuation($from);
        }

        $transition = new WorkflowTransition($from, $to, $boundary, $condition, $branch, $cycle, $evidence);
        $key = hash('sha256', json_encode($transition->toArray(), JSON_THROW_ON_ERROR));
        $this->transitions[$key] = $transition;
    }

    private function truncateAt(string $nodeId): void
    {
        $this->truncation['truncated'] = true;
        $this->truncation['omitted_count']++;
        $this->truncation['frontier'][] = $nodeId;
        $this->truncation['frontier'] = array_values(array_unique($this->truncation['frontier']));
    }
}
