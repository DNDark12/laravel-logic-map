<?php

namespace DNDark\LogicMap\Domain\Workflow;

final readonly class WorkflowGap
{
    public function __construct(
        public string $stepId,
        public string $reason,
        public array $evidenceIds = [],
        public array $attributes = [],
    ) {}

    public function toArray(): array
    {
        return [
            'step_id' => $this->stepId,
            'reason' => $this->reason,
            'evidence_ids' => $this->evidenceIds,
            'attributes' => $this->attributes,
        ];
    }
}
