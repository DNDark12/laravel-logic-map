<?php

namespace DNDark\LogicMap\Services\Impact;

use DNDark\LogicMap\Domain\Graph\Certainty;
use DNDark\LogicMap\Domain\Graph\EvidenceOrigin;
use DNDark\LogicMap\Domain\Graph\EvidenceRecord;
use DNDark\LogicMap\Domain\Impact\ImpactBand;
use DNDark\LogicMap\Domain\Impact\ImpactLevel;
use DNDark\LogicMap\Domain\Impact\ImpactReason;
use DNDark\LogicMap\Domain\Impact\ImpactWeight;
use InvalidArgumentException;

/**
 * Assigns an explainable severity weight to impact reasons using signals
 * already present in the graph: the reason's ImpactCategory, the strongest
 * static Certainty on its evidence, its ImpactLevel/traversal depth, and
 * whether the relation was observed at runtime. Nothing here re-implements
 * traversal — it only scores ImpactReason values produced by ImpactAnalyzer.
 *
 * score = clamp01(category x confidence x level_decay x runtime_factor)
 *
 * All factor tables are config-tunable (logic-map.doc_export.weights); the
 * values below are the shipped defaults and are also used when a config key
 * is absent, so partial overrides are safe.
 */
final readonly class ImpactWeightModel
{
    private const DEFAULT_CATEGORY_WEIGHTS = [
        'hard_dependency' => 1.0,
        'workflow' => 0.8,
        'external_contract' => 0.8,
        'async' => 0.7,
        'shared_state' => 0.6,
        'module' => 0.4,
        'test_scope' => 0.4,
        'uncertainty' => 0.3,
    ];

    private const DEFAULT_CONFIDENCE_WEIGHTS = [
        'certain' => 1.0,
        'probable' => 0.6,
        'possible' => 0.3,
    ];

    private const DEFAULT_LEVEL_WEIGHTS = [
        'breaks' => 1.0,
        'direct' => 1.0,
        'shared_resource' => 0.7,
        'possible' => 0.3,
        'transitive_decay_base' => 0.5,
    ];

    private const DEFAULT_RUNTIME_WEIGHTS = [
        'observed' => 1.0,
        'static_only' => 0.9,
        'runtime_only' => 0.7,
    ];

    private const DEFAULT_BAND_THRESHOLDS = [
        'critical' => 0.70,
        'high' => 0.45,
        'medium' => 0.20,
    ];

    /** @param array<string,float> $categoryWeights
     *  @param array<string,float> $confidenceWeights
     *  @param array<string,float> $levelWeights
     *  @param array<string,float> $runtimeWeights
     *  @param array<string,float> $bandThresholds
     */
    public function __construct(
        private array $categoryWeights = self::DEFAULT_CATEGORY_WEIGHTS,
        private array $confidenceWeights = self::DEFAULT_CONFIDENCE_WEIGHTS,
        private array $levelWeights = self::DEFAULT_LEVEL_WEIGHTS,
        private array $runtimeWeights = self::DEFAULT_RUNTIME_WEIGHTS,
        private array $bandThresholds = self::DEFAULT_BAND_THRESHOLDS,
    ) {
    }

    /** @param array<string,mixed> $config logic-map.doc_export.weights */
    public static function fromConfig(array $config): self
    {
        return new self(
            [...self::DEFAULT_CATEGORY_WEIGHTS, ...(array) ($config['category'] ?? [])],
            [...self::DEFAULT_CONFIDENCE_WEIGHTS, ...(array) ($config['confidence'] ?? [])],
            [...self::DEFAULT_LEVEL_WEIGHTS, ...(array) ($config['level'] ?? [])],
            [...self::DEFAULT_RUNTIME_WEIGHTS, ...(array) ($config['runtime'] ?? [])],
            [...self::DEFAULT_BAND_THRESHOLDS, ...(array) ($config['bands'] ?? [])],
        );
    }

    /** @param array<string,EvidenceRecord> $evidenceById keyed by EvidenceRecord::id() */
    public function forReason(ImpactReason $reason, array $evidenceById): ImpactWeight
    {
        $records = [];

        foreach ($reason->evidenceIds as $id) {
            if (isset($evidenceById[$id])) {
                $records[] = $evidenceById[$id];
            }
        }

        $static = array_values(array_filter(
            $records,
            static fn (EvidenceRecord $record): bool => $record->origin !== EvidenceOrigin::Runtime,
        ));
        $runtime = array_values(array_filter(
            $records,
            static fn (EvidenceRecord $record): bool => $record->origin === EvidenceOrigin::Runtime,
        ));

        $categoryFactor = $this->categoryWeights[$reason->category->value] ?? 0.3;
        [$confidenceFactor, $confidenceLabel] = $this->confidenceFactor($static, $runtime);
        $levelFactor = $this->levelFactor($reason);
        [$runtimeFactor, $runtimeLabel] = $this->runtimeFactor($static, $runtime);

        $score = $this->clamp01($categoryFactor * $confidenceFactor * $levelFactor * $runtimeFactor);

        return new ImpactWeight($score, $this->bandForScore($score), [
            'category' => $reason->category->value,
            'category_factor' => $categoryFactor,
            'confidence' => $confidenceLabel,
            'confidence_factor' => $confidenceFactor,
            'level' => $reason->level->value,
            'level_factor' => $levelFactor,
            'depth' => max(1, count($reason->edgeChain)),
            'runtime_status' => $runtimeLabel,
            'runtime_factor' => $runtimeFactor,
        ]);
    }

    /**
     * Aggregates a symbol's reasons into a single weight: the highest-scoring
     * reason wins. When `$testCovered` is true, the band is dropped one step
     * (never below Low) as a mitigation signal — coverage lowers risk, it
     * does not erase it, so the score and the underlying reason are kept
     * in `factors` for transparency.
     *
     * @param list<ImpactReason> $reasons
     * @param array<string,EvidenceRecord> $evidenceById
     */
    public function aggregate(array $reasons, array $evidenceById, bool $testCovered = false): ImpactWeight
    {
        if ($reasons === []) {
            return new ImpactWeight(0.0, ImpactBand::Low, ['reason' => 'no_reasons']);
        }

        $best = $this->bestReason($reasons, $evidenceById)['weight'];

        if (! $testCovered) {
            return new ImpactWeight($best->score, $best->band, [
                ...$best->factors,
                'mitigated_by_test_coverage' => false,
            ]);
        }

        return new ImpactWeight($best->score, $best->band->oneStepLower(), [
            ...$best->factors,
            'mitigated_by_test_coverage' => true,
            'pre_mitigation_band' => $best->band->value,
        ]);
    }

    /**
     * The single highest-scoring reason for a symbol, alongside its weight.
     * Exposed (in addition to aggregate()) so consumers that need the
     * winning reason's chain/evidence — not just its score — don't have to
     * re-derive "which reason won" themselves.
     *
     * @param list<ImpactReason> $reasons non-empty
     * @param array<string,EvidenceRecord> $evidenceById
     * @return array{reason: ImpactReason, weight: ImpactWeight}
     */
    public function bestReason(array $reasons, array $evidenceById): array
    {
        $bestReason = null;
        $bestWeight = null;

        foreach ($reasons as $reason) {
            $weight = $this->forReason($reason, $evidenceById);

            if ($bestWeight === null || $weight->score > $bestWeight->score) {
                $bestReason = $reason;
                $bestWeight = $weight;
            }
        }

        if ($bestReason === null || $bestWeight === null) {
            throw new InvalidArgumentException('At least one impact reason is required to determine the best reason.');
        }

        return ['reason' => $bestReason, 'weight' => $bestWeight];
    }

    /** @param list<EvidenceRecord> $static
     *  @param list<EvidenceRecord> $runtime
     *  @return array{0:float,1:string}
     */
    private function confidenceFactor(array $static, array $runtime): array
    {
        $pool = $static !== [] ? $static : $runtime;

        if ($pool === []) {
            return [$this->confidenceWeights['possible'] ?? 0.3, 'unknown'];
        }

        $rank = ['possible' => 0, 'probable' => 1, 'certain' => 2];
        $strongest = Certainty::Possible;

        foreach ($pool as $record) {
            if ($rank[$record->certainty->value] > $rank[$strongest->value]) {
                $strongest = $record->certainty;
            }
        }

        return [$this->confidenceWeights[$strongest->value] ?? 0.3, $strongest->value];
    }

    private function levelFactor(ImpactReason $reason): float
    {
        if ($reason->level === ImpactLevel::Transitive) {
            $depth = max(1, count($reason->edgeChain));
            $base = $this->levelWeights['transitive_decay_base'] ?? 0.5;

            return $base ** ($depth - 1);
        }

        return $this->levelWeights[$reason->level->value] ?? 0.5;
    }

    /** @param list<EvidenceRecord> $static
     *  @param list<EvidenceRecord> $runtime
     *  @return array{0:float,1:string}
     */
    private function runtimeFactor(array $static, array $runtime): array
    {
        if ($static !== [] && $runtime !== []) {
            return [$this->runtimeWeights['observed'] ?? 1.0, 'observed'];
        }

        if ($runtime !== [] && $static === []) {
            return [$this->runtimeWeights['runtime_only'] ?? 0.7, 'runtime_only'];
        }

        return [$this->runtimeWeights['static_only'] ?? 0.9, 'static_only'];
    }

    private function bandForScore(float $score): ImpactBand
    {
        return match (true) {
            $score >= ($this->bandThresholds['critical'] ?? 0.70) => ImpactBand::Critical,
            $score >= ($this->bandThresholds['high'] ?? 0.45) => ImpactBand::High,
            $score >= ($this->bandThresholds['medium'] ?? 0.20) => ImpactBand::Medium,
            default => ImpactBand::Low,
        };
    }

    private function clamp01(float $value): float
    {
        return max(0.0, min(1.0, $value));
    }
}
