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
use DNDark\LogicMap\Domain\Snapshot\GraphSnapshot;
use DNDark\LogicMap\Projectors\GraphJsonProjector;
use DNDark\LogicMap\Support\NodeIdCodec;
use PHPUnit\Framework\TestCase;

final class GraphJsonProjectorTest extends TestCase
{
    public function test_it_emits_the_full_bundle_shape_sorted_and_byte_stable(): void
    {
        $snapshot = $this->buildSnapshot();
        $projector = new GraphJsonProjector();

        $first = $projector->project($snapshot);

        self::assertSame(
            ['schema_version', 'analysis_version', 'snapshot_id', 'fingerprint', 'nodes', 'edges', 'modules'],
            array_keys($first),
        );
        self::assertSame(1, $first['schema_version']);
        self::assertSame('2.0-ai-1', $first['analysis_version']);
        self::assertSame($snapshot->id, $first['snapshot_id']);
        self::assertSame($snapshot->sourceFingerprint, $first['fingerprint']);

        $nodeIds = array_column($first['nodes'], 'id');
        $sorted = $nodeIds;
        sort($sorted, SORT_STRING);
        self::assertSame($sorted, $nodeIds, 'nodes must be sorted by canonical id');

        $moduleIds = array_column($first['modules'], 'id');
        $sortedModules = $moduleIds;
        sort($sortedModules, SORT_STRING);
        self::assertSame($sortedModules, $moduleIds, 'modules must be sorted by canonical id');

        // Byte-stability: projecting the same snapshot twice must be identical.
        $second = (new GraphJsonProjector())->project($snapshot);
        self::assertSame($first, $second);
        self::assertSame($projector->json($snapshot), $projector->json($snapshot));
    }

    public function test_node_rows_carry_module_membership_location_and_classification(): void
    {
        $snapshot = $this->buildSnapshot();
        $bundle = (new GraphJsonProjector())->project($snapshot);

        $service = $this->findNode($bundle, 'class:App\\Billing\\InvoiceService');

        self::assertNotNull($service);
        self::assertSame('module:Billing', $service['module']);
        self::assertSame('app/Billing/InvoiceService.php', $service['file']);
        self::assertSame(10, $service['start_line']);
        self::assertSame(40, $service['end_line']);
        self::assertSame('certain', $service['classification_certainty']);
        self::assertSame((new NodeIdCodec())->encode('class:App\\Billing\\InvoiceService'), $service['encoded_id']);

        $route = $this->findNode($bundle, 'route:POST:invoices');
        self::assertNotNull($route);
        self::assertSame('module:Billing', $route['module']);
    }

    public function test_edge_rows_carry_the_strongest_evidence_certainty_and_evidence_ids(): void
    {
        $snapshot = $this->buildSnapshot();
        $bundle = (new GraphJsonProjector())->project($snapshot);

        $calls = array_values(array_filter($bundle['edges'], static fn (array $edge): bool => $edge['type'] === 'calls'));

        self::assertCount(1, $calls);
        self::assertSame('certain', $calls[0]['confidence'], 'strongest of possible+certain evidence must win');
        self::assertCount(2, $calls[0]['evidence_ids']);
    }

    public function test_module_rows_carry_member_count_and_entrypoint_ids_only(): void
    {
        $snapshot = $this->buildSnapshot();
        $bundle = (new GraphJsonProjector())->project($snapshot);

        self::assertCount(1, $bundle['modules']);
        $module = $bundle['modules'][0];

        self::assertSame('module:Billing', $module['id']);
        self::assertSame('Billing', $module['name']);
        self::assertSame(2, $module['member_count']);
        self::assertSame(['route:POST:invoices'], $module['entrypoint_ids']);
    }

    private function findNode(array $bundle, string $id): ?array
    {
        foreach ($bundle['nodes'] as $node) {
            if ($node['id'] === $id) {
                return $node;
            }
        }

        return null;
    }

    private function buildSnapshot(): GraphSnapshot
    {
        $graph = new KnowledgeGraph();

        $module = NodeId::named(NodeKind::Module, 'Billing');
        $service = NodeId::symbol(NodeKind::ClassSymbol, 'App\\Billing\\InvoiceService');
        $gateway = NodeId::symbol(NodeKind::ClassSymbol, 'App\\Billing\\PaymentGateway');
        $route = NodeId::route('POST', 'invoices');

        $graph->addNode(new GraphNode($module, NodeKind::Module, 'Billing', null, null));
        $graph->addNode(new GraphNode(
            $service,
            NodeKind::ClassSymbol,
            'InvoiceService',
            'App\\Billing\\InvoiceService',
            new SourceLocation('app/Billing/InvoiceService.php', 10, 40),
            ['classification_certainty' => 'certain', 'classification_reason' => 'namespace_convention'],
        ));
        $graph->addNode(new GraphNode(
            $gateway,
            NodeKind::ClassSymbol,
            'PaymentGateway',
            'App\\Billing\\PaymentGateway',
            new SourceLocation('app/Billing/PaymentGateway.php', 5, 20),
        ));
        $graph->addNode(new GraphNode($route, NodeKind::Route, 'POST invoices', null, null));

        $graph->addEdge(GraphEdge::fromEvidence(
            $service,
            $module,
            EdgeType::MemberOfModule,
            new EvidenceRecord(EvidenceOrigin::StaticAst, 'module-classifier', Certainty::Certain, attributes: ['registration_key' => 'service-module']),
        ));
        $graph->addEdge(GraphEdge::fromEvidence(
            $route,
            $module,
            EdgeType::MemberOfModule,
            new EvidenceRecord(EvidenceOrigin::StaticAst, 'module-classifier', Certainty::Certain, attributes: ['registration_key' => 'route-module']),
        ));

        $callsEdge = GraphEdge::fromEvidence(
            $service,
            $gateway,
            EdgeType::Calls,
            new EvidenceRecord(EvidenceOrigin::StaticAst, 'call-detector', Certainty::Possible, attributes: ['registration_key' => 'weak-call']),
        );
        $callsEdge->addEvidence(new EvidenceRecord(EvidenceOrigin::StaticAst, 'call-detector', Certainty::Certain, attributes: ['registration_key' => 'strong-call']));
        $graph->addEdge($callsEdge);

        $fingerprint = hash('sha256', 'graph-json-projector-fixture');
        $id = hash('sha256', '1'."\0".$fingerprint);

        return new GraphSnapshot(
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
    }
}
