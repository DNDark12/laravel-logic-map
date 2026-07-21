<?php

namespace DNDark\LogicMap\Tests\Unit\Projectors;

use DateTimeImmutable;
use DNDark\LogicMap\Domain\Graph\Certainty;
use DNDark\LogicMap\Domain\Graph\EdgeType;
use DNDark\LogicMap\Domain\Graph\EvidenceOrigin;
use DNDark\LogicMap\Domain\Graph\EvidenceRecord;
use DNDark\LogicMap\Domain\Graph\GraphEdge;
use DNDark\LogicMap\Domain\Graph\GraphNode;
use DNDark\LogicMap\Domain\Graph\KnowledgeGraph;
use DNDark\LogicMap\Domain\Graph\NodeId;
use DNDark\LogicMap\Domain\Graph\NodeKind;
use DNDark\LogicMap\Domain\Graph\SourceLocation;
use DNDark\LogicMap\Domain\Impact\AffectedSymbol;
use DNDark\LogicMap\Domain\Impact\ChangedSymbol;
use DNDark\LogicMap\Domain\Impact\ChangeType;
use DNDark\LogicMap\Domain\Impact\ImpactCategory;
use DNDark\LogicMap\Domain\Impact\ImpactLevel;
use DNDark\LogicMap\Domain\Impact\ImpactReason;
use DNDark\LogicMap\Domain\Impact\ImpactReport;
use DNDark\LogicMap\Domain\Snapshot\GraphSnapshot;
use DNDark\LogicMap\Projectors\ImpactWeightProjector;
use DNDark\LogicMap\Support\NodeIdCodec;
use PHPUnit\Framework\TestCase;

final class ImpactWeightProjectorTest extends TestCase
{
    public function test_it_shapes_weighted_affected_symbols_sorted_by_descending_score(): void
    {
        [$snapshot, $report, $changedId] = $this->buildFixture();

        $projected = (new ImpactWeightProjector())->project($snapshot, $report, $changedId->value);

        self::assertSame(['target', 'snapshot_id', 'truncation', 'affected'], array_keys($projected));
        self::assertSame($changedId->value, $projected['target']);
        self::assertSame($snapshot->id, $projected['snapshot_id']);

        $nodeIds = array_column($projected['affected'], 'node_id');
        self::assertSame([
            'method:App\\ShippingService::authorize', // hard_dependency, direct, certain -> 0.9 critical
            'external:https://erp.example/cancel',     // external_contract, direct, certain -> 0.72 critical
            'method:App\\ShippingService::canShip',    // shared_state, shared_resource, certain -> 0.378, mitigated
            'module:Shipping',                          // module, direct, certain -> 0.36 medium
            $changedId->value,                          // uncertainty, possible, possible -> 0.0243 low
        ], $nodeIds);

        $top = $projected['affected'][0];
        self::assertSame('hard_dependency', $top['category']);
        self::assertSame('direct', $top['level']);
        self::assertSame(0.9, $top['score']);
        self::assertSame('critical', $top['band']);
        self::assertSame([$changedId->value, 'method:App\\ShippingService::authorize'], $top['reason_chain']);

        $sharedState = $projected['affected'][2];
        self::assertSame('medium', $sharedState['factors']['pre_mitigation_band']);
        self::assertSame('low', $sharedState['band'], 'test coverage drops shared-state one band');
        self::assertEqualsWithDelta(0.378, $sharedState['score'], 0.0001, 'mitigation shifts the band, not the score');
        self::assertTrue($sharedState['factors']['mitigated_by_test_coverage']);
        self::assertSame(
            ['test:tests/Feature/ShippingTest.php::test_can_ship'],
            $sharedState['suggested_tests'],
        );

        self::assertSame([], $top['suggested_tests'], 'symbols without a covering test edge get an empty list');
        self::assertSame((new NodeIdCodec())->encode($top['node_id']), $top['encoded_id']);
    }

    public function test_it_is_byte_stable_across_two_projections(): void
    {
        [$snapshot, $report, $changedId] = $this->buildFixture();
        $projector = new ImpactWeightProjector();

        $first = $projector->project($snapshot, $report, $changedId->value);
        $second = (new ImpactWeightProjector())->project($snapshot, $report, $changedId->value);

        self::assertSame($first, $second);
        self::assertSame($projector->json($snapshot, $report, $changedId->value), $projector->json($snapshot, $report, $changedId->value));
    }

