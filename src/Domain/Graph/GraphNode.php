<?php

namespace DNDark\LogicMap\Domain\Graph;

use InvalidArgumentException;

final readonly class GraphNode
{
    public function __construct(
        public NodeId $id,
        public NodeKind $kind,
        public string $name,
        public ?string $qualifiedName,
        public ?SourceLocation $location,
        public array $attributes = [],
    ) {
        if (trim($name) === '') {
            throw new InvalidArgumentException('Graph node names must be non-empty.');
        }
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id->value,
            'kind' => $this->kind->value,
            'name' => $this->name,
            'qualified_name' => $this->qualifiedName,
            'location' => $this->location?->toArray(),
            'attributes' => $this->attributes,
        ];
    }
}
