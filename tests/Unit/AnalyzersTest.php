<?php

namespace Tests\Unit;

use dndark\LogicMap\Analysis\Analyzers\CircularDependencyAnalyzer;
use dndark\LogicMap\Analysis\Analyzers\DeadCodeAnalyzer;
use dndark\LogicMap\Analysis\Analyzers\FatControllerAnalyzer;
use dndark\LogicMap\Analysis\Analyzers\OrphanAnalyzer;
use dndark\LogicMap\Analysis\ArchitectureAnalyzer;
use dndark\LogicMap\Analysis\MetricsCalculator;
use dndark\LogicMap\Analysis\RiskCalculator;
use dndark\LogicMap\Domain\AnalysisReport;
use dndark\LogicMap\Domain\Edge;
use dndark\LogicMap\Domain\Enums\EdgeType;
use dndark\LogicMap\Domain\Enums\NodeKind;
use dndark\LogicMap\Domain\Graph;
use dndark\LogicMap\Domain\Node;
use dndark\LogicMap\Domain\Violation;
use dndark\LogicMap\LogicMapServiceProvider;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class AnalyzersTest extends TestCase
{
    protected function getPackageProviders($app)
    {
        return [
            LogicMapServiceProvider::class,
        ];
    }

    protected MetricsCalculator $metricsCalculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->metricsCalculator = new MetricsCalculator();
    }

    // ─── FatControllerAnalyzer ────────────────────────────

    /** @test */
    public function fat_controller_detects_high_fan_out()
    {
        $graph = $this->buildFatControllerGraph(15);
        $this->metricsCalculator->calculate($graph);

        $this->app['config']->set('logic-map.analysis.thresholds.fat_controller_fan_out', 10);

        $analyzer = new FatControllerAnalyzer();
        $violations = $analyzer->analyze($graph);

        $this->assertCount(1, $violations);
        $this->assertEquals('fat_controller', $violations[0]->type);
        $this->assertEquals('high', $violations[0]->severity);
        $this->assertEquals('ctrl', $violations[0]->nodeId);
    }

    /** @test */
    public function fat_controller_ignores_below_threshold()
    {
        $graph = $this->buildFatControllerGraph(5);
        $this->metricsCalculator->calculate($graph);

        $this->app['config']->set('logic-map.analysis.thresholds.fat_controller_fan_out', 10);

        $analyzer = new FatControllerAnalyzer();
        $violations = $analyzer->analyze($graph);

        $this->assertEmpty($violations);
    }

    /** @test */
    public function fat_controller_only_checks_controllers()
    {
        $graph = new Graph();
        // A service with high fan_out should NOT trigger
        $graph->addNode(new Node('svc', NodeKind::SERVICE, 'BigService'));
        for ($i = 0; $i < 15; $i++) {
            $n = new Node("dep{$i}", NodeKind::MODEL, "Model{$i}");
            $graph->addNode($n);
            $graph->addEdge(new Edge('svc', "dep{$i}", EdgeType::CALL));
        }

        $this->metricsCalculator->calculate($graph);

        $this->app['config']->set('logic-map.analysis.thresholds.fat_controller_fan_out', 10);
        $analyzer = new FatControllerAnalyzer();

        $this->assertEmpty($analyzer->analyze($graph));
    }

    // ─── CircularDependencyAnalyzer ──────────────────────

    /** @test */
    public function circular_dependency_detects_two_node_cycle()
    {
        $graph = new Graph();
        $graph->addNode(new Node('a', NodeKind::SERVICE, 'ServiceA'));
        $graph->addNode(new Node('b', NodeKind::SERVICE, 'ServiceB'));
        $graph->addEdge(new Edge('a', 'b', EdgeType::CALL));
        $graph->addEdge(new Edge('b', 'a', EdgeType::CALL));

        $analyzer = new CircularDependencyAnalyzer();
        $violations = $analyzer->analyze($graph);

        $this->assertNotEmpty($violations);
        $this->assertEquals('circular_dependency', $violations[0]->type);
        $this->assertEquals('critical', $violations[0]->severity);

        // Each node in cycle gets a violation
        $nodeIds = array_map(fn(Violation $v) => $v->nodeId, $violations);
        $this->assertContains('a', $nodeIds);
        $this->assertContains('b', $nodeIds);
    }

    /** @test */
    public function circular_dependency_detects_three_node_cycle()
    {
        $graph = new Graph();
        $graph->addNode(new Node('a', NodeKind::SERVICE, 'A'));
        $graph->addNode(new Node('b', NodeKind::SERVICE, 'B'));
        $graph->addNode(new Node('c', NodeKind::SERVICE, 'C'));
        $graph->addEdge(new Edge('a', 'b', EdgeType::CALL));
        $graph->addEdge(new Edge('b', 'c', EdgeType::CALL));
        $graph->addEdge(new Edge('c', 'a', EdgeType::CALL));

        $analyzer = new CircularDependencyAnalyzer();
        $violations = $analyzer->analyze($graph);

        $this->assertCount(3, $violations);

        $nodeIds = array_map(fn(Violation $v) => $v->nodeId, $violations);
        $this->assertContains('a', $nodeIds);
        $this->assertContains('b', $nodeIds);
        $this->assertContains('c', $nodeIds);
    }

    /** @test */
    public function circular_dependency_ignores_acyclic_graph()
    {
        $graph = new Graph();
        $graph->addNode(new Node('a', NodeKind::CONTROLLER, 'A'));
        $graph->addNode(new Node('b', NodeKind::SERVICE, 'B'));
        $graph->addNode(new Node('c', NodeKind::MODEL, 'C'));
        $graph->addEdge(new Edge('a', 'b', EdgeType::CALL));
        $graph->addEdge(new Edge('b', 'c', EdgeType::CALL));

        $analyzer = new CircularDependencyAnalyzer();
        $this->assertEmpty($analyzer->analyze($graph));
    }

    // ─── OrphanAnalyzer ──────────────────────────────────

    /** @test */
    public function orphan_detects_zero_fan_in_on_eligible_kinds()
    {
        $graph = new Graph();
        $graph->addNode(new Node('ctrl', NodeKind::CONTROLLER, 'OrphanCtrl'));
        $graph->addNode(new Node('svc', NodeKind::SERVICE, 'OrphanService'));
        // No edges pointing TO these nodes

        $this->metricsCalculator->calculate($graph);

        $this->app['config']->set('logic-map.analysis.orphan.eligible_kinds', ['controller', 'service']);

        $analyzer = new OrphanAnalyzer();
        $violations = $analyzer->analyze($graph);

        $this->assertCount(2, $violations);
        $this->assertEquals('orphan', $violations[0]->type);
        $this->assertEquals('low', $violations[0]->severity);
    }

    /** @test */
    public function orphan_skips_ineligible_kinds()
    {
        $graph = new Graph();
        // Events and listeners should NOT be flagged as orphans
        $graph->addNode(new Node('evt', NodeKind::EVENT, 'UserCreated'));
        $graph->addNode(new Node('lst', NodeKind::LISTENER, 'SendMail'));

        $this->metricsCalculator->calculate($graph);

        $this->app['config']->set('logic-map.analysis.orphan.eligible_kinds', ['controller', 'service', 'model']);

        $analyzer = new OrphanAnalyzer();
        $this->assertEmpty($analyzer->analyze($graph));
    }

    /** @test */
    public function orphan_respects_ignore_node_ids()
    {
        $graph = new Graph();
        $graph->addNode(new Node('ctrl', NodeKind::CONTROLLER, 'SpecialCtrl'));

        $this->metricsCalculator->calculate($graph);

        $this->app['config']->set('logic-map.analysis.orphan.eligible_kinds', ['controller']);
        $this->app['config']->set('logic-map.analysis.orphan.ignore_node_ids', ['ctrl']);

        $analyzer = new OrphanAnalyzer();
        $this->assertEmpty($analyzer->analyze($graph));
    }

    /** @test */
    public function orphan_does_not_flag_nodes_with_fan_in()
    {
        $graph = new Graph();
        $graph->addNode(new Node('ctrl', NodeKind::CONTROLLER, 'UsedCtrl'));
        $graph->addNode(new Node('r1', NodeKind::ROUTE, 'GET /'));
        $graph->addEdge(new Edge('r1', 'ctrl', EdgeType::ROUTE_TO_CONTROLLER));

        $this->metricsCalculator->calculate($graph);

        $this->app['config']->set('logic-map.analysis.orphan.eligible_kinds', ['controller']);

        $analyzer = new OrphanAnalyzer();
        $this->assertEmpty($analyzer->analyze($graph));
    }

    // ─── DeadCodeAnalyzer ───────────────────────────────

    /** @test */
    public function dead_code_flags_unreachable_nodes_by_depth()
    {
        $graph = new Graph();
        $graph->addNode(new Node('r1', NodeKind::ROUTE, 'GET /'));
        $graph->addNode(new Node('ctrl', NodeKind::CONTROLLER, 'HomeController'));
        $graph->addNode(new Node('svc_a', NodeKind::SERVICE, 'A'));
        $graph->addNode(new Node('svc_b', NodeKind::SERVICE, 'B'));

        // Reachable chain
        $graph->addEdge(new Edge('r1', 'ctrl', EdgeType::ROUTE_TO_CONTROLLER));
        // Unreachable cluster (no route path)
        $graph->addEdge(new Edge('svc_a', 'svc_b', EdgeType::CALL));

        $this->app['config']->set('logic-map.analysis.depth.traversal_edge_types', [
            'route_to_controller', 'call', 'dispatch', 'listen', 'use',
        ]);
        $this->app['config']->set('logic-map.analysis.dead_code.eligible_kinds', ['service']);

        $this->metricsCalculator->calculate($graph);

        $analyzer = new DeadCodeAnalyzer();
        $violations = $analyzer->analyze($graph);

        $this->assertCount(2, $violations);
        $this->assertEquals('dead_code', $violations[0]->type);
        $this->assertEquals('low', $violations[0]->severity);

        $ids = array_map(fn(Violation $v) => $v->nodeId, $violations);
        $this->assertContains('svc_a', $ids);
        $this->assertContains('svc_b', $ids);
        $this->assertNotContains('ctrl', $ids);
    }

    /** @test */
    public function dead_code_ignores_reachable_and_non_eligible_nodes()
    {
        $graph = new Graph();
        $graph->addNode(new Node('r1', NodeKind::ROUTE, 'GET /users'));
        $graph->addNode(new Node('ctrl', NodeKind::CONTROLLER, 'UserController'));
        $graph->addNode(new Node('svc', NodeKind::SERVICE, 'UserService'));
        $graph->addNode(new Node('evt', NodeKind::EVENT, 'UserCreated'));

        $graph->addEdge(new Edge('r1', 'ctrl', EdgeType::ROUTE_TO_CONTROLLER));
        $graph->addEdge(new Edge('ctrl', 'svc', EdgeType::CALL));

        $this->app['config']->set('logic-map.analysis.depth.traversal_edge_types', [
            'route_to_controller', 'call', 'dispatch', 'listen', 'use',
        ]);
        $this->app['config']->set('logic-map.analysis.dead_code.eligible_kinds', ['controller', 'service']);

        $this->metricsCalculator->calculate($graph);

        $analyzer = new DeadCodeAnalyzer();
        $violations = $analyzer->analyze($graph);

        $this->assertEmpty($violations);
    }

    // ─── RiskCalculator ──────────────────────────────────

    /** @test */
    public function risk_calculator_assigns_critical_for_circular_dependency()
    {
        $graph = new Graph();
        $graph->addNode(new Node('a', NodeKind::SERVICE, 'A'));
        $this->metricsCalculator->calculate($graph);

        $violations = [
            new Violation('circular_dependency', 'critical', 'a', 'Cycle detected'),
        ];

        $this->app['config']->set('logic-map.analysis.weights', [
            'critical' => 25, 'high' => 10, 'medium' => 5, 'low' => 1,
        ]);

        $calculator = new RiskCalculator();
        $riskMap = $calculator->calculate($graph, $violations);

        $this->assertArrayHasKey('a', $riskMap);
        $this->assertEquals('critical', $riskMap['a']['risk']);
        $this->assertGreaterThanOrEqual(20, $riskMap['a']['score']);
    }

    /** @test */
    public function risk_calculator_cach_b_medium_from_metrics_directly()
    {
        $graph = new Graph();
        $node = new Node('a', NodeKind::SERVICE, 'A');
        $node->metrics = [
            'in_degree' => 0, 'out_degree' => 0,
            'fan_in' => 0, 'fan_out' => 0,
            'instability' => 0.95,  // above 0.9 threshold
            'coupling' => 5,
            'depth' => null,
        ];
        $graph->addNode($node);

        $this->app['config']->set('logic-map.analysis.thresholds', [
            'high_instability' => 0.9,
            'high_coupling' => 20,
        ]);
        $this->app['config']->set('logic-map.analysis.weights', [
            'critical' => 25, 'high' => 10, 'medium' => 5, 'low' => 1,
        ]);

        $calculator = new RiskCalculator();
        $riskMap = $calculator->calculate($graph, []);

        $this->assertArrayHasKey('a', $riskMap);
        $this->assertEquals('medium', $riskMap['a']['risk']);
        $this->assertContains('instability=0.95 (threshold=0.9)', $riskMap['a']['reasons']);
    }

    /** @test */
    public function risk_calculator_returns_empty_for_healthy_nodes()
    {
        $graph = new Graph();
        $node = new Node('a', NodeKind::SERVICE, 'A');
        $node->metrics = [
            'in_degree' => 1, 'out_degree' => 1,
            'fan_in' => 1, 'fan_out' => 1,
            'instability' => 0.5,
            'coupling' => 2,
            'depth' => 1,
        ];
        $graph->addNode($node);

        $this->app['config']->set('logic-map.analysis.thresholds', [
            'high_instability' => 0.9,
            'high_coupling' => 20,
        ]);

        $calculator = new RiskCalculator();
        $riskMap = $calculator->calculate($graph, []);

        $this->assertArrayNotHasKey('a', $riskMap);
    }

    // ─── ArchitectureAnalyzer (orchestrator) ─────────────

    /** @test */
    public function architecture_analyzer_produces_valid_report()
    {
        $graph = $this->buildSimpleGraph();
        $this->metricsCalculator->calculate($graph);

        $analyzer = new ArchitectureAnalyzer();
        $report = $analyzer->analyze($graph);

        $this->assertIsInt($report->healthScore);
        $this->assertGreaterThanOrEqual(0, $report->healthScore);
        $this->assertLessThanOrEqual(100, $report->healthScore);
        $this->assertIsString($report->grade);
        $this->assertContains($report->grade, ['A', 'B', 'C', 'D', 'F']);
        $this->assertArrayHasKey('critical', $report->summary);
        $this->assertArrayHasKey('high', $report->summary);
        $this->assertArrayHasKey('medium', $report->summary);
        $this->assertArrayHasKey('low', $report->summary);
        $this->assertArrayHasKey('analysis_config_hash', $report->metadata);
        $this->assertArrayHasKey('analyzer_count', $report->metadata);
    }

    /** @test */
    public function architecture_analyzer_returns_empty_when_disabled()
    {
        $this->app['config']->set('logic-map.analysis.enabled', false);

        $graph = $this->buildSimpleGraph();
        $this->metricsCalculator->calculate($graph);

        $analyzer = new ArchitectureAnalyzer();
        $report = $analyzer->analyze($graph);

        $this->assertEquals(100, $report->healthScore);
        $this->assertEquals('A', $report->grade);
        $this->assertEmpty($report->violations);
    }

    /** @test */
    public function architecture_analyzer_config_hash_changes_with_config()
    {
        $analyzer = new ArchitectureAnalyzer();

        $this->app['config']->set('logic-map.analysis.thresholds.fat_controller_fan_out', 10);
        $hash1 = $analyzer->getConfigHash();

        $this->app['config']->set('logic-map.analysis.thresholds.fat_controller_fan_out', 20);
        $hash2 = $analyzer->getConfigHash();

        $this->assertNotEquals($hash1, $hash2);
    }

    /** @test */
    public function analysis_report_serializes_and_deserializes()
    {
        $graph = $this->buildSimpleGraph();
        $this->metricsCalculator->calculate($graph);

        $analyzer = new ArchitectureAnalyzer();
        $report = $analyzer->analyze($graph);

        $array = $report->toArray();
        $restored = AnalysisReport::fromArray($array);

        $this->assertEquals($report->healthScore, $restored->healthScore);
        $this->assertEquals($report->grade, $restored->grade);
        $this->assertEquals($report->summary, $restored->summary);
        $this->assertCount(count($report->violations), $restored->violations);
    }

    // ─── Helpers ─────────────────────────────────────────

    protected function buildFatControllerGraph(int $fanOut): Graph
    {
        $graph = new Graph();
        $graph->addNode(new Node('ctrl', NodeKind::CONTROLLER, 'FatController'));

        for ($i = 0; $i < $fanOut; $i++) {
            $depId = "dep{$i}";
            $graph->addNode(new Node($depId, NodeKind::SERVICE, "Service{$i}"));
            $graph->addEdge(new Edge('ctrl', $depId, EdgeType::CALL));
        }

        return $graph;
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
