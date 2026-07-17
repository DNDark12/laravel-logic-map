<?php

namespace DNDark\LogicMap\Analysis\Pipeline;

use InvalidArgumentException;

final readonly class PhaseResult
{
    public function __construct(
        public string $phase,
        public mixed $value = null,
        public array $diagnostics = [],
        public array $metrics = [],
    ) {
        if (trim($phase) === '') {
            throw new InvalidArgumentException('Phase results require a phase name.');
        }
    }

    public function withMetrics(array $metrics): self
    {
        return new self(
            $this->phase,
            $this->value,
            $this->diagnostics,
            $metrics,
        );
    }
}
