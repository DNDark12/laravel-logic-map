<?php

namespace DNDark\LogicMap\Domain\Workflow;

use DNDark\LogicMap\Domain\Graph\NodeId;
use InvalidArgumentException;

final readonly class WorkflowStep
{
    public function __construct(
        public string $id,
        public WorkflowStepKind $kind,
        public string $label,
        public ?NodeId $nodeId,
        public ?string $module,
        public array $evidenceIds,
        public array $attributes = [],
    ) {
        if (trim($id) === '' || trim($label) === '') {
            throw new InvalidArgumentException('Workflow steps require an ID and label.');
        }
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind->value,
            'label' => $this->label,
            'node_id' => $this->nodeId?->value,
            'module' => $this->module,
            'evidence_ids' => $this->evidenceIds,
            'attributes' => $this->attributes,
        ];
    }
}
