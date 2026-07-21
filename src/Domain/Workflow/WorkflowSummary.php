<?php

namespace DNDark\LogicMap\Domain\Workflow;

final readonly class WorkflowSummary
{
    public function __construct(
        public int $stepCount,
        public int $moduleCount,
        public int $branchCount,
        public int $asyncBoundaryCount,
        public int $transactionCount,
        public int $effectCount,
        public int $gapCount,
    ) {}

    public function toArray(): array
    {
        return [
            'step_count' => $this->stepCount,
            'module_count' => $this->moduleCount,
            'branch_count' => $this->branchCount,
            'async_boundary_count' => $this->asyncBoundaryCount,
            'transaction_count' => $this->transactionCount,
            'effect_count' => $this->effectCount,
            'gap_count' => $this->gapCount,
        ];
    }
}
