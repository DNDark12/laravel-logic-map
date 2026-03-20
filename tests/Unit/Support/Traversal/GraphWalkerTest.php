<?php

namespace dndark\LogicMap\Tests\Unit\Support\Traversal;

use dndark\LogicMap\Domain\Edge;
use dndark\LogicMap\Domain\Enums\EdgeType;
use dndark\LogicMap\Domain\Enums\NodeKind;
use dndark\LogicMap\Domain\Graph;
use dndark\LogicMap\Domain\Node;
use dndark\LogicMap\Support\Traversal\GraphWalker;
use dndark\LogicMap\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class GraphWalkerTest extends TestCase
{
    #[Test]
    public function it_visits_nodes_in_deterministic_priority_order()
    {
        $graph = new Graph();
        $graph->addNode(new Node('root', NodeKind::CONTROLLER, 'Root'));
        $graph->addNode(new Node('child_dispatch', NodeKind::JOB, 'Child Dispatch'));
        $graph->addNode(new Node('child_use', NodeKind::MODEL, 'Child Use'));
        $graph->addNode(new Node('child_call', NodeKind::SERVICE, 'Child Call'));
        
        // Add edges: USE (priority 4), CALL (priority 1), DISPATCH (priority 2)
        // Expected order: CALL, then DISPATCH, then USE
        $graph->addEdge(new Edge('root', 'child_use', EdgeType::USE));
        $graph->addEdge(new Edge('root', 'child_call', EdgeType::CALL));
        $graph->addEdge(new Edge('root', 'child_dispatch', EdgeType::DISPATCH));

        $walker = new GraphWalker();
        $steps = $walker->walk($graph, 'root', 'downstream', 1);

        // depth 0 is root
        dd($steps);
        $this->assertEquals('root', $steps[0]->node->id);
        
        // depth 1, highest priority first
        $this->assertEquals('child_call', $steps[1]->node->id);     // CALL
        $this->assertEquals('child_dispatch', $steps[2]->node->id); // DISPATCH
        $this->assertEquals('child_use', $steps[3]->node->id);      // USE
    }

    #[Test]
    public function it_breaks_ties_lexically_when_priorities_are_equal()
    {
        $graph = new Graph();
        $graph->addNode(new Node('root', NodeKind::CONTROLLER, 'Root'));
        $graph->addNode(new Node('service_b', NodeKind::SERVICE, 'Service B'));
        $graph->addNode(new Node('service_a', NodeKind::SERVICE, 'Service A'));
        $graph->addNode(new Node('service_c', NodeKind::SERVICE, 'Service C'));
        
        $graph->addEdge(new Edge('root', 'service_b', EdgeType::CALL));
        $graph->addEdge(new Edge('root', 'service_c', EdgeType::CALL));
        $graph->addEdge(new Edge('root', 'service_a', EdgeType::CALL));

        $walker = new GraphWalker();
        $steps = $walker->walk($graph, 'root', 'downstream', 1);

        dd($steps);
        $this->assertEquals('root', $steps[0]->node->id);
        $this->assertEquals('service_a', $steps[1]->node->id);
        $this->assertEquals('service_b', $steps[2]->node->id);
        $this->assertEquals('service_c', $steps[3]->node->id);
    }
}
