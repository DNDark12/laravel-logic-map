<?php

namespace dndark\LogicMap\Tests\Unit;

use dndark\LogicMap\Analysis\Analyzers\HighCouplingAnalyzer;
use dndark\LogicMap\Analysis\Analyzers\HighInstabilityAnalyzer;
use dndark\LogicMap\Analysis\MetricsCalculator;
use dndark\LogicMap\Domain\Edge;
use dndark\LogicMap\Domain\Enums\EdgeType;
use dndark\LogicMap\Domain\Enums\NodeKind;
use dndark\LogicMap\Domain\Graph;
use dndark\LogicMap\Domain\Node;
use dndark\LogicMap\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class CutBAnalyzersTest extends TestCase
{
    protected MetricsCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new MetricsCalculator();
    }

    // ─── HighInstabilityAnalyzer ──────────────────────────

    #[Test]
    public function high_instability_detects_unstable_service()
    {
        $graph = new Graph();
        // Service depends on 5 things, nothing depends on it → instability=1.0
        $svc = new Node('svc', NodeKind::SERVICE, 'UnstableService');
        $graph->addNode($svc);
        for ($i = 0; $i < 5; $i++) {
            $dep = new Node("m{$i}", NodeKind::MODEL, "Model{$i}");
            $graph->addNode($dep);
            $graph->addEdge(new Edge('svc', "m{$i}", EdgeType::CALL));
        }

        $this->calculator->calculate($graph);

        $this->app['config']->set('logic-map.analysis.thresholds.high_instability', 0.9);

        $analyzer = new HighInstabilityAnalyzer();
        $violations = $analyzer->analyze($graph);

        $this->assertCount(1, $violations);
        $this->assertEquals('high_instability', $violations[0]->type);
        $this->assertEquals('medium', $violations[0]->severity);
        $this->assertEquals('svc', $violations[0]->nodeId);
    }

    #[Test]
    public function high_instability_skips_isolated_nodes()
    {
        $graph = new Graph();
        // Isolated node → coupling=0, instability is meaningless
        $graph->addNode(new Node('iso', NodeKind::SERVICE, 'Isolated'));

        $this->calculator->calculate($graph);

        $this->app['config']->set('logic-map.analysis.thresholds.high_instability', 0.9);

        $analyzer = new HighInstabilityAnalyzer();
        $this->assertEmpty($analyzer->analyze($graph));
    }

    #[Test]
    public function high_instability_ignores_stable_nodes()
    {
        $graph = new Graph();
        // svc depends on 1 thing, and 3 things depend on svc → instability ≈ 0.25
        $svc = new Node('svc', NodeKind::SERVICE, 'StableService');
        $graph->addNode($svc);
        $graph->addNode(new Node('m1', NodeKind::MODEL, 'M1'));
        $graph->addEdge(new Edge('svc', 'm1', EdgeType::CALL));
        for ($i = 0; $i < 3; $i++) {
            $depId = "r{$i}";
            // Use ROUTE kind — not in getAnalyzableNodes(), so won't be checked
            $graph->addNode(new Node($depId, NodeKind::ROUTE, "Route{$i}"));
            $graph->addEdge(new Edge($depId, 'svc', EdgeType::CALL));
        }

        $this->calculator->calculate($graph);

        $this->app['config']->set('logic-map.analysis.thresholds.high_instability', 0.9);

        $analyzer = new HighInstabilityAnalyzer();
        // svc has instability ≈ 0.25 (stable), m1 has instability 0.0 (stable)
        $this->assertEmpty($analyzer->analyze($graph));
    }

    // ─── HighCouplingAnalyzer ────────────────────────────

    #[Test]
    public function high_coupling_detects_hub_node()
    {
        $graph = new Graph();
        $hub = new Node('hub', NodeKind::SERVICE, 'HubService');
        $graph->addNode($hub);

        // fan_in = 12, fan_out = 12 → coupling = 24
        for ($i = 0; $i < 12; $i++) {
            $inId = "in{$i}";
            $outId = "out{$i}";
            $graph->addNode(new Node($inId, NodeKind::CONTROLLER, "Ctrl{$i}"));
            $graph->addNode(new Node($outId, NodeKind::MODEL, "Model{$i}"));
            $graph->addEdge(new Edge($inId, 'hub', EdgeType::CALL));
            $graph->addEdge(new Edge('hub', $outId, EdgeType::CALL));
        }

        $this->calculator->calculate($graph);

        $this->app['config']->set('logic-map.analysis.thresholds.high_coupling', 20);

        $analyzer = new HighCouplingAnalyzer();
        $violations = $analyzer->analyze($graph);

        $this->assertCount(1, $violations);
        $this->assertEquals('high_coupling', $violations[0]->type);
        $this->assertEquals('medium', $violations[0]->severity);
        $this->assertEquals('hub', $violations[0]->nodeId);
    }

    #[Test]
    public function high_coupling_ignores_low_coupling()
    {
        $graph = new Graph();
        $graph->addNode(new Node('svc', NodeKind::SERVICE, 'SimpleService'));
        $graph->addNode(new Node('m1', NodeKind::MODEL, 'M1'));
        $graph->addEdge(new Edge('svc', 'm1', EdgeType::CALL));

        $this->calculator->calculate($graph);

        $this->app['config']->set('logic-map.analysis.thresholds.high_coupling', 20);

        $analyzer = new HighCouplingAnalyzer();
        $this->assertEmpty($analyzer->analyze($graph));
    }
}
