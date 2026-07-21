<?php

namespace DNDark\LogicMap\Domain\Impact;

/**
 * Human-facing severity band for an ImpactWeight score. Ordered from most to
 * least severe; rank() gives an integer usable for comparisons and for the
 * one-band test-coverage mitigation in ImpactWeightModel.
 */
enum ImpactBand: string
{
    case Critical = 'critical';
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';

    public function rank(): int
    {
        return match ($this) {
            self::Critical => 3,
            self::High => 2,
            self::Medium => 1,
            self::Low => 0,
        };
    }

    public function oneStepLower(): self
    {
        return match ($this) {
            self::Critical => self::High,
            self::High => self::Medium,
            self::Medium, self::Low => self::Low,
        };
    }

    public static function fromRank(int $rank): self
    {
        return match (max(0, min(3, $rank))) {
            3 => self::Critical,
            2 => self::High,
            1 => self::Medium,
            default => self::Low,
        };
    }
}
