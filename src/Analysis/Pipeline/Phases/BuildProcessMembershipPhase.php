<?php

namespace DNDark\LogicMap\Analysis\Pipeline\Phases;

use DNDark\LogicMap\Analysis\Laravel\SemanticEdgeFactory;
use DNDark\LogicMap\Analysis\Pipeline\AnalysisPhase;
use DNDark\LogicMap\Analysis\Pipeline\PhaseResult;
use DNDark\LogicMap\Analysis\Pipeline\PipelineContext;
use DNDark\LogicMap\Domain\Graph\Certainty;
use DNDark\LogicMap\Domain\Graph\EdgeType;
use DNDark\LogicMap\Domain\Graph\EvidenceOrigin;
use DNDark\LogicMap\Domain\Graph\GraphNode;
use DNDark\LogicMap\Domain\Graph\NodeId;
use DNDark\LogicMap\Domain\Graph\NodeKind;
use DNDark\LogicMap\Domain\Snapshot\ProcessStepRecord;
use DNDark\LogicMap\Domain\Workflow\ExecutionBoundary;
use DNDark\LogicMap\Domain\Workflow\WorkflowDefinition;
use DNDark\LogicMap\Domain\Workflow\WorkflowStep;
use DNDark\LogicMap\Domain\Workflow\WorkflowStepKind;
use DNDark\LogicMap\Services\Workflow\WorkflowBuilder;
use DNDark\LogicMap\Services\Workflow\WorkflowRequest;
use InvalidArgumentException;

