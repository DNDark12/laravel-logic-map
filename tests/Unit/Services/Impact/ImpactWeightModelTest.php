<?php

namespace DNDark\LogicMap\Tests\Unit\Services\Impact;

use DNDark\LogicMap\Domain\Graph\Certainty;
use DNDark\LogicMap\Domain\Graph\EvidenceOrigin;
use DNDark\LogicMap\Domain\Graph\EvidenceRecord;
use DNDark\LogicMap\Domain\Impact\ImpactBand;
use DNDark\LogicMap\Domain\Impact\ImpactCategory;
use DNDark\LogicMap\Domain\Impact\ImpactLevel;
use DNDark\LogicMap\Domain\Impact\ImpactReason;
use DNDark\LogicMap\Services\Impact\ImpactWeightModel;
use PHPUnit\Framework\TestCase;

final class ImpactWeightModelTest extends TestCase
{
    public function test_hard_dependency_direct_certain_static_only_is_critical(): void
    {
        $model = new ImpactWeightModel();
        $evidence = $this->evidence(Certainty::Certain, EvidenceOrigin::StaticAst);
        $reason = $this->reason(ImpactCategory::HardDependency, ImpactLevel::Direct, [$evidence]);

        $weight = $model->forReason($reason, $this->byId([$evidence]));

        // 1.0 (category) x 1.0 (certain) x 1.0 (direct) x 0.9 (static-only) = 0.9
        self::assertEqualsWithDelta(0.9, $weight->score, 0.0001);
        self::assertSame(ImpactBand::Critical, $weight->band);
        self::assertSame('static_only', $weight->factors['runtime_status']);
    }

    public function test_uncertainty_possible_is_low(): void
    {
        $model = new ImpactWeightModel();
        $evidence = $this->evidence(Certainty::Possible, EvidenceOrigin::StaticAst);
        $reason = $this->reason(ImpactCategory::Uncertainty, ImpactLevel::Possible, [$evidence]);

        $weight = $model->forReason($reason, $this->byId([$evidence]));

        // 0.3 x 0.3 x 0.3 x 0.9 = 0.0243
        self::assertEqualsWithDelta(0.0243, $weight->score, 0.0001);
        self::assertSame(ImpactBand::Low, $weight->band);
    }

    public function test_transitive_level_decays_with_depth(): void
    {
        $model = new ImpactWeightModel();
        $evidence = $this->evidence(Certainty::Certain, EvidenceOrigin::StaticAst);

        $depthOne = $this->reason(ImpactCategory::HardDependency, ImpactLevel::Transitive, [$evidence], nodes: ['a', 'b']);
        $depthTwo = $this->reason(ImpactCategory::HardDependency, ImpactLevel::Transitive, [$evidence], nodes: ['a', 'b', 'c']);

        $weightOne = $model->forReason($depthOne, $this->byId([$evidence]));
        $weightTwo = $model->forReason($depthTwo, $this->byId([$evidence]));

        self::assertEqualsWithDelta(1.0, $weightOne->factors['level_factor'], 0.0001);
        self::assertEqualsWithDelta(0.5, $weightTwo->factors['level_factor'], 0.0001);
        self::assertGreaterThan($weightTwo->score, $weightOne->score);
    }

    public function test_runtime_observed_scores_higher_than_static_only(): void
    {
        $model = new ImpactWeightModel();
        $static = $this->evidence(Certainty::Certain, EvidenceOrigin::StaticAst);
        $runtime = $this->evidence(Certainty::Certain, EvidenceOrigin::Runtime);

        $staticOnly = $this->reason(ImpactCategory::HardDependency, ImpactLevel::Direct, [$static]);
        $observed = $this->reason(ImpactCategory::HardDependency, ImpactLevel::Direct, [$static, $runtime]);

        $staticWeight = $model->forReason($staticOnly, $this->byId([$static]));
        $observedWeight = $model->forReason($observed, $this->byId([$static, $runtime]));

        self::assertSame('static_only', $staticWeight->factors['runtime_status']);
        self::assertSame('observed', $observedWeight->factors['runtime_status']);
        self::assertGreaterThan($staticWeight->score, $observedWeight->score);
    }

    public function test_runtime_only_relation_scores_lower_than_static_only(): void
    {
        $model = new ImpactWeightModel();
        $runtime = $this->evidence(Certainty::Certain, EvidenceOrigin::Runtime);
        $reason = $this->reason(ImpactCategory::HardDependency, ImpactLevel::Direct, [$runtime]);

        $weight = $model->forReason($reason, $this->byId([$runtime]));

        self::assertSame('runtime_only', $weight->factors['runtime_status']);
        self::assertLessThan(0.9, $weight->factors['runtime_factor']);
    }

