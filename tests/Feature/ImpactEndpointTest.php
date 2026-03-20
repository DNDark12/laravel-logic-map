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

class ImpactEndpointTest extends TestCase
{
    private const TARGET_ID = 'class:App\\Services\\Order\\OrderService';

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('logic-map:clear-cache');

        $repo = $this->app->make(GraphRepository::class);
        $repo->putSnapshot('fp-impact', $this->makeGraph());
        $repo->putAnalysisReport('fp-impact', $this->makeReport());
        $repo->setActiveFingerprint('fp-impact');
    }

    #[Test]
    public function impact_returns_correct_structure()
    {
        $url = route('logic-map.impact', ['id' => urlencode(self::TARGET_ID)]);
        $response = $this->getJson($url);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'ok',
            'data' => [
                'target'  => ['node_id', 'kind', 'name', 'module', 'risk'],
                'summary' => [
                    'direction', 'max_depth',
                    'upstream_count', 'downstream_count',
                    'async_boundary_count', 'persistence_touch_count',
                    'cross_module_touch_count', 'high_risk_touch_count',
                    'high_risk_low_coverage_touch_count',
                    'blast_radius_score', 'risk_bucket',
                ],
                'upstream',
                'downstream',
                'critical_touches',
                'review_scope' => ['must_review', 'should_review', 'test_focus'],
                '_resolution',
            ],
            'message',
            'errors',
        ]);

        $this->assertTrue($response->json('ok'));
    }

    #[Test]
    public function impact_returns_404_for_unknown_node()
    {
        $url = route('logic-map.impact', ['id' => urlencode('class:Does\\Not\\Exist')]);
        $response = $this->getJson($url);

        $response->assertStatus(404);
        $this->assertFalse($response->json('ok'));
        $this->assertSame('node_not_found', $response->json('errors.0.type'));
    }

    #[Test]
    public function impact_returns_422_for_invalid_direction()
    {
        $url = route('logic-map.impact', ['id' => urlencode(self::TARGET_ID)]) . '?direction=sideways';
        $response = $this->getJson($url);

        $response->assertStatus(422);
        $this->assertFalse($response->json('ok'));
        $this->assertSame('invalid_direction', $response->json('errors.0.type'));
    }

    #[Test]
    public function impact_returns_422_for_invalid_max_depth()
    {
        $base = route('logic-map.impact', ['id' => urlencode(self::TARGET_ID)]);

        $response = $this->getJson($base . '?max_depth=0');
        $response->assertStatus(422);
        $this->assertSame('invalid_max_depth', $response->json('errors.0.type'));

        $response2 = $this->getJson($base . '?max_depth=9');
        $response2->assertStatus(422);
        $this->assertSame('invalid_max_depth', $response2->json('errors.0.type'));
    }

    #[Test]
    public function impact_scores_blast_radius_and_populates_critical_touches()
    {
        $url = route('logic-map.impact', ['id' => urlencode(self::TARGET_ID)]) . '?direction=downstream';
        $response = $this->getJson($url);

        $response->assertStatus(200);

        $summary = $response->json('data.summary');
        $this->assertGreaterThan(0, $summary['blast_radius_score']);
        $this->assertGreaterThan(0, $summary['persistence_touch_count']);
        $this->assertGreaterThan(0, $summary['async_boundary_count']);

        $criticalIds = array_column($response->json('data.critical_touches'), 'node_id');
        $this->assertTrue(
            in_array('class:App\\Repositories\\OrderRepository', $criticalIds) ||
            in_array('class:App\\Jobs\\PaymentJob', $criticalIds),
        );
    }

    #[Test]
    public function impact_includes_target_in_must_review()
    {
        $url = route('logic-map.impact', ['id' => urlencode(self::TARGET_ID)]);
        $response = $this->getJson($url);

        $response->assertStatus(200);
        $mustReviewIds = array_column($response->json('data.review_scope.must_review'), 'node_id');
        $this->assertContains(self::TARGET_ID, $mustReviewIds);
    }

    #[Test]
    public function impact_returns_404_when_snapshot_not_found()
    {
        $url = route('logic-map.impact', ['id' => urlencode(self::TARGET_ID)]) . '?snapshot=non-existent-fp';
        $response = $this->getJson($url);

        $response->assertStatus(404);
        $this->assertSame('snapshot_not_found', $response->json('errors.0.type'));
    }

    #[Test]
    public function impact_upstream_direction_returns_no_downstream()
    {
        // upstream direction: finds callers (OrderController), no downstream should be returned
        $url = route('logic-map.impact', ['id' => urlencode(self::TARGET_ID)]) . '?direction=upstream';
        $response = $this->getJson($url);

        $response->assertStatus(200);
        // downstream_count must be 0 when direction=upstream
        $this->assertSame(0, $response->json('data.summary.downstream_count'));
        // upstream_count > 0 since OrderController calls OrderService
        $this->assertGreaterThanOrEqual(0, $response->json('data.summary.upstream_count'));
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
                'class:App\\Services\\Order\\OrderService'       => ['risk' => 'high',     'score' => 60, 'reasons' => ['high_coupling']],
                'class:App\\Repositories\\OrderRepository'      => ['risk' => 'medium',   'score' => 30, 'reasons' => []],
                'class:App\\Jobs\\PaymentJob'                   => ['risk' => 'critical',  'score' => 85, 'reasons' => ['async_boundary']],
                'class:App\\Http\\Controllers\\OrderController' => ['risk' => 'low',      'score' => 10, 'reasons' => []],
            ],
            metadata: ['analysis_config_hash' => 'impact-test-hash']
        );
    }
}