    /** @return array{0: GraphSnapshot, 1: ImpactReport, 2: NodeId} */
    private function buildFixture(): array
    {
        $graph = new KnowledgeGraph();

        $changedId = NodeId::method('App\\Services\\OrderService', 'cancel');
        $hardDependencyId = NodeId::method('App\\ShippingService', 'authorize');
        $externalId = NodeId::fromString('external:https://erp.example/cancel');
        $sharedStateId = NodeId::method('App\\ShippingService', 'canShip');
        $moduleId = NodeId::named(NodeKind::Module, 'Shipping');
        $testId = NodeId::named(NodeKind::Test, 'tests/Feature/ShippingTest.php::test_can_ship');

        $graph->addNode(new GraphNode($changedId, NodeKind::Method, 'cancel', 'App\\Services\\OrderService::cancel', new SourceLocation('app/OrderService.php', 20, 22)));
        $graph->addNode(new GraphNode($hardDependencyId, NodeKind::Method, 'authorize', 'App\\ShippingService::authorize', null));
        $graph->addNode(new GraphNode($externalId, NodeKind::ExternalEndpoint, 'erp.example/cancel', null, null));
        $graph->addNode(new GraphNode($sharedStateId, NodeKind::Method, 'canShip', 'App\\ShippingService::canShip', null));
        $graph->addNode(new GraphNode($moduleId, NodeKind::Module, 'Shipping', null, null));
        $graph->addNode(new GraphNode($testId, NodeKind::Test, 'test_can_ship', null, null));

        $graph->addEdge(GraphEdge::fromEvidence(
            $testId,
            $sharedStateId,
            EdgeType::CoveredByTest,
            new EvidenceRecord(EvidenceOrigin::StaticAst, 'test-reference', Certainty::Certain, attributes: ['reference_kind' => 'direct_symbol', 'registration_key' => 'covers-can-ship']),
        ));

        $evidence = new EvidenceRecord(
            EvidenceOrigin::StaticAst,
            'impact-weight-projector-test',
            Certainty::Certain,
            new SourceLocation('app/OrderService.php', 20, 22),
            'changed lines',
        );
        $possibleEvidence = new EvidenceRecord(
            EvidenceOrigin::StaticAst,
            'impact-weight-projector-test',
            Certainty::Possible,
            new SourceLocation('app/OrderService.php', 20, 22),
            'maybe related',
        );

        $change = new ChangedSymbol(
            ChangeType::Modified,
            $changedId,
            $changedId,
            'app/OrderService.php',
            'app/OrderService.php',
            20,
            22,
            20,
            22,
            $evidence,
        );

        $affected = [
            new AffectedSymbol($hardDependencyId, [new ImpactReason(
                ImpactCategory::HardDependency,
                ImpactLevel::Direct,
                [$changedId->value, $hardDependencyId->value],
                ['edge-hard-dependency'],
                [$evidence->id()],
                'Reason hard_dependency.',
            )]),
            new AffectedSymbol($externalId, [new ImpactReason(
                ImpactCategory::ExternalContract,
                ImpactLevel::Direct,
                [$changedId->value, $externalId->value],
                ['edge-external'],
                [$evidence->id()],
                'Reason external_contract.',
            )]),
            new AffectedSymbol($sharedStateId, [new ImpactReason(
                ImpactCategory::SharedState,
                ImpactLevel::SharedResource,
                [$changedId->value, $hardDependencyId->value, $sharedStateId->value],
                ['edge-shared-1', 'edge-shared-2'],
                [$evidence->id()],
                'Reason shared_state.',
            )]),
            new AffectedSymbol($moduleId, [new ImpactReason(
                ImpactCategory::Module,
                ImpactLevel::Direct,
                [$changedId->value, $moduleId->value],
                ['edge-module'],
                [$evidence->id()],
                'Reason module.',
            )]),
            new AffectedSymbol($changedId, [new ImpactReason(
                ImpactCategory::Uncertainty,
                ImpactLevel::Possible,
                [$changedId->value],
                [],
                [$possibleEvidence->id()],
                'Reason uncertainty.',
            )]),
        ];

        $report = new ImpactReport(
            [$change],
            $affected,
            [$evidence, $possibleEvidence],
            array_fill_keys(array_map(static fn (ImpactCategory $category): string => $category->value, ImpactCategory::cases()), [
                'truncated' => false,
                'max_depth' => 3,
                'visited_count' => 5,
                'edge_count' => 5,
                'omitted_count' => 0,
                'frontier' => [],
            ]),
        );

        $fingerprint = hash('sha256', 'impact-weight-projector-fixture');
        $id = hash('sha256', '1'."\0".$fingerprint);

        $snapshot = new GraphSnapshot(
            $id,
            1,
            '2.0-ai-1',
            new DateTimeImmutable('2026-07-20T00:00:00+00:00'),
            $fingerprint,
            [],
            $graph,
            [],
            [],
        );

        return [$snapshot, $report, $changedId];
    }
}
