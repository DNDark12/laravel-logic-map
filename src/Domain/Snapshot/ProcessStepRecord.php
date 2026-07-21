<?php

namespace DNDark\LogicMap\Domain\Snapshot;

use DNDark\LogicMap\Domain\Graph\NodeId;
use DNDark\LogicMap\Domain\Workflow\ExecutionBoundary;
use DNDark\LogicMap\Domain\Workflow\WorkflowStepKind;
use InvalidArgumentException;

final readonly class ProcessStepRecord
{
    /** @var list<string> */
    public array $evidenceIds;

    public function __construct(
        public NodeId $processId,
        public int $ordinal,
        public string $stepId,
        public ?NodeId $nodeId,
        public WorkflowStepKind $stepKind,
        public ExecutionBoundary $boundary,
        array $evidenceIds,
        public array $attributes = [],
    ) {
        if (! str_starts_with($processId->value, 'process:')) {
            throw new InvalidArgumentException('Process step records require a process node ID.');
        }

        if ($ordinal < 0 || trim($stepId) === '') {
            throw new InvalidArgumentException('Process step records require a non-negative ordinal and step ID.');
        }

        $unique = [];

        foreach ($evidenceIds as $evidenceId) {
            if (! is_string($evidenceId) || preg_match('/^[a-f0-9]{64}$/', $evidenceId) !== 1) {
                throw new InvalidArgumentException('Process step evidence IDs must be lowercase SHA-256 values.');
            }

            $unique[$evidenceId] = true;
        }

        $evidenceIds = array_keys($unique);
        sort($evidenceIds, SORT_STRING);
        $this->evidenceIds = $evidenceIds;
    }

    public function toArray(): array
    {
        return [
            'process_id' => $this->processId->value,
            'ordinal' => $this->ordinal,
            'step_id' => $this->stepId,
            'node_id' => $this->nodeId?->value,
            'step_kind' => $this->stepKind->value,
            'boundary' => $this->boundary->value,
            'evidence_ids' => $this->evidenceIds,
            'attributes' => $this->attributes,
        ];
    }
}
