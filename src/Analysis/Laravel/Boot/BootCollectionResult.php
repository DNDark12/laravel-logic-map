<?php

namespace DNDark\LogicMap\Analysis\Laravel\Boot;

use DNDark\LogicMap\Domain\Snapshot\Diagnostic;
use InvalidArgumentException;

final readonly class BootCollectionResult
{
    public function __construct(
        public array $facts = [],
        public array $diagnostics = [],
    ) {
        self::assertInstances($facts, BootFact::class, 'facts');
        self::assertInstances($diagnostics, Diagnostic::class, 'diagnostics');
    }

    private static function assertInstances(array $values, string $class, string $label): void
    {
        foreach ($values as $value) {
            if (! $value instanceof $class) {
                throw new InvalidArgumentException("Boot collection {$label} contain an invalid value.");
            }
        }
    }
}