    public function test_missing_evidence_lookup_is_conservative_not_fatal(): void
    {
        $model = new ImpactWeightModel();
        $evidence = $this->evidence(Certainty::Certain, EvidenceOrigin::StaticAst);
        $reason = $this->reason(ImpactCategory::HardDependency, ImpactLevel::Direct, [$evidence]);

        // Empty lookup map: the evidence id referenced by the reason cannot be resolved.
        $weight = $model->forReason($reason, []);

        self::assertSame('unknown', $weight->factors['confidence']);
        self::assertGreaterThanOrEqual(0.0, $weight->score);
        self::assertLessThanOrEqual(1.0, $weight->score);
    }

    public function test_aggregate_picks_the_highest_scoring_reason(): void
    {
        $model = new ImpactWeightModel();
        $weak = $this->evidence(Certainty::Possible, EvidenceOrigin::StaticAst);
        $strong = $this->evidence(Certainty::Certain, EvidenceOrigin::StaticAst);
        $reasons = [
            $this->reason(ImpactCategory::Uncertainty, ImpactLevel::Possible, [$weak]),
            $this->reason(ImpactCategory::HardDependency, ImpactLevel::Direct, [$strong]),
        ];

        $weight = $model->aggregate($reasons, $this->byId([$weak, $strong]));

        self::assertSame(ImpactBand::Critical, $weight->band);
        self::assertFalse($weight->factors['mitigated_by_test_coverage']);
    }

    public function test_aggregate_with_empty_reasons_is_low_not_fatal(): void
    {
        $model = new ImpactWeightModel();

        $weight = $model->aggregate([], []);

        self::assertSame(0.0, $weight->score);
        self::assertSame(ImpactBand::Low, $weight->band);
    }

    public function test_test_coverage_mitigation_drops_one_band_but_never_below_low(): void
    {
        $model = new ImpactWeightModel();
        $evidence = $this->evidence(Certainty::Certain, EvidenceOrigin::StaticAst);
        $reason = $this->reason(ImpactCategory::HardDependency, ImpactLevel::Direct, [$evidence]);

        $unmitigated = $model->aggregate([$reason], $this->byId([$evidence]), testCovered: false);
        $mitigated = $model->aggregate([$reason], $this->byId([$evidence]), testCovered: true);

        self::assertSame(ImpactBand::Critical, $unmitigated->band);
        self::assertSame(ImpactBand::High, $mitigated->band);
        self::assertSame($unmitigated->score, $mitigated->score, 'Mitigation shifts the band, not the underlying score.');
        self::assertTrue($mitigated->factors['mitigated_by_test_coverage']);

        $lowReason = $this->reason(ImpactCategory::Uncertainty, ImpactLevel::Possible, [$evidence]);
        $lowMitigated = $model->aggregate([$lowReason], $this->byId([$evidence]), testCovered: true);
        self::assertSame(ImpactBand::Low, $lowMitigated->band);
    }

    public function test_band_thresholds_are_config_tunable(): void
    {
        $evidence = $this->evidence(Certainty::Certain, EvidenceOrigin::StaticAst);
        $reason = $this->reason(ImpactCategory::HardDependency, ImpactLevel::Direct, [$evidence]);
        // Default HardDependency+Direct+Certain+static-only scores 0.9.
        $default = (new ImpactWeightModel())->forReason($reason, $this->byId([$evidence]));
        self::assertSame(ImpactBand::Critical, $default->band);

        // Raising the critical threshold above the score demotes the same
        // reason to High without changing its score, proving bands are
        // driven by config rather than hardcoded.
        $tightened = ImpactWeightModel::fromConfig(['bands' => ['critical' => 0.95]])
            ->forReason($reason, $this->byId([$evidence]));

        self::assertSame(0.9, round($tightened->score, 4));
        self::assertSame(ImpactBand::High, $tightened->band);
    }

    private function evidence(Certainty $certainty, EvidenceOrigin $origin): EvidenceRecord
    {
        return new EvidenceRecord($origin, 'test-detector', $certainty);
    }

    /** @param list<EvidenceRecord> $records
     *  @return array<string,EvidenceRecord>
     */
    private function byId(array $records): array
    {
        $map = [];

        foreach ($records as $record) {
            $map[$record->id()] = $record;
        }

        return $map;
    }

    /** @param list<EvidenceRecord> $evidence
     *  @param list<string> $nodes
     */
    private function reason(
        ImpactCategory $category,
        ImpactLevel $level,
        array $evidence,
        array $nodes = ['source', 'target'],
    ): ImpactReason {
        $edges = [];

        for ($index = 0; $index < count($nodes) - 1; $index++) {
            $edges[] = 'edge-'.$index;
        }

        return new ImpactReason(
            $category,
            $level,
            $nodes,
            $edges,
            array_map(static fn (EvidenceRecord $record): string => $record->id(), $evidence),
            'test sentence',
        );
    }
}