final readonly class BuildProcessMembershipPhase implements AnalysisPhase
{
    public function __construct(
        private int $maxSteps,
        private int $maxDepth,
    ) {
        if ($maxSteps < 1 || $maxDepth < 1) {
            throw new InvalidArgumentException('Process membership limits must be positive.');
        }
    }

    public function name(): string
    {
        return 'build_process_membership';
    }

    public function dependencies(): array
    {
        return ['extract_laravel_semantics'];
    }

    public function execute(PipelineContext $context, array $dependencies): PhaseResult
    {
        $semantic = $dependencies['extract_laravel_semantics'] ?? null;

        if (! $semantic instanceof PhaseResult || ! is_array($semantic->value)) {
            throw new InvalidArgumentException(
                'BuildProcessMembershipPhase requires Laravel semantic outputs.',
            );
        }

        $builder = new WorkflowBuilder($context->graph, $semantic->value, $semantic->diagnostics);
        $workflows = [];

        foreach ($this->entrypoints($context) as $entrypoint) {
            $workflows[] = $builder->build(new WorkflowRequest(
                $entrypoint,
                $this->maxSteps,
                $this->maxDepth,
            ));
        }

        $records = [];

        foreach ($workflows as $workflow) {
            $records = [...$records, ...$this->materialize($context, $workflow)];
        }

        usort($records, static fn (ProcessStepRecord $left, ProcessStepRecord $right): int => [
            $left->processId->value,
            $left->ordinal,
            $left->stepId,
        ] <=> [
            $right->processId->value,
            $right->ordinal,
            $right->stepId,
        ]);

        return new PhaseResult($this->name(), array_values($records), [], [
            'process_count' => count($workflows),
            'process_step_count' => count($records),
        ]);
    }

    /** @return list<NodeId> */
    private function entrypoints(PipelineContext $context): array
    {
        $entries = [];

        foreach ($context->graph->nodes() as $node) {
            $stable = match ($node->kind) {
                NodeKind::Route, NodeKind::Schedule, NodeKind::Job, NodeKind::Event => true,
                NodeKind::Command => str_starts_with($node->id->value, 'command:'),
                default => false,
            };

            if ($stable) {
                $entries[$node->id->value] = $node->id;
            }
        }

        ksort($entries, SORT_STRING);

        return array_values($entries);
    }

    /** @return list<ProcessStepRecord> */
    private function materialize(PipelineContext $context, WorkflowDefinition $workflow): array
    {
        $entryNode = $this->node($context, $workflow->entrypoint);
        $processId = NodeId::named(NodeKind::Process, $workflow->entrypoint->value);
        $context->graph->addNode(new GraphNode(
            $processId,
            NodeKind::Process,
            $entryNode->name.' process',
            null,
            $entryNode->location,
            [
                'entrypoint_id' => $workflow->entrypoint->value,
                'workflow_id' => $workflow->id->value,
                'entry_step_id' => $workflow->entryStepId,
                'truncation' => $workflow->truncation,
            ],
        ));

        $records = [];
        $incoming = [];
        $outgoing = [];
        $transactions = [];

        foreach ($workflow->transitions as $transition) {
            $incoming[$transition->to][] = $transition->toArray();
            $outgoing[$transition->from][] = $transition->toArray();
        }

        foreach ($workflow->transactions as $transaction) {
            foreach ($transaction->stepIds as $stepId) {
                $transactions[$stepId][] = $transaction->id;
            }
        }

        foreach ($this->orderedSteps($workflow) as $ordinal => $step) {
            $evidenceIds = $step->evidenceIds;

            if ($step->nodeId !== null) {
                $edge = SemanticEdgeFactory::add(
                    $context->graph,
                    $step->nodeId,
                    EdgeType::StepInProcess,
                    $processId,
                    EvidenceOrigin::StaticAst,
                    'process-membership',
                    Certainty::Certain,
                    null,
                    null,
                    $processId->value."\0".$step->id,
                    $workflow->id->value."\0".$step->id,
                    [
                        'entrypoint_id' => $workflow->entrypoint->value,
                        'step_id' => $step->id,
                    ],
                );
                $evidenceIds = [
                    ...$evidenceIds,
                    ...array_map(static fn ($record): string => $record->id(), $edge->evidence),
                ];
            }

            $records[] = new ProcessStepRecord(
                $processId,
                $ordinal,
                $step->id,
                $step->nodeId,
                $step->kind,
                $this->boundaryFor($workflow, $step),
                $evidenceIds,
                [
                    ...$step->attributes,
                    'label' => $step->label,
                    'module' => $step->module,
                    'incoming_transitions' => $incoming[$step->id] ?? [],
                    'outgoing_transitions' => $outgoing[$step->id] ?? [],
                    'transaction_ids' => $transactions[$step->id] ?? [],
                ],
            );
        }

        return $records;
    }

    /** @return list<WorkflowStep> */
    private function orderedSteps(WorkflowDefinition $workflow): array
    {
        $steps = [];

        foreach ($workflow->steps as $step) {
            $steps[$step->id] = $step;
        }

        $outgoing = [];

        foreach ($workflow->transitions as $transition) {
            $outgoing[$transition->from][] = $transition;
        }

        foreach ($outgoing as &$transitions) {
            usort($transitions, static fn ($left, $right): int => [
                $left->boundary->value,
                $left->condition ?? '',
                $left->branch ?? '',
                $left->to,
            ] <=> [
                $right->boundary->value,
                $right->condition ?? '',
                $right->branch ?? '',
                $right->to,
            ]);
        }
        unset($transitions);

        $queue = [$workflow->entryStepId];
        $ordered = [];
        $seen = [];

        while ($queue !== []) {
            $stepId = array_shift($queue);

            if (isset($seen[$stepId]) || ! isset($steps[$stepId])) {
                continue;
            }

            $seen[$stepId] = true;
            $ordered[] = $steps[$stepId];

            foreach ($outgoing[$stepId] ?? [] as $transition) {
                $queue[] = $transition->to;
            }
        }

        $remaining = array_diff_key($steps, $seen);
        ksort($remaining, SORT_STRING);

        return [...$ordered, ...array_values($remaining)];
    }

    private function boundaryFor(WorkflowDefinition $workflow, WorkflowStep $step): ExecutionBoundary
    {
        if ($step->kind === WorkflowStepKind::AsyncBoundary) {
            return ExecutionBoundary::tryFrom((string) ($step->attributes['boundary'] ?? ''))
                ?? ExecutionBoundary::Async;
        }

        $boundaries = [];

        foreach ($workflow->transitions as $transition) {
            if ($transition->to === $step->id) {
                $boundaries[$transition->boundary->value] = $transition->boundary;
            }
        }

        if ($boundaries === []) {
            return ExecutionBoundary::Sync;
        }

        $rank = [
            ExecutionBoundary::Sync->value => 0,
            ExecutionBoundary::Scheduled->value => 1,
            ExecutionBoundary::Async->value => 2,
            ExecutionBoundary::AfterResponse->value => 3,
            ExecutionBoundary::AfterCommit->value => 4,
        ];
        uasort($boundaries, static fn (ExecutionBoundary $left, ExecutionBoundary $right): int =>
            $rank[$right->value] <=> $rank[$left->value]);

        return reset($boundaries);
    }

    private function node(PipelineContext $context, NodeId $id): GraphNode
    {
        foreach ($context->graph->nodes() as $node) {
            if ($node->id->equals($id)) {
                return $node;
            }
        }

        throw new InvalidArgumentException("Missing process entrypoint {$id->value}.");
    }
}
