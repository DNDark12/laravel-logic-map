<?php

namespace DNDark\LogicMap\Analysis\Facts;

use InvalidArgumentException;

final readonly class ControlContext
{
    public string $boundaryId;

    public function __construct(
        public ControlKind $kind,
        public ?string $predicate,
        public ?string $branch,
        public int $startLine,
        public int $endLine,
    ) {
        if ($startLine < 1 || $endLine < $startLine) {
            throw new InvalidArgumentException('Control contexts require a valid source span.');
        }

        $this->boundaryId = 'control:'.hash('sha256', implode("\0", [
            $kind->value,
            $predicate ?? '',
            $branch ?? '',
            (string) $startLine,
            (string) $endLine,
        ]));
    }

    public function toArray(): array
    {
        return [
            'boundary_id' => $this->boundaryId,
            'kind' => $this->kind->value,
            'predicate' => $this->predicate,
            'branch' => $this->branch,
            'start_line' => $this->startLine,
            'end_line' => $this->endLine,
        ];
    }
}
