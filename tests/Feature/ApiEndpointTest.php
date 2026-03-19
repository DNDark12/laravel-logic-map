<?php

namespace dndark\LogicMap\Tests\Feature;

use dndark\LogicMap\Contracts\GraphRepository;
use dndark\LogicMap\Domain\Edge;
use dndark\LogicMap\Domain\Enums\Confidence;
use dndark\LogicMap\Domain\Enums\EdgeType;
use dndark\LogicMap\Domain\Enums\NodeKind;
use dndark\LogicMap\Domain\Graph;
use dndark\LogicMap\Domain\Node;
use dndark\LogicMap\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;

class ApiEndpointTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Build snapshot before tests
        Artisan::call('logic-map:build', ['--force' => true]);
    }

    #[Test]
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

    #[Test]
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

    #[Test]
    public function index_page_contains_snapshot_and_heatmap_controls()
    {
        $response = $this->get(route('logic-map.index'));

        $response->assertStatus(200);
        $response->assertSee('snapshot-dropdown', false);
        $response->assertSee('heatmap-toggle', false);
    }

    #[Test]
    public function index_page_contains_mobile_subgraph_actions_for_small_screens()
    {
        $response = $this->get(route('logic-map.index'));

        $response->assertStatus(200);
        $response->assertSee('mobile-subgraph-controls', false);
        $response->assertSee('data-mobile-sg-depth="1"', false);
        $response->assertSee('data-mobile-action="subgraph-rerun"', false);
        $response->assertSee('data-mobile-action="subgraph-exit"', false);
    }

    #[Test]
    public function index_page_contains_panel_state_controls_for_peek_hide_and_restore()
    {
        $response = $this->get(route('logic-map.index'));

        $response->assertStatus(200);
        $response->assertSee('id="p-peek"', false);
        $response->assertSee('id="p-expand"', false);
        $response->assertSee('id="p-hide"', false);
        $response->assertSee('id="panel-restore"', false);
    }

    #[Test]
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

    #[Test]
    public function snapshots_endpoint_returns_available_snapshots()
    {
        $response = $this->getJson(route('logic-map.snapshots'));

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'ok',
            'data' => [
                'snapshots',
                'latest_fingerprint',
                'active_fingerprint',
                'current_fingerprint',
                'count',
                '_resolution' => [
                    'resolved_via',
                    'resolved_fingerprint',
                    'pointer_state',
                ],
            ],
            'message',
            'errors',
        ]);

        $this->assertTrue($response->json('ok'));
        $this->assertGreaterThanOrEqual(1, $response->json('data.count'));
        $this->assertIsArray($response->json('data.snapshots'));
        $this->assertSame(
            $response->json('data.active_fingerprint'),
            $response->json('data.current_fingerprint')
        );
    }

    #[Test]
    public function snapshot_backed_success_endpoints_include_resolution_metadata()
    {
        $overviewResponse = $this->getJson(route('logic-map.overview'));
        $nodeId = $overviewResponse->json('data.nodes.0.id');

        $endpoints = [
            route('logic-map.overview'),
            route('logic-map.search', ['q' => 'logic-map']),
            route('logic-map.meta'),
            route('logic-map.snapshots'),
            route('logic-map.subgraph', ['id' => urlencode($nodeId)]),
            route('logic-map.health'),
            route('logic-map.violations'),
            route('logic-map.hotspots'),
            route('logic-map.export.graph'),
            route('logic-map.export.analysis'),
            route('logic-map.export.bundle'),
            route('logic-map.export.json'),
        ];

        foreach ($endpoints as $endpoint) {
            $response = $this->getJson($endpoint);

            $response->assertStatus(200);
            $response->assertJsonStructure([
                'ok',
                'data' => [
                    '_resolution' => [
                        'requested_snapshot',
                        'resolved_via',
                        'resolved_fingerprint',
                        'pointer_state',
                        'analysis_state',
                    ],
                ],
                'message',
                'errors',
            ]);
        }
    }

    #[Test]
    public function search_endpoint_handles_empty_query()
    {
        $response = $this->getJson(route('logic-map.search', ['q' => '']));

        $response->assertStatus(200);
        $this->assertTrue($response->json('ok'));
    }

    #[Test]
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

    #[Test]
    public function subgraph_endpoint_returns_404_for_unknown_node()
    {
        $response = $this->getJson(route('logic-map.subgraph', ['id' => 'nonexistent:id']));

        $response->assertStatus(404);
        $this->assertFalse($response->json('ok'));
        $this->assertNotNull($response->json('message'));
    }

    #[Test]
    public function overview_endpoint_returns_404_for_unknown_snapshot()
    {
        $response = $this->getJson(route('logic-map.overview', ['snapshot' => 'missing-fingerprint']));

        $response->assertStatus(404);
        $this->assertFalse($response->json('ok'));
        $this->assertNotNull($response->json('message'));
    }

    #[Test]
    public function overview_edges_reference_only_visible_nodes()
    {
        $response = $this->getJson(route('logic-map.overview'));
        $data = $response->json('data');

        $nodeIds = array_map(fn($n) => $n['id'], $data['nodes']);
        if (!empty($data['edges'])) {
            foreach ($data['edges'] as $edge) {
                $this->assertContains($edge['source'], $nodeIds, "Edge source {$edge['source']} not found in nodes set");
                $this->assertContains($edge['target'], $nodeIds, "Edge target {$edge['target']} not found in nodes set");
            }
        } else {
            $this->assertTrue(true); // Neutral assertion for empty graph
        }
    }

    #[Test]
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

    #[Test]
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

    #[Test]
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
