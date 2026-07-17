<?php

namespace DNDark\LogicMap\Domain\Graph;

use DNDark\LogicMap\Support\CanonicalJson;
use InvalidArgumentException;

final readonly class EvidenceRecord
{
    public function __construct(
        public EvidenceOrigin $origin,
        public string $detector,
        public Certainty $certainty,
        public ?SourceLocation $location = null,
        public ?string $expression = null,
        public ?string $condition = null,
        public array $attributes = [],
    ) {
        if ($detector === '') {
            throw new InvalidArgumentException('Evidence detector is required.');
        }
    }

    public function id(): string
    {
        return hash('sha256', CanonicalJson::encode($this->toArray()));
    }

    public function toArray(): array
    {
        return [
            'origin' => $this->origin->value,
            'detector' => $this->detector,
            'certainty' => $this->certainty->value,
            'location' => $this->location?->toArray(),
            'expression' => $this->expression,
            'condition' => $this->condition,
            'attributes' => $this->attributes,
        ];
    }
}
