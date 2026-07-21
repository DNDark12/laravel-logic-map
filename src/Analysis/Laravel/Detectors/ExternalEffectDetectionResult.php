<?php

namespace DNDark\LogicMap\Analysis\Laravel\Detectors;

use DNDark\LogicMap\Analysis\Facts\ExternalEffectFact;
use DNDark\LogicMap\Domain\Snapshot\Diagnostic;
use InvalidArgumentException;

final readonly class ExternalEffectDetectionResult
{
    public function __construct(
        public array $facts = [],
        public array $diagnostics = [],
    ) {
        foreach ($facts as $fact) {
            if (! $fact instanceof ExternalEffectFact) {
                throw new InvalidArgumentException('External effect results require ExternalEffectFact values.');
            }
        }

        foreach ($diagnostics as $diagnostic) {
            if (! $diagnostic instanceof Diagnostic) {
                throw new InvalidArgumentException('External effect diagnostics require Diagnostic values.');
            }
        }
    }
}
