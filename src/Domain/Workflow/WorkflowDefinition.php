<?php

namespace DNDark\LogicMap\Domain\Workflow;

use DNDark\LogicMap\Domain\Graph\NodeId;
use InvalidArgumentException;

final readonly class WorkflowDefinition
{
    public function __construct(
        public WorkflowId $id,
        public NodeId $entrypoint,
        public string $entryStepId,
        public array $steps,
        public array $transitions,
        public array $transactions,
        public array $gaps = [],
        public array $truncation = [
            'truncated' => false,
            'omitted_count' => 0,
            'frontier' => [],
        ],
    ) {
        $byId = [];

        foreach ($steps as $step) {
            if (! $step instanceof WorkflowStep || isset($byId[$step->id])) {
                throw new InvalidArgumentException('Workflow step IDs must be unique.');
            }

            $byId[$step->id] = $step;
        }

        if (! isset($byId[$entryStepId])) {
            throw new InvalidArgumentException('Workflow entry step must exist.');
        }

        foreach ($transitions as $transition) {
            if (! $transition instanceof WorkflowTransition
                || ! isset($byId[$transition->from], $byId[$transition->to])) {
                throw new InvalidArgumentException('Workflow transitions must reference existing steps.');
            }

            if ($byId[$transition->from]->kind === WorkflowStepKind::Terminal) {
                throw new InvalidArgumentException('Terminal workflow steps cannot have outgoing transitions.');
            }

            if ($byId[$transition->from]->kind === WorkflowStepKind::Decision
                && $transition->condition === null
                && $transition->branch === null) {
                throw new InvalidArgumentException('Decision transitions require a condition or branch.');
            }
        }

        foreach ($transactions as $transaction) {
            if (! $transaction instanceof TransactionSegment) {
                throw new InvalidArgumentException('Workflow transactions require TransactionSegment values.');
            }

            foreach ($transaction->stepIds as $stepId) {
                if (! isset($byId[$stepId])) {
                    throw new InvalidArgumentException('Transaction segments must reference existing steps.');
                }
            }
        }
    }

    public function summary(): WorkflowSummary
    {
        $modules = array_values(array_unique(array_filter(array_map(
            static fn (WorkflowStep $step): ?string => $step->module,
            $this->steps,
        ))));

        return new WorkflowSummary(
            count($this->steps),
            count($modules),
            count(array_filter($this->steps, static fn (WorkflowStep $step): bool => $step->kind === WorkflowStepKind::Decision)),
            count(array_filter($this->steps, static fn (WorkflowStep $step): bool => $step->kind === WorkflowStepKind::AsyncBoundary)),
            count($this->transactions),
            count(array_filter($this->steps, static fn (WorkflowStep $step): bool => $step->kind === WorkflowStepKind::Effect)),
            count($this->gaps),
        );
    }
}
