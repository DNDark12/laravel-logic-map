<?php

namespace DNDark\LogicMap\Tests\Unit\Services\Impact;

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
use DNDark\LogicMap\Services\Impact\TestScopeResolver;
use PHPUnit\Framework\TestCase;

final class TestScopeResolverTest extends TestCase
{
    public function test_ranks_runtime_direct_framework_and_module_tests_with_reasons(): void
    {
        $graph = new KnowledgeGraph();
        $method = NodeId::method('App\Orders\OrderService', 'cancel');
        $route = NodeId::route('POST', 'orders/{order}/cancel');
        $module = NodeId::fromString('module:Orders');
        $runtime = NodeId::fromString('test:tests/Feature/RuntimeTest.php::test_runtime');
        $direct = NodeId::fromString('test:tests/Unit/DirectTest.php::test_direct');
        $framework = NodeId::fromString('test:tests/Feature/RouteTest.php::test_route');
        $moduleTest = NodeId::fromString('test:tests/Feature/Orders/FallbackTest.php::test_fallback');

        foreach ([
            [$method, NodeKind::Method, []],
            [$route, NodeKind::Route, []],
            [$module, NodeKind::Module, []],
            [$runtime, NodeKind::Test, ['module' => 'Other']],
            [$direct, NodeKind::Test, ['module' => 'Other']],
            [$framework, NodeKind::Test, ['module' => 'Other']],
            [$moduleTest, NodeKind::Test, ['module' => 'Orders']],
        ] as [$id, $kind, $attributes]) {
            $graph->addNode(new GraphNode($id, $kind, $id->value, null, null, $attributes));
        }

        $this->edge($graph, $method, $module, EdgeType::MemberOfModule, EvidenceOrigin::StaticAst, 'module', 1);
        $this->edge($graph, $runtime, $method, EdgeType::CoveredByTest, EvidenceOrigin::Runtime, 'runtime_method', 2);
        $this->edge($graph, $direct, $method, EdgeType::CoveredByTest, EvidenceOrigin::StaticAst, 'direct_symbol', 3);
        $this->edge($graph, $framework, $route, EdgeType::CoveredByTest, EvidenceOrigin::StaticAst, 'route', 4);

        $rows = (new TestScopeResolver($graph))->resolve([$method, $route], 10);

        self::assertSame([
            $runtime->value,
            $direct->value,
            $framework->value,
            $moduleTest->value,
        ], array_column($rows, 'test_node_id'));
        self::assertSame([1, 2, 3, 4], array_column($rows, 'rank'));

        foreach ($rows as $row) {
            self::assertNotSame('', $row['reason']);
            self::assertNotEmpty($row['evidence_ids']);
        }
    }

    private function edge(
        KnowledgeGraph $graph,
        NodeId $source,
        NodeId $target,
        EdgeType $type,
        EvidenceOrigin $origin,
        string $referenceKind,
        int $line,
    ): void {
        $graph->addEdge(GraphEdge::fromEvidence(
            $source,
            $target,
            $type,
            new EvidenceRecord(
                $origin,
                'test-scope-fixture',
                Certainty::Certain,
                new SourceLocation('tests/Test.php', $line, $line),
                $referenceKind,
                null,
                ['coverage_kind' => $origin === EvidenceOrigin::Runtime ? 'runtime' : 'reference', 'reference_kind' => $referenceKind],
            ),
        ));
    }
}
