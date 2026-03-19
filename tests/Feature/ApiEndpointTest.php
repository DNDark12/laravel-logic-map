<?php

namespace Tests\Feature;

use dndark\LogicMap\Contracts\GraphRepository;
use dndark\LogicMap\Domain\Edge;
use dndark\LogicMap\Domain\Enums\Confidence;
use dndark\LogicMap\Domain\Enums\EdgeType;
use dndark\LogicMap\Domain\Enums\NodeKind;
use dndark\LogicMap\Domain\Graph;
use dndark\LogicMap\Domain\Node;
use dndark\LogicMap\LogicMapServiceProvider;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ApiEndpointTest extends TestCase
{
    protected function getPackageProviders($app)
    {
        return [
            LogicMapServiceProvider::class,
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Build snapshot before tests
        Artisan::call('logic-map:build', ['--force' => true]);
    }

    /** @test */
    public function overview_endpoint_returns_valid_envelope()
    {
        $response = $this->getJson(route('logic-map.overview'));

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'ok',
            'data' => [
                'nodes',
                'edges',
                'meta',
            ],
            'message',
            'errors',
        ]);

        $this->assertTrue($response->json('ok'));
        $this->assertNull($response->json('errors'));
    }

    /** @test */
    public function meta_endpoint_returns_statistics()
    {
        $response = $this->getJson(route('logic-map.meta'));

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'ok',
            'data' => [
                'node_count',
                'edge_count',
                'kinds',
                'edge_types',
                'available_kinds',
            ],
            'message',
            'errors',
        ]);

        $this->assertTrue($response->json('ok'));
        $this->assertGreaterThan(0, $response->json('data.node_count'));
    }

    /** @test */
    public function index_page_contains_snapshot_and_heatmap_controls()
    {
        $response = $this->get(route('logic-map.index'));

        $response->assertStatus(200);
        $response->assertSee('snapshot-dropdown', false);
        $response->assertSee('heatmap-toggle', false);
    }

    /** @test */
    public function search_endpoint_returns_matching_nodes()
    {
        $response = $this->getJson(route('logic-map.search', ['q' => 'logic-map']));

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'ok',
            'data' => [
                'nodes',
                'edges',
                'meta' => [
                    'query',
                    'total_matches',
                ],
            ],
            'message',
            'errors',
        ]);

        $this->assertTrue($response->json('ok'));
        $this->assertNotEmpty($response->json('data.nodes'));
    }

    /** @test */
    public function snapshots_endpoint_returns_available_snapshots()
    {
        $response = $this->getJson(route('logic-map.snapshots'));

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'ok',
            'data' => [
                'snapshots',
                'latest_fingerprint',
                'current_fingerprint',
                'count',
            ],
            'message',
            'errors',
        ]);

        $this->assertTrue($response->json('ok'));
        $this->assertGreaterThanOrEqual(1, $response->json('data.count'));
        $this->assertIsArray($response->json('data.snapshots'));
    }

    /** @test */
    public function search_endpoint_handles_empty_query()
    {
        $response = $this->getJson(route('logic-map.search', ['q' => '']));

        $response->assertStatus(200);
        $this->assertTrue($response->json('ok'));
    }

    /** @test */
    public function subgraph_endpoint_returns_neighborhood()
    {
        // First get a valid node ID from overview
        $overviewResponse = $this->getJson(route('logic-map.overview'));
        $nodes = $overviewResponse->json('data.nodes');

        $this->assertNotEmpty($nodes, 'Should have nodes in overview');

        $nodeId = $nodes[0]['id'];

        $response = $this->getJson(route('logic-map.subgraph', ['id' => urlencode($nodeId)]));

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'ok',
            'data' => [
                'nodes',
                'edges',
                'meta' => [
                    'focus_id',
                    'found',
                ],
            ],
            'message',
            'errors',
        ]);

        $this->assertTrue($response->json('ok'));
        $this->assertTrue($response->json('data.meta.found'));
    }

    /** @test */
    public function subgraph_endpoint_returns_404_for_unknown_node()
    {
        $response = $this->getJson(route('logic-map.subgraph', ['id' => 'nonexistent:id']));

        $response->assertStatus(404);
        $this->assertFalse($response->json('ok'));
        $this->assertNotNull($response->json('message'));
    }

    /** @test */
    public function overview_endpoint_returns_404_for_unknown_snapshot()
    {
        $response = $this->getJson(route('logic-map.overview', ['snapshot' => 'missing-fingerprint']));

        $response->assertStatus(404);
        $this->assertFalse($response->json('ok'));
        $this->assertNotNull($response->json('message'));
    }

    /** @test */
    public function overview_edges_reference_only_visible_nodes()
    {
        $response = $this->getJson(route('logic-map.overview'));
        $data = $response->json('data');

        $nodeIds = array_map(fn($n) => $n['id'], $data['nodes']);

        foreach ($data['edges'] as $edge) {
            $this->assertContains(
                $edge['source'],
                $nodeIds,
                "Edge source {$edge['source']} should be in visible nodes"
            );
            $this->assertContains(
                $edge['target'],
                $nodeIds,
                "Edge target {$edge['target']} should be in visible nodes"
            );
        }
    }

    /** @test */
    public function all_endpoints_use_consistent_envelope_on_error()
    {
        // Clear cache to simulate missing snapshot scenario
        Artisan::call('logic-map:clear-cache');

        $endpoints = [
            route('logic-map.overview'),
            route('logic-map.meta'),
            route('logic-map.search', ['q' => 'test']),
        ];

        foreach ($endpoints as $endpoint) {
            $response = $this->getJson($endpoint);

            $response->assertJsonStructure([
                'ok',
                'data',
                'message',
                'errors',
            ]);

            $this->assertFalse($response->json('ok'));
            $this->assertNotNull($response->json('message'));
        }

        // Rebuild for other tests
        Artisan::call('logic-map:build', ['--force' => true]);
    }

    /** @test */
    public function diff_endpoint_returns_graph_changes_between_two_snapshots()
    {
        Artisan::call('logic-map:clear-cache');

        /** @var GraphRepository $repo */
        $repo = $this->app->make(GraphRepository::class);
        [$base, $target] = $this->makeDiffGraphs();

        $repo->putSnapshot('fp-base', $base);
        $repo->putSnapshot('fp-target', $target);

        $response = $this->getJson(route('logic-map.diff', [
            'from' => 'fp-base',
            'to' => 'fp-target',
        ]));

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'ok',
            'data' => [
                'from_fingerprint',
                'to_fingerprint',
                'summary' => [
                    'added_nodes',
                    'removed_nodes',
                    'modified_nodes',
                    'added_edges',
                    'removed_edges',
                    'modified_edges',
                ],
                'nodes' => [
                    'added',
                    'removed',
                    'modified',
                ],
                'edges' => [
                    'added',
                    'removed',
                    'modified',
                ],
            ],
            'message',
            'errors',
        ]);

        $this->assertTrue($response->json('ok'));
        $this->assertSame('fp-base', $response->json('data.from_fingerprint'));
        $this->assertSame('fp-target', $response->json('data.to_fingerprint'));
        $this->assertSame(1, $response->json('data.summary.added_nodes'));
        $this->assertSame(1, $response->json('data.summary.removed_nodes'));
    }

    /** @test */
    public function diff_endpoint_returns_error_when_there_are_not_enough_snapshots()
    {
        Artisan::call('logic-map:clear-cache');

        /** @var GraphRepository $repo */
        $repo = $this->app->make(GraphRepository::class);
        [$base] = $this->makeDiffGraphs();
        $repo->putSnapshot('fp-only', $base);

        $response = $this->getJson(route('logic-map.diff'));

        $response->assertStatus(422);
        $this->assertFalse($response->json('ok'));
        $this->assertNotNull($response->json('message'));
    }

    /**
     * @return array{0: Graph, 1: Graph}
     */
    protected function makeDiffGraphs(): array
    {
        $base = new Graph();
        $base->addNode(new Node('class:App\\Services\\AlphaService', NodeKind::SERVICE, 'AlphaService'));
        $base->addNode(new Node('method:App\\Services\\AlphaService@run', NodeKind::SERVICE, 'run'));
        $base->addNode(new Node('method:App\\Services\\LegacyService@old', NodeKind::SERVICE, 'old'));
        $base->addEdge(new Edge(
            'method:App\\Services\\AlphaService@run',
            'method:App\\Services\\LegacyService@old',
            EdgeType::CALL,
            Confidence::HIGH
        ));

        $target = new Graph();
        $target->addNode(new Node('class:App\\Services\\AlphaService', NodeKind::SERVICE, 'AlphaService'));
        $target->addNode(new Node('method:App\\Services\\AlphaService@run', NodeKind::SERVICE, 'run', metadata: ['version' => 'v2']));
        $target->addNode(new Node('method:App\\Services\\BetaService@new', NodeKind::SERVICE, 'new'));
        $target->addEdge(new Edge(
            'method:App\\Services\\AlphaService@run',
            'method:App\\Services\\BetaService@new',
            EdgeType::CALL,
            Confidence::MEDIUM
        ));

        return [$base, $target];
    }
}
