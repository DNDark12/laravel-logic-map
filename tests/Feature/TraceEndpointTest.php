<?php

namespace dndark\LogicMap\Tests\Feature;

use dndark\LogicMap\Contracts\GraphRepository;
use dndark\LogicMap\Domain\AnalysisReport;
use dndark\LogicMap\Domain\Edge;
use dndark\LogicMap\Domain\Enums\EdgeType;
use dndark\LogicMap\Domain\Enums\NodeKind;
use dndark\LogicMap\Domain\Graph;
use dndark\LogicMap\Domain\Node;
use dndark\LogicMap\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;

class TraceEndpointTest extends TestCase
{
    private const TARGET_ID = 'class:App\\Services\\Order\\OrderService';

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('logic-map:clear-cache');

        $repo = $this->app->make(GraphRepository::class);
        $repo->putSnapshot('fp-trace', $this->makeGraph());
        $repo->putAnalysisReport('fp-trace', $this->makeReport());
        $repo->setActiveFingerprint('fp-trace');
    }

    #[Test]
    public function trace_returns_correct_structure()
    {
        $url = route('logic-map.trace', ['id' => urlencode(self::TARGET_ID)]);
        $response = $this->getJson($url);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'ok',
            'data' => [
                'target'  => ['node_id', 'kind', 'name', 'module', 'risk'],
                'summary' => [
                    'direction', 'max_depth',
                    'segment_count', 'branch_count',
                    'async_hops', 'persistence_touch_count', 'truncated',
                ],
                'segments',
                'branch_points',
                'entrypoints',
                'persistence_touchpoints',
                '_resolution',
            ],
            'message',
            'errors',
        ]);

        $this->assertTrue($response->json('ok'));
    }

    #[Test]
    public function trace_returns_404_for_unknown_node()
    {
        $url = route('logic-map.trace', ['id' => urlencode('class:Does\\Not\\Exist')]);
        $response = $this->getJson($url);

        $response->assertStatus(404);
        $this->assertFalse($response->json('ok'));
        $this->assertSame('node_not_found', $response->json('errors.0.type'));
    }

    #[Test]
    public function trace_returns_422_for_invalid_direction()
    {
        $url = route('logic-map.trace', ['id' => urlencode(self::TARGET_ID)]) . '?direction=sideways';
        $response = $this->getJson($url);

        $response->assertStatus(422);
        $this->assertFalse($response->json('ok'));
        $this->assertSame('invalid_direction', $response->json('errors.0.type'));
    }

    #[Test]
    public function trace_returns_422_for_invalid_max_depth()
    {
        $base = route('logic-map.trace', ['id' => urlencode(self::TARGET_ID)]);

        $response = $this->getJson($base . '?max_depth=0');
        $response->assertStatus(422);
        $this->assertSame('invalid_max_depth', $response->json('errors.0.type'));

        $response2 = $this->getJson($base . '?max_depth=11');
        $response2->assertStatus(422);
        $this->assertSame('invalid_max_depth', $response2->json('errors.0.type'));
    }

    #[Test]
    public function trace_forward_segments_split_on_async_boundary()
    {
        $url = route('logic-map.trace', ['id' => urlencode(self::TARGET_ID)]) . '?direction=forward';
        $response = $this->getJson($url);

        $response->assertStatus(200);
        $segments = $response->json('data.segments');
        $this->assertNotEmpty($segments);

        $asyncSegments = array_filter($segments, fn($s) => $s['async_boundary'] === true);
        $this->assertNotEmpty($asyncSegments, 'Expected at least one async_boundary segment');

        $asyncSegment = reset($asyncSegments);
        $this->assertSame('async_dispatch', $asyncSegment['segment_type']);
    }

    #[Test]
    public function trace_forward_surfaces_persistence_touchpoints()
    {
        $url = route('logic-map.trace', ['id' => urlencode(self::TARGET_ID)]) . '?direction=forward';
        $response = $this->getJson($url);

        $response->assertStatus(200);
        $ids = array_column($response->json('data.persistence_touchpoints'), 'node_id');
        $this->assertContains('class:App\\Repositories\\OrderRepository', $ids);
    }

    #[Test]
    public function trace_backward_surfaces_route_entrypoints()
    {
        $url = route('logic-map.trace', ['id' => urlencode(self::TARGET_ID)]) . '?direction=backward';
        $response = $this->getJson($url);

        $response->assertStatus(200);
        $ids = array_column($response->json('data.entrypoints'), 'node_id');
        $this->assertContains('route:POST:/orders', $ids);
    }

    #[Test]
    public function trace_returns_404_when_snapshot_not_found()
    {
        $url = route('logic-map.trace', ['id' => urlencode(self::TARGET_ID)]) . '?snapshot=no-such-fp';
        $response = $this->getJson($url);

        $response->assertStatus(404);
        $this->assertSame('snapshot_not_found', $response->json('errors.0.type'));
    }

    #[Test]
    public function trace_summary_direction_matches_requested()
    {
        $url = route('logic-map.trace', ['id' => urlencode(self::TARGET_ID)]) . '?direction=backward';
        $response = $this->getJson($url);

        $response->assertStatus(200);
        $this->assertSame('backward', $response->json('data.summary.direction'));
    }

    // ─── Helpers ──────────────────────────────────────────────

    protected function makeGraph(): Graph
    {
        $graph = new Graph();

        $graph->addNode(new Node('route:POST:/orders', NodeKind::ROUTE, 'POST /orders'));
        $graph->addNode(new Node(
            'class:App\\Http\\Controllers\\OrderController',
            NodeKind::CONTROLLER, 'OrderController',
            metadata: ['module' => 'Order']
        ));
        $graph->addNode(new Node(
            'class:App\\Services\\Order\\OrderService',
            NodeKind::SERVICE, 'OrderService',
            metadata: ['module' => 'Order']
        ));
        $graph->addNode(new Node(
            'class:App\\Repositories\\OrderRepository',
            NodeKind::REPOSITORY, 'OrderRepository',
            metadata: ['module' => 'Order']
        ));
        $graph->addNode(new Node(
            'class:App\\Jobs\\PaymentJob',
            NodeKind::JOB, 'PaymentJob',
            metadata: ['module' => 'Payment']
        ));

        $graph->addEdge(new Edge('route:POST:/orders', 'class:App\\Http\\Controllers\\OrderController', EdgeType::ROUTE_TO_CONTROLLER));
        $graph->addEdge(new Edge('class:App\\Http\\Controllers\\OrderController', 'class:App\\Services\\Order\\OrderService', EdgeType::CALL));
        $graph->addEdge(new Edge('class:App\\Services\\Order\\OrderService', 'class:App\\Repositories\\OrderRepository', EdgeType::CALL));
        $graph->addEdge(new Edge('class:App\\Services\\Order\\OrderService', 'class:App\\Jobs\\PaymentJob', EdgeType::DISPATCH));

        return $graph;
    }

    protected function makeReport(): AnalysisReport
    {
        return new AnalysisReport(
            violations: [],
            healthScore: 72,
            grade: 'C',
            summary: ['critical' => 1, 'high' => 0, 'medium' => 1, 'low' => 2],
            nodeRiskMap: [
                'class:App\\Services\\Order\\OrderService'       => ['risk' => 'high',    'score' => 60, 'reasons' => []],
                'class:App\\Repositories\\OrderRepository'      => ['risk' => 'medium',  'score' => 30, 'reasons' => []],
                'class:App\\Jobs\\PaymentJob'                   => ['risk' => 'critical', 'score' => 85, 'reasons' => []],
                'class:App\\Http\\Controllers\\OrderController' => ['risk' => 'low',     'score' => 10, 'reasons' => []],
            ],
            metadata: ['analysis_config_hash' => 'trace-test-hash']
        );
    }
}
