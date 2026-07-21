<?php

namespace DNDark\LogicMap\Domain\Impact;

use InvalidArgumentException;

/**
 * A weighted, explainable severity score for one impact reason or one
 * aggregated affected symbol. `factors` records every multiplicand that
 * produced `score`, plus any mitigation applied, so a consumer can audit or
 * re-derive the result without re-running the model.
 */
final readonly class ImpactWeight
{
    public function __construct(
        public float $score,
        public ImpactBand $band,
        public array $factors,
    ) {
        if ($score < 0.0 || $score > 1.0) {
            throw new InvalidArgumentException('Impact weight scores must be clamped to the 0..1 range.');
        }
    }

    public function toArray(): array
    {
        return [
            'score' => round($this->score, 4),
            'band' => $this->band->value,
            'factors' => $this->factors,
        ];
    }
}
