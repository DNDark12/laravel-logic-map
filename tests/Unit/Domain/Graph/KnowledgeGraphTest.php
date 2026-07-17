<?php

namespace DNDark\LogicMap\Tests\Unit\Domain\Graph;

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
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class KnowledgeGraphTest extends TestCase
{
    public function test_retains_distinct_call_sites_between_the_same_nodes(): void
    {
        [$graph, $source, $target] = $this->graphWithTwoMethods();

        $first = GraphEdge::fromEvidence(
            $source,
            $target,
            EdgeType::Calls,
            $this->evidence(12, 'call-resolver'),
        );
        $second = GraphEdge::fromEvidence(
            $source,
            $target,
            EdgeType::Calls,
            $this->evidence(24, 'call-resolver'),
        );

        $graph->addEdge($first);
        $graph->addEdge($second);

        self::assertCount(2, $graph->edges());
        self::assertNotSame($first->id, $second->id);
    }

    public function test_multiedge_identity_preserves_detector_origin_and_condition(): void
    {
        [$graph, $source, $target] = $this->graphWithTwoMethods();

        $edges = [
            GraphEdge::fromEvidence($source, $target, EdgeType::Calls, $this->evidence(12, 'detector-a')),
            GraphEdge::fromEvidence($source, $target, EdgeType::Calls, $this->evidence(12, 'detector-b')),
            GraphEdge::fromEvidence(
                $source,
                $target,
                EdgeType::Calls,
                $this->evidence(12, 'detector-a', EvidenceOrigin::LaravelBoot),
            ),
            GraphEdge::fromEvidence(
                $source,
                $target,
                EdgeType::Calls,
                $this->evidence(12, 'detector-a', EvidenceOrigin::StaticAst, '$order->isOpen()'),
            ),
        ];

        foreach ($edges as $edge) {
            $graph->addEdge($edge);
        }

        self::assertCount(4, array_unique(array_column($graph->edges(), 'id')));
    }

    public function test_formatting_only_whitespace_dedupes_and_distinct_evidence_is_appended_once(): void
    {
        [$graph, $source, $target] = $this->graphWithTwoMethods();

        $firstEvidence = $this->evidence(
            12,
            'call-resolver',
            EvidenceOrigin::StaticAst,
            '$order->isOpen()',
            '$this->gateway->save($order)',
        );
        $sameSiteEvidence = new EvidenceRecord(
            EvidenceOrigin::StaticAst,
            'call-resolver',
            Certainty::Probable,
            new SourceLocation('app/Services/OrderService.php', 12, 12),
            '  $this->gateway->save(  $order ) ',
            ' $order->isOpen( ) ',
            ['reason' => 'interface-implementation'],
        );
        $distinctPayloadSameSite = new EvidenceRecord(
            EvidenceOrigin::StaticAst,
            'call-resolver',
            Certainty::Probable,
            new SourceLocation('app/Services/OrderService.php', 12, 12),
            '$this->gateway->save($order)',
            '$order->isOpen()',
            ['reason' => 'confirmed-by-symbol-table'],
        );

        $first = GraphEdge::fromEvidence($source, $target, EdgeType::Calls, $firstEvidence);
        $sameSite = GraphEdge::fromEvidence($source, $target, EdgeType::Calls, $sameSiteEvidence);
        $distinct = GraphEdge::fromEvidence($source, $target, EdgeType::Calls, $distinctPayloadSameSite);

        self::assertSame($first->id, $sameSite->id);

        $graph->addEdge($first);
        $graph->addEdge($sameSite);
        $graph->addEdge($distinct);
        $graph->addEdge($distinct);

        self::assertCount(1, $graph->edges());
        self::assertCount(3, $graph->edges()[0]->evidence);
        self::assertCount(3, $graph->evidence());
    }

    public function test_rejects_edges_whose_endpoints_are_missing(): void
    {
        $graph = new KnowledgeGraph();
        $source = NodeId::method('App\\Source', 'run');
        $target = NodeId::method('App\\Target', 'run');
        $graph->addNode($this->methodNode($source));

        $this->expectException(InvalidArgumentException::class);
        $graph->addEdge(GraphEdge::fromEvidence(
            $source,
            $target,
            EdgeType::Calls,
            $this->evidence(12, 'call-resolver'),
        ));
    }

    public function test_applies_classification_without_changing_identity(): void
    {
        $graph = new KnowledgeGraph();
        $id = NodeId::symbol(NodeKind::ClassSymbol, 'App\\Services\\OrderService');
        $graph->addNode(new GraphNode(
            $id,
            NodeKind::ClassSymbol,
            'OrderService',
            'App\\Services\\OrderService',
            new SourceLocation('app/Services/OrderService.php', 10, 30),
        ));

        $graph->applyClassification($id, NodeKind::Service, Certainty::Probable, 'namespace convention');

        $classified = $graph->nodes()[0];
        self::assertSame($id->value, $classified->id->value);
        self::assertSame(NodeKind::Service, $classified->kind);
        self::assertSame('probable', $classified->attributes['classification_certainty']);
        self::assertSame('namespace convention', $classified->attributes['classification_reason']);

        $this->expectException(InvalidArgumentException::class);
        $graph->applyClassification($id, NodeKind::Repository, Certainty::Probable, 'conflicting convention');
    }

    public function test_classification_cannot_rewrite_non_class_nodes(): void
    {
        $graph = new KnowledgeGraph();
        $id = NodeId::route('get', '/orders');
        $graph->addNode(new GraphNode($id, NodeKind::Route, 'GET /orders', null, null));

        $this->expectException(InvalidArgumentException::class);
        $graph->applyClassification($id, NodeKind::Controller, Certainty::Certain, 'route action');
    }

    private function graphWithTwoMethods(): array
    {
        $graph = new KnowledgeGraph();
        $source = NodeId::method('App\\Services\\OrderService', 'cancel');
        $target = NodeId::method('App\\Repositories\\OrderRepository', 'save');
        $graph->addNode($this->methodNode($source));
        $graph->addNode($this->methodNode($target));

        return [$graph, $source, $target];
    }

    private function methodNode(NodeId $id): GraphNode
    {
        return new GraphNode($id, NodeKind::Method, $id->value, null, null);
    }

    private function evidence(
        int $line,
        string $detector,
        EvidenceOrigin $origin = EvidenceOrigin::StaticAst,
        ?string $condition = null,
        string $expression = '$this->gateway->save($order)',
    ): EvidenceRecord {
        return new EvidenceRecord(
            $origin,
            $detector,
            Certainty::Probable,
            new SourceLocation('app/Services/OrderService.php', $line, $line),
            $expression,
            $condition,
            ['reason' => 'interface-implementation'],
        );
    }
}
