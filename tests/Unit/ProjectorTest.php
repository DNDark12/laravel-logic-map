<?php

namespace dndark\LogicMap\Tests\Unit;

use dndark\LogicMap\Domain\Graph;
use dndark\LogicMap\Domain\Node;
use dndark\LogicMap\Domain\Edge;
use dndark\LogicMap\Domain\Enums\NodeKind;
use dndark\LogicMap\Domain\Enums\EdgeType;
use dndark\LogicMap\Domain\Enums\Confidence;
use dndark\LogicMap\Projectors\OverviewProjector;
use dndark\LogicMap\Projectors\SubgraphProjector;
use dndark\LogicMap\Projectors\SearchProjector;
use dndark\LogicMap\Projectors\MetaProjector;
use dndark\LogicMap\Tests\TestCase;

class ProjectorTest extends TestCase
{
    protected Graph $graph;

    protected function setUp(): void
    {
        parent::setUp();
        $this->graph = $this->createTestGraph();
    }

    protected function createTestGraph(): Graph
    {
        $graph = new Graph();

        // Add nodes
        $graph->addNode(new Node('class:App\\Controllers\\UserController', NodeKind::CONTROLLER, 'UserController'));
        $graph->addNode(new Node('class:App\\Services\\UserService', NodeKind::SERVICE, 'UserService'));
        $graph->addNode(new Node('class:App\\Repositories\\UserRepository', NodeKind::REPOSITORY, 'UserRepository'));
        $graph->addNode(new Node('method:App\\Controllers\\UserController@index', NodeKind::CONTROLLER, 'index', parentId: 'class:App\\Controllers\\UserController'));
        $graph->addNode(new Node('method:App\\Services\\UserService@getUsers', NodeKind::SERVICE, 'getUsers', parentId: 'class:App\\Services\\UserService'));
        $graph->addNode(new Node('route:/users', NodeKind::ROUTE, '/users'));

        // Add edges
        $graph->addEdge(new Edge('route:/users', 'method:App\\Controllers\\UserController@index', EdgeType::ROUTE_TO_CONTROLLER, Confidence::HIGH));
        $graph->addEdge(new Edge('method:App\\Controllers\\UserController@index', 'method:App\\Services\\UserService@getUsers', EdgeType::CALL, Confidence::MEDIUM));
        $graph->addEdge(new Edge('method:App\\Services\\UserService@getUsers', 'class:App\\Repositories\\UserRepository', EdgeType::USE, Confidence::HIGH));

        return $graph;
    }

    /** @test */
    public function overview_projector_returns_nodes_and_edges()
    {
        $projector = new OverviewProjector();
        $result = $projector->overview($this->graph);

        $this->assertArrayHasKey('nodes', $result);
        $this->assertArrayHasKey('edges', $result);
        $this->assertArrayHasKey('meta', $result);

        $this->assertNotEmpty($result['nodes']);
        $this->assertNotEmpty($result['edges']);
    }

    /** @test */
    public function overview_projector_respects_node_limit()
    {
        // Create a large graph
        $largeGraph = new Graph();
        for ($i = 0; $i < 200; $i++) {
            $largeGraph->addNode(new Node("class:Test{$i}", NodeKind::UNKNOWN, "Test{$i}"));
        }

        $projector = new OverviewProjector();
        $result = $projector->overview($largeGraph);

        $this->assertLessThanOrEqual(
            config('logic-map.overview_node_limit', 100),
            count($result['nodes'])
        );
        $this->assertTrue($result['meta']['limit_applied']);
    }

    /** @test */
    public function overview_edges_only_reference_visible_nodes()
    {
        $projector = new OverviewProjector();
        $result = $projector->overview($this->graph);

        $nodeIds = array_map(fn($n) => $n['id'], $result['nodes']);

        foreach ($result['edges'] as $edge) {
            $this->assertContains($edge['source'], $nodeIds, "Edge source {$edge['source']} should be in visible nodes");
            $this->assertContains($edge['target'], $nodeIds, "Edge target {$edge['target']} should be in visible nodes");
        }
    }

    /** @test */
    public function subgraph_projector_returns_neighborhood()
    {
        $projector = new SubgraphProjector();
        $result = $projector->subgraph($this->graph, 'method:App\\Controllers\\UserController@index');

        $this->assertArrayHasKey('nodes', $result);
        $this->assertArrayHasKey('edges', $result);
        $this->assertArrayHasKey('meta', $result);

        // Should include the focus node
        $nodeIds = array_map(fn($n) => $n['id'], $result['nodes']);
        $this->assertContains('method:App\\Controllers\\UserController@index', $nodeIds);

        // Meta should indicate focus
        $this->assertEquals('method:App\\Controllers\\UserController@index', $result['meta']['focus_id']);
        $this->assertTrue($result['meta']['found']);
    }

    /** @test */
    public function subgraph_projector_returns_empty_for_unknown_node()
    {
        $projector = new SubgraphProjector();
        $result = $projector->subgraph($this->graph, 'nonexistent:id');

        $this->assertEmpty($result['nodes']);
        $this->assertEmpty($result['edges']);
        $this->assertFalse($result['meta']['found']);
    }

    /** @test */
    public function search_projector_finds_nodes_by_name()
    {
        $projector = new SearchProjector();
        $result = $projector->search($this->graph, 'User');

        $this->assertNotEmpty($result['nodes']);

        foreach ($result['nodes'] as $node) {
            $this->assertTrue(
                str_contains(strtolower($node['name'] ?? ''), 'user') ||
                str_contains(strtolower($node['id']), 'user'),
                "Node should contain 'user' in name or id"
            );
        }
    }

    /** @test */
    public function search_projector_filters_by_kind()
    {
        $projector = new SearchProjector();
        $result = $projector->search($this->graph, '', ['kind' => 'controller']);

        foreach ($result['nodes'] as $node) {
            $this->assertEquals('controller', $node['kind']);
        }
    }

    /** @test */
    public function search_projector_returns_all_nodes_for_empty_query()
    {
        $projector = new SearchProjector();
        $result = $projector->search($this->graph, '');

        $this->assertCount(count($this->graph->getNodes()), $result['nodes']);
    }

    /** @test */
    public function meta_projector_returns_statistics()
    {
        $projector = new MetaProjector();
        $result = $projector->getMeta($this->graph);

        $this->assertArrayHasKey('node_count', $result);
        $this->assertArrayHasKey('edge_count', $result);
        $this->assertArrayHasKey('kinds', $result);
        $this->assertArrayHasKey('edge_types', $result);
        $this->assertArrayHasKey('available_kinds', $result);
        $this->assertArrayHasKey('available_edge_types', $result);

        $this->assertEquals(6, $result['node_count']);
        $this->assertEquals(3, $result['edge_count']);
    }

    /** @test */
    public function meta_projector_counts_kinds_correctly()
    {
        $projector = new MetaProjector();
        $result = $projector->getMeta($this->graph);

        $this->assertArrayHasKey('controller', $result['kinds']);
        $this->assertArrayHasKey('service', $result['kinds']);
        $this->assertArrayHasKey('repository', $result['kinds']);
        $this->assertArrayHasKey('route', $result['kinds']);
    }

    /** @test */
    public function meta_projector_counts_edge_types()
    {
        $projector = new MetaProjector();
        $result = $projector->getMeta($this->graph);

        $this->assertArrayHasKey('route_to_controller', $result['edge_types']);
        $this->assertArrayHasKey('call', $result['edge_types']);
        $this->assertArrayHasKey('use', $result['edge_types']);
    }
}

