<?php

namespace dndark\LogicMap\Tests\Unit;

use dndark\LogicMap\Analysis\MetricsCalculator;
use dndark\LogicMap\Domain\Edge;
use dndark\LogicMap\Domain\Enums\EdgeType;
use dndark\LogicMap\Domain\Enums\NodeKind;
use dndark\LogicMap\Domain\Graph;
use dndark\LogicMap\Domain\Node;
use dndark\LogicMap\Tests\TestCase;

class MetricsCalculatorTest extends TestCase
{
    protected MetricsCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new MetricsCalculator();
    }

    /** @test */
    public function it_populates_all_seven_metrics_on_each_node()
    {
        $graph = $this->buildSimpleGraph();
        $this->calculator->calculate($graph);

        foreach ($graph->getNodes() as $node) {
            $this->assertArrayHasKey('in_degree', $node->metrics);
            $this->assertArrayHasKey('out_degree', $node->metrics);
            $this->assertArrayHasKey('fan_in', $node->metrics);
            $this->assertArrayHasKey('fan_out', $node->metrics);
            $this->assertArrayHasKey('instability', $node->metrics);
            $this->assertArrayHasKey('coupling', $node->metrics);
            $this->assertArrayHasKey('depth', $node->metrics);
        }
    }

    /** @test */
    public function it_calculates_in_degree_as_raw_edge_count()
    {
        $graph = new Graph();
        $graph->addNode(new Node('a', NodeKind::SERVICE, 'ServiceA'));
        $graph->addNode(new Node('b', NodeKind::SERVICE, 'ServiceB'));
        $graph->addNode(new Node('c', NodeKind::SERVICE, 'ServiceC'));

        // Two edges from different sources to 'a'
        $graph->addEdge(new Edge('b', 'a', EdgeType::CALL));
        $graph->addEdge(new Edge('c', 'a', EdgeType::CALL));
        // Duplicate edge from b → a (counts as 2 for in_degree, 1 for fan_in)
        $graph->addEdge(new Edge('b', 'a', EdgeType::USE));

        $this->calculator->calculate($graph);

        $nodeA = $graph->getNode('a');
        $this->assertEquals(3, $nodeA->metrics['in_degree']);  // raw edge count
        $this->assertEquals(2, $nodeA->metrics['fan_in']);     // unique sources (b, c)
    }

    /** @test */
    public function it_calculates_out_degree_as_raw_edge_count()
    {
        $graph = new Graph();
        $graph->addNode(new Node('a', NodeKind::CONTROLLER, 'CtrlA'));
        $graph->addNode(new Node('b', NodeKind::SERVICE, 'ServiceB'));
        $graph->addNode(new Node('c', NodeKind::MODEL, 'ModelC'));

        // a → b (two edges), a → c (one edge)
        $graph->addEdge(new Edge('a', 'b', EdgeType::CALL));
        $graph->addEdge(new Edge('a', 'b', EdgeType::USE));
        $graph->addEdge(new Edge('a', 'c', EdgeType::CALL));

        $this->calculator->calculate($graph);

        $nodeA = $graph->getNode('a');
        $this->assertEquals(3, $nodeA->metrics['out_degree']); // raw edge count
        $this->assertEquals(2, $nodeA->metrics['fan_out']);     // unique targets (b, c)
    }

    /** @test */
    public function it_calculates_instability_correctly()
    {
        $graph = new Graph();
        $graph->addNode(new Node('a', NodeKind::SERVICE, 'ServiceA'));
        $graph->addNode(new Node('b', NodeKind::SERVICE, 'ServiceB'));
        $graph->addNode(new Node('c', NodeKind::SERVICE, 'ServiceC'));

        // a depends on b and c (fan_out=2), nothing depends on a (fan_in=0)
        $graph->addEdge(new Edge('a', 'b', EdgeType::CALL));
        $graph->addEdge(new Edge('a', 'c', EdgeType::CALL));

        $this->calculator->calculate($graph);

        // a: fan_in=0, fan_out=2 → instability = 2/(0+2) = 1.0
        $this->assertEquals(1.0, $graph->getNode('a')->metrics['instability']);

        // b: fan_in=1, fan_out=0 → instability = 0/(1+0) = 0.0
        $this->assertEquals(0.0, $graph->getNode('b')->metrics['instability']);
    }

    /** @test */
    public function it_calculates_coupling_as_sum_of_fan_in_and_fan_out()
    {
        $graph = new Graph();
        $graph->addNode(new Node('a', NodeKind::SERVICE, 'ServiceA'));
        $graph->addNode(new Node('b', NodeKind::SERVICE, 'ServiceB'));
        $graph->addNode(new Node('c', NodeKind::SERVICE, 'ServiceC'));

        $graph->addEdge(new Edge('a', 'b', EdgeType::CALL));
        $graph->addEdge(new Edge('c', 'a', EdgeType::CALL));

        $this->calculator->calculate($graph);

        // a: fan_in=1 (c), fan_out=1 (b) → coupling=2
        $this->assertEquals(2, $graph->getNode('a')->metrics['coupling']);
    }

    /** @test */
    public function it_calculates_depth_from_route_entrypoints()
    {
        $graph = new Graph();
        $graph->addNode(new Node('r1', NodeKind::ROUTE, 'GET /users'));
        $graph->addNode(new Node('ctrl', NodeKind::CONTROLLER, 'UserController'));
        $graph->addNode(new Node('svc', NodeKind::SERVICE, 'UserService'));
        $graph->addNode(new Node('model', NodeKind::MODEL, 'User'));

        $graph->addEdge(new Edge('r1', 'ctrl', EdgeType::ROUTE_TO_CONTROLLER));
        $graph->addEdge(new Edge('ctrl', 'svc', EdgeType::CALL));
        $graph->addEdge(new Edge('svc', 'model', EdgeType::CALL));

        // Set traversal to include route_to_controller
        $this->app['config']->set('logic-map.analysis.depth.traversal_edge_types', [
            'route_to_controller', 'call', 'dispatch', 'use',
        ]);

        $this->calculator->calculate($graph);

        $this->assertEquals(0, $graph->getNode('r1')->metrics['depth']);
        $this->assertEquals(1, $graph->getNode('ctrl')->metrics['depth']);
        $this->assertEquals(2, $graph->getNode('svc')->metrics['depth']);
        $this->assertEquals(3, $graph->getNode('model')->metrics['depth']);
    }

    /** @test */
    public function unreachable_nodes_have_null_depth()
    {
        $graph = new Graph();
        $graph->addNode(new Node('r1', NodeKind::ROUTE, 'GET /users'));
        $graph->addNode(new Node('ctrl', NodeKind::CONTROLLER, 'UserController'));
        $graph->addNode(new Node('orphan', NodeKind::SERVICE, 'OrphanService'));

        $graph->addEdge(new Edge('r1', 'ctrl', EdgeType::ROUTE_TO_CONTROLLER));

        $this->app['config']->set('logic-map.analysis.depth.traversal_edge_types', [
            'route_to_controller', 'call',
        ]);

        $this->calculator->calculate($graph);

        $this->assertEquals(0, $graph->getNode('r1')->metrics['depth']);
        $this->assertEquals(1, $graph->getNode('ctrl')->metrics['depth']);
        $this->assertNull($graph->getNode('orphan')->metrics['depth']);
    }

    /** @test */
    public function depth_uses_shortest_path_with_cycles()
    {
        $graph = new Graph();
        $graph->addNode(new Node('r1', NodeKind::ROUTE, 'GET /'));
        $graph->addNode(new Node('a', NodeKind::CONTROLLER, 'A'));
        $graph->addNode(new Node('b', NodeKind::SERVICE, 'B'));

        $graph->addEdge(new Edge('r1', 'a', EdgeType::ROUTE_TO_CONTROLLER));
        $graph->addEdge(new Edge('a', 'b', EdgeType::CALL));
        $graph->addEdge(new Edge('b', 'a', EdgeType::CALL)); // cycle

        $this->app['config']->set('logic-map.analysis.depth.traversal_edge_types', [
            'route_to_controller', 'call',
        ]);

        $this->calculator->calculate($graph);

        $this->assertEquals(1, $graph->getNode('a')->metrics['depth']);
        $this->assertEquals(2, $graph->getNode('b')->metrics['depth']);
    }

    /** @test */
    public function isolated_node_has_zero_metrics()
    {
        $graph = new Graph();
        $graph->addNode(new Node('lonely', NodeKind::SERVICE, 'LonelyService'));

        $this->calculator->calculate($graph);

        $node = $graph->getNode('lonely');
        $this->assertEquals(0, $node->metrics['in_degree']);
        $this->assertEquals(0, $node->metrics['out_degree']);
        $this->assertEquals(0, $node->metrics['fan_in']);
        $this->assertEquals(0, $node->metrics['fan_out']);
        $this->assertEquals(0.0, $node->metrics['instability']);
        $this->assertEquals(0, $node->metrics['coupling']);
        $this->assertNull($node->metrics['depth']);
    }

    /** @test */
    public function it_flags_hub_utility_nodes_in_metadata()
    {
        $this->app['config']->set('logic-map.analysis.ui_thresholds.hub_utility_fan_in', 4);

        $graph = new Graph();
        $graph->addNode(new Node('hub', NodeKind::SERVICE, 'HubService'));
        $graph->addNode(new Node('a', NodeKind::SERVICE, 'A'));
        $graph->addNode(new Node('b', NodeKind::SERVICE, 'B'));
        $graph->addNode(new Node('c', NodeKind::SERVICE, 'C'));
        $graph->addNode(new Node('d', NodeKind::SERVICE, 'D'));
        $graph->addNode(new Node('e', NodeKind::SERVICE, 'E'));

        $graph->addEdge(new Edge('a', 'hub', EdgeType::CALL));
        $graph->addEdge(new Edge('b', 'hub', EdgeType::CALL));
        $graph->addEdge(new Edge('c', 'hub', EdgeType::CALL));
        $graph->addEdge(new Edge('d', 'hub', EdgeType::CALL));
        $graph->addEdge(new Edge('e', 'hub', EdgeType::CALL));

        $this->calculator->calculate($graph);

        $hub = $graph->getNode('hub');
        $this->assertTrue($hub->metadata['isHubUtility'] ?? false);
        $this->assertTrue($hub->metadata['is_hub_utility'] ?? false);
    }

    /** @test */
    public function it_does_not_flag_hub_utility_when_node_has_outgoing_calls_or_is_route()
    {
        $this->app['config']->set('logic-map.analysis.ui_thresholds.hub_utility_fan_in', 2);

        $graph = new Graph();
        $graph->addNode(new Node('hub', NodeKind::SERVICE, 'HubService'));
        $graph->addNode(new Node('route', NodeKind::ROUTE, 'GET /health'));
        $graph->addNode(new Node('a', NodeKind::SERVICE, 'A'));
        $graph->addNode(new Node('b', NodeKind::SERVICE, 'B'));
        $graph->addNode(new Node('c', NodeKind::SERVICE, 'C'));
        $graph->addNode(new Node('target', NodeKind::SERVICE, 'Target'));

        $graph->addEdge(new Edge('a', 'hub', EdgeType::CALL));
        $graph->addEdge(new Edge('b', 'hub', EdgeType::CALL));
        $graph->addEdge(new Edge('c', 'hub', EdgeType::CALL));
        $graph->addEdge(new Edge('hub', 'target', EdgeType::CALL)); // has outgoing

        $graph->addEdge(new Edge('a', 'route', EdgeType::CALL));
        $graph->addEdge(new Edge('b', 'route', EdgeType::CALL));
        $graph->addEdge(new Edge('c', 'route', EdgeType::CALL)); // high fan_in but kind=route

        $this->calculator->calculate($graph);

        $this->assertFalse($graph->getNode('hub')->metadata['isHubUtility'] ?? true);
        $this->assertFalse($graph->getNode('route')->metadata['isHubUtility'] ?? true);
    }

    protected function buildSimpleGraph(): Graph
    {
        $graph = new Graph();
        $graph->addNode(new Node('r1', NodeKind::ROUTE, 'GET /'));
        $graph->addNode(new Node('c1', NodeKind::CONTROLLER, 'HomeController'));
        $graph->addNode(new Node('s1', NodeKind::SERVICE, 'HomeService'));

        $graph->addEdge(new Edge('r1', 'c1', EdgeType::ROUTE_TO_CONTROLLER));
        $graph->addEdge(new Edge('c1', 's1', EdgeType::CALL));

        return $graph;
    }
}
