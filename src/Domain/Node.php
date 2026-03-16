<?php

namespace dndark\LogicMap\Domain;

use dndark\LogicMap\Domain\Enums\NodeKind;

class Node
{
    public function __construct(
        public string $id,
        public NodeKind $kind,
        public ?string $name = null,
        public ?string $scope = null,
        public ?string $parentId = null,
        public array $metrics = [],
        public array $positionCache = [],
        public array $metadata = [],
    ) {
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind->value,
            'name' => $this->name,
            'scope' => $this->scope,
            'parentId' => $this->parentId,
            'metrics' => $this->metrics,
            'positionCache' => $this->positionCache,
            'metadata' => $this->metadata,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            kind: NodeKind::from($data['kind']),
            name: $data['name'] ?? null,
            scope: $data['scope'] ?? null,
            parentId: $data['parentId'] ?? null,
            metrics: $data['metrics'] ?? [],
            positionCache: $data['positionCache'] ?? [],
            metadata: $data['metadata'] ?? [],
        );
    }
}
