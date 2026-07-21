<?php

namespace DNDark\LogicMap\Domain\Workflow;

use InvalidArgumentException;

final readonly class WorkflowTransition
{
    public function __construct(
        public string $from,
        public string $to,
        public ExecutionBoundary $boundary,
        public ?string $condition,
        public ?string $branch,
        public bool $isCycle,
        public array $evidenceIds,
    ) {
        if (trim($from) === '' || trim($to) === '') {
            throw new InvalidArgumentException('Workflow transitions require endpoints.');
        }
    }

    public function toArray(): array
    {
        return [
            'from' => $this->from,
            'to' => $this->to,
            'boundary' => $this->boundary->value,
            'condition' => $this->condition,
            'branch' => $this->branch,
            'is_cycle' => $this->isCycle,
            'evidence_ids' => $this->evidenceIds,
        ];
    }
}
