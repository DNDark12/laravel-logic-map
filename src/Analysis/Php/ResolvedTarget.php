<?php

namespace DNDark\LogicMap\Analysis\Php;

use DNDark\LogicMap\Domain\Graph\Certainty;
use InvalidArgumentException;

final readonly class ResolvedTarget
{
    public function __construct(
        public SymbolDefinition $symbol,
        public Certainty $certainty,
        public string $reason,
        public array $evidence = [],
    ) {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('Resolved targets require a reason.');
        }
    }
}
