<?php

namespace DNDark\LogicMap\Tests\Unit\Analysis\Laravel;

use DNDark\LogicMap\Analysis\Laravel\Detectors\TestReferenceDetector;
use DNDark\LogicMap\Domain\Graph\EdgeType;
use DNDark\LogicMap\Domain\Graph\NodeId;
use DNDark\LogicMap\Domain\Graph\NodeKind;
use DNDark\LogicMap\Tests\Support\CommerceFixtureTestCase;

final class TestReferenceDetectorTest extends CommerceFixtureTestCase
{
    public function test_conventional_laravel_test_references_emit_static_reference_edges(): void
    {
        [$graph, , $files, $symbols] = $this->buildSemanticGraph();
        $result = (new TestReferenceDetector())->detect($files, $symbols, $graph);
        $test = NodeId::named(
            NodeKind::Test,
            'tests/Feature/CancelOrderTest.php::test_cancel_order_flow',
        );

        self::assertTrue($graph->hasNode($test));
        self::assertSame('Orders', $result['tests'][0]->attributes['module']);

        $targets = array_map(
            static fn ($edge): string => $edge->target->value,
            $graph->outgoing($test, [EdgeType::CoveredByTest]),
        );

        foreach ([
            'route:POST:orders/{order}/cancel',
            'table:orders',
            'class:Fixtures\CommerceApp\Events\OrderCancelled',
            'class:Fixtures\CommerceApp\Jobs\ReconcileInventoryJob',
            'class:Fixtures\CommerceApp\Services\OrderService',
            'method:Fixtures\CommerceApp\Services\OrderService::cancel',
        ] as $target) {
            self::assertContains($target, $targets, $target);
        }

        foreach ($graph->outgoing($test, [EdgeType::CoveredByTest]) as $edge) {
            self::assertSame('reference', $edge->evidence[0]->attributes['coverage_kind']);
        }
    }
}
