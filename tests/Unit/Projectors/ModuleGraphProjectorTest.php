<?php

namespace DNDark\LogicMap\Tests\Unit\Projectors;

use DNDark\LogicMap\Analysis\Laravel\ModuleResolver;
use DNDark\LogicMap\Domain\Graph\EdgeType;
use DNDark\LogicMap\Projectors\ModuleGraphProjector;
use DNDark\LogicMap\Tests\Support\CommerceFixtureTestCase;

final class ModuleGraphProjectorTest extends CommerceFixtureTestCase
{
    public function test_assignments_are_total_and_cross_module_edges_are_aggregated_by_type(): void
    {
        [$graph, , , $symbols] = $this->buildSemanticGraph();
        $resolver = new ModuleResolver(
            config('logic-map.modules.explicit', []),
            config('logic-map.modules.namespace_roots', []),
            config('logic-map.modules.directory_roots', []),
            config('logic-map.modules.fallback', 'Core'),
        );
        $assignments = $resolver->assign($symbols, $graph);
        $membershipEdges = array_values(array_filter(
            $graph->edges(),
            static fn ($edge): bool => $edge->type === EdgeType::MemberOfModule,
        ));

        self::assertCount(count($symbols->all()), $assignments);
        self::assertCount(count($assignments), $membershipEdges);
        self::assertNotContains('Core', array_map(
            static fn ($assignment): string => $assignment->module,
            $assignments,
        ));

        $projection = (new ModuleGraphProjector())->project($graph);
        $modules = array_column($projection['nodes'], 'name');
        self::assertContains('Orders', $modules);
        self::assertContains('Inventory', $modules);
        self::assertNotEmpty(array_filter(
            $projection['edges'],
            static fn (array $edge): bool => $edge['source_module'] === 'Orders'
                && $edge['target_module'] === 'Integration'
                && $edge['type'] === EdgeType::Queues->value,
        ), json_encode($projection['edges'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        self::assertNotEmpty(array_filter(
            $projection['edges'],
            static fn (array $edge): bool => $edge['source_module'] === 'Orders'
                && $edge['target_module'] === 'Inventory'
                && $edge['type'] === EdgeType::WritesModel->value,
        ), json_encode($projection['edges'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        self::assertEmpty(array_filter(
            $projection['edges'],
            static fn (array $edge): bool => in_array($edge['type'], [
                EdgeType::Contains->value,
                EdgeType::Defines->value,
                EdgeType::MemberOfModule->value,
            ], true),
        ));
    }
}
