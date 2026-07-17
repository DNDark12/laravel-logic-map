<?php

namespace DNDark\LogicMap\Analysis\Laravel\Detectors;

use DNDark\LogicMap\Analysis\Facts\DataEffectFact;
use DNDark\LogicMap\Domain\Snapshot\Diagnostic;
use InvalidArgumentException;

final readonly class DataEffectDetectionResult
{
    public function __construct(
        public array $facts = [],
        public array $diagnostics = [],
    ) {
        foreach ($facts as $fact) {
            if (! $fact instanceof DataEffectFact) {
                throw new InvalidArgumentException('Data effect results require DataEffectFact values.');
            }
        }

        foreach ($diagnostics as $diagnostic) {
            if (! $diagnostic instanceof Diagnostic) {
                throw new InvalidArgumentException('Data effect diagnostics require Diagnostic values.');
            }
        }
    }
}
