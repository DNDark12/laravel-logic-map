<?php

namespace DNDark\LogicMap\Domain\Workflow;

use InvalidArgumentException;

final readonly class TransactionSegment
{
    public function __construct(
        public string $id,
        public array $stepIds,
        public array $evidenceIds,
    ) {
        if (trim($id) === '' || $stepIds === [] || $evidenceIds === []) {
            throw new InvalidArgumentException('Transaction segments require an ID, steps, and evidence.');
        }
    }

    public function toArray(): array
    {
        return ['id' => $this->id, 'step_ids' => $this->stepIds, 'evidence_ids' => $this->evidenceIds];
    }
}
