<?php

namespace DNDark\LogicMap\Tests\Unit\Services\Workflow;

use DNDark\LogicMap\Analysis\Laravel\SemanticEdgeFactory;
use DNDark\LogicMap\Domain\Graph\Certainty;
use DNDark\LogicMap\Domain\Graph\EdgeType;
use DNDark\LogicMap\Domain\Graph\EvidenceOrigin;
use DNDark\LogicMap\Domain\Graph\GraphNode;
use DNDark\LogicMap\Domain\Graph\KnowledgeGraph;
use DNDark\LogicMap\Domain\Graph\NodeId;
use DNDark\LogicMap\Domain\Graph\NodeKind;
use DNDark\LogicMap\Domain\Graph\SourceLocation;
use DNDark\LogicMap\Services\Workflow\ModuleWorkflowBuilder;
use DNDark\LogicMap\Tests\Support\CommerceFixtureTestCase;

final class ModuleWorkflowBuilderTest extends CommerceFixtureTestCase
{
    public function test_module_entry_workflows_remain_independent_without_a_synthetic_global_sequence(): void
    {
        $graph = new KnowledgeGraph();
        $module = NodeId::named(NodeKind::Module, 'Orders');
        $cancelRoute = NodeId::route('POST', 'orders/{order}/cancel');
        $shipRoute = NodeId::route('POST', 'orders/{order}/ship');
        $cancel = NodeId::method('App\Orders\OrderController', 'cancel');
        $ship = NodeId::method('App\Orders\OrderController', 'ship');
        $graph->addNode(new GraphNode($module, NodeKind::Module, 'Orders', null, null));
        $graph->addNode(new GraphNode($cancelRoute, NodeKind::Route, 'Cancel order', null, null));
        $graph->addNode(new GraphNode($shipRoute, NodeKind::Route, 'Ship order', null, null));
        $graph->addNode(new GraphNode($cancel, NodeKind::Method, 'cancel', 'App\Orders\OrderController::cancel', new SourceLocation('app/Orders/OrderController.php', 10, 15)));
        $graph->addNode(new GraphNode($ship, NodeKind::Method, 'ship', 'App\Orders\OrderController::ship', new SourceLocation('app/Orders/OrderController.php', 17, 22)));

        foreach ([[$cancelRoute, $cancel, 10], [$shipRoute, $ship, 17]] as [$route, $handler, $line]) {
            SemanticEdgeFactory::add($graph, $route, EdgeType::HandlesRoute, $handler, EvidenceOrigin::StaticAst, 'test-route', Certainty::Certain, new SourceLocation('routes/web.php', $line, $line), 'route');
            SemanticEdgeFactory::add($graph, $handler, EdgeType::MemberOfModule, $module, EvidenceOrigin::StaticAst, 'test-module', Certainty::Certain, null, null, $handler->value, $handler->value);
        }

        $workflow = (new ModuleWorkflowBuilder($graph, [], [], [
            'max_nodes' => 500,
            'max_depth' => 12,
        ]))->build($module);
        self::assertSame([
            $cancelRoute->value,
            $shipRoute->value,
        ], array_map(static fn ($entry): string => $entry->entrypoint->value, $workflow->entryWorkflows));

        foreach ($workflow->entryWorkflows as $entry) {
            $stepIds = array_fill_keys(array_map(static fn ($step): string => $step->id, $entry->steps), true);

            foreach ($entry->transitions as $transition) {
                self::assertArrayHasKey($transition->from, $stepIds);
                self::assertArrayHasKey($transition->to, $stepIds);
            }
        }
    }

    public function test_cross_module_summary_uses_shared_resources_and_real_queue_edges(): void
    {
        [$graph, $diagnostics, , , , $outputs] = $this->buildSemanticGraph();
        $workflow = (new ModuleWorkflowBuilder($graph, $outputs, $diagnostics, [
            'max_nodes' => 500,
            'max_depth' => 12,
        ]))->build(
            NodeId::named(NodeKind::Module, 'Orders'),
        );
        $relations = [];

        foreach ($workflow->outboundRelations as $edgeType => $items) {
            foreach ($items as $item) {
                $relations[] = implode('|', [
                    $item['source_module'],
                    $item['target_module'],
                    $edgeType,
                    $item['resource_id'] ?? '',
                ]);
            }
        }

        self::assertContains('module:Orders|module:Inventory|writes_column|column:inventory_stocks.quantity', $relations);
        self::assertContains('module:Orders|module:Shipping|writes_column|column:orders.status', $relations);
        self::assertContains('module:Orders|module:Dashboard|writes_column|column:orders.status', $relations);
        self::assertContains('module:Orders|module:Integration|queues|', $relations);
        self::assertNotEmpty($workflow->sharedResources);
    }
}
