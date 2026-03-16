<?php

namespace dndark\LogicMap\Domain;

use dndark\LogicMap\Domain\Enums\EdgeType;
use dndark\LogicMap\Domain\Enums\Confidence;

class Edge
{
    public function __construct(
        public string $source,
        public string $target,
        public EdgeType $type,
        public Confidence $confidence = Confidence::HIGH,
        public array $metadata = [],
    ) {}

    public function toArray(): array
    {
        return [
            'source' => $this->source,
            'target' => $this->target,
            'type' => $this->type->value,
            'confidence' => $this->confidence->value,
            'metadata' => $this->metadata,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            source: $data['source'],
            target: $data['target'],
            type: EdgeType::from($data['type']),
            confidence: Confidence::from($data['confidence'] ?? 'high'),
            metadata: $data['metadata'] ?? [],
        );
    }
}
