<?php

namespace dndark\LogicMap\Tests\Feature;

use dndark\LogicMap\Contracts\GraphRepository;
use dndark\LogicMap\Domain\AnalysisReport;
use dndark\LogicMap\Domain\Edge;
use dndark\LogicMap\Domain\Enums\Confidence;
use dndark\LogicMap\Domain\Enums\EdgeType;
use dndark\LogicMap\Domain\Enums\NodeKind;
use dndark\LogicMap\Domain\Graph;
use dndark\LogicMap\Domain\Node;
use dndark\LogicMap\Services\QueryLogicMapService;
use dndark\LogicMap\Support\FileDiscovery;
use dndark\LogicMap\Support\Fingerprint;
use dndark\LogicMap\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class SnapshotResolverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('logic-map:clear-cache');
    }

    /** @test */
    public function missing_active_pointer_falls_back_to_latest_snapshot_when_enabled()
    {
        $repo = $this->app->make(GraphRepository::class);
        $repo->putSnapshot('fp-latest', $this->makeGraph('route:/latest'));

        Cache::forget(config('logic-map.fingerprint_key'));

        $response = $this->getJson(route('logic-map.overview'));

        $response->assertStatus(200);
        $this->assertTrue($response->json('ok'));
        $this->assertSame('latest_snapshot_fallback', $response->json('data._resolution.resolved_via'));
        $this->assertSame('missing', $response->json('data._resolution.pointer_state'));
        $this->assertSame('fp-latest', $response->json('data._resolution.resolved_fingerprint'));
    }

    /** @test */
    public function corrupted_active_pointer_falls_back_to_latest_snapshot_when_enabled()
    {
        $repo = $this->app->make(GraphRepository::class);
        $repo->putSnapshot('fp-valid', $this->makeGraph('route:/valid'));

        Cache::put(config('logic-map.fingerprint_key'), 'fp-missing', 3600);

        $response = $this->getJson(route('logic-map.overview'));

        $response->assertStatus(200);
        $this->assertTrue($response->json('ok'));
        $this->assertSame('latest_snapshot_fallback', $response->json('data._resolution.resolved_via'));
        $this->assertSame('corrupted', $response->json('data._resolution.pointer_state'));
        $this->assertSame('fp-valid', $response->json('data._resolution.resolved_fingerprint'));
    }

    /** @test */
    public function corrupted_active_pointer_returns_error_when_fallback_disabled()
    {
        $repo = $this->app->make(GraphRepository::class);
        $repo->putSnapshot('fp-valid', $this->makeGraph('route:/valid'));

        Cache::put(config('logic-map.fingerprint_key'), 'fp-missing', 3600);
        config()->set('logic-map.query.resolver.fallback_on_corrupted_pointer', false);

        $response = $this->getJson(route('logic-map.overview'));

        $response->assertStatus(404);
        $this->assertFalse($response->json('ok'));
        $this->assertSame('snapshot_not_found', $response->json('errors.0.type'));
    }

    /** @test */
    public function health_returns_analysis_unavailable_when_graph_exists_without_report()
    {
        $repo = $this->app->make(GraphRepository::class);
        $repo->putSnapshot('fp-graph-only', $this->makeGraph('route:/health'));

        $response = $this->getJson(route('logic-map.health'));

        $response->assertStatus(404);
        $this->assertFalse($response->json('ok'));
        $this->assertSame('analysis_unavailable', $response->json('errors.0.type'));
    }

    /** @test */
    public function snapshots_endpoint_marks_effective_active_snapshot_as_current()
    {
        $repo = $this->app->make(GraphRepository::class);
        $repo->putSnapshot('fp-one', $this->makeGraph('route:/one'));
        $repo->putAnalysisReport('fp-one', $this->makeReport());
        $repo->putSnapshot('fp-two', $this->makeGraph('route:/two'));
        $repo->putAnalysisReport('fp-two', $this->makeReport());

        $response = $this->getJson(route('logic-map.snapshots'));

        $response->assertStatus(200);
        $this->assertSame('fp-two', $response->json('data.active_fingerprint'));
        $this->assertSame('fp-two', $response->json('data.current_fingerprint'));
        $this->assertTrue(collect($response->json('data.snapshots'))->firstWhere('fingerprint', 'fp-two')['is_current']);
        $this->assertTrue(collect($response->json('data.snapshots'))->firstWhere('fingerprint', 'fp-two')['is_active']);
    }

    /** @test */
    public function query_endpoints_do_not_invoke_discovery_or_fingerprint_on_request_path()
    {
        $repo = $this->app->make(GraphRepository::class);
        $repo->putSnapshot('fp-query', $this->makeGraph('route:/query'));
        $repo->putAnalysisReport('fp-query', $this->makeReport());

        $this->app->instance(FileDiscovery::class, new class extends FileDiscovery {
            public function findFiles(array $paths): array
            {
                throw new RuntimeException('File discovery should not be called on query path.');
            }
        });
        $this->app->instance(Fingerprint::class, new class extends Fingerprint {
            public function generate(array $files): string
            {
                throw new RuntimeException('Fingerprint generation should not be called on query path.');
            }
        });
        $this->app->forgetInstance(QueryLogicMapService::class);

        $this->getJson(route('logic-map.overview'))->assertStatus(200);
        $this->getJson(route('logic-map.health'))->assertStatus(200);
        $this->getJson(route('logic-map.snapshots'))->assertStatus(200);
    }

    protected function makeGraph(string $routeId): Graph
    {
        $graph = new Graph();
        $graph->addNode(new Node($routeId, NodeKind::ROUTE, $routeId));
        $graph->addNode(new Node('method:App\\Http\\Controllers\\DemoController@index', NodeKind::CONTROLLER, 'index'));
        $graph->addEdge(new Edge(
            $routeId,
            'method:App\\Http\\Controllers\\DemoController@index',
            EdgeType::ROUTE_TO_CONTROLLER,
            Confidence::HIGH
        ));

        return $graph;
    }

    protected function makeReport(): AnalysisReport
    {
        return new AnalysisReport(
            violations: [],
            healthScore: 100,
            grade: 'S',
            summary: ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0],
            nodeRiskMap: [],
            metadata: ['analysis_config_hash' => 'test-hash']
        );
    }
}
