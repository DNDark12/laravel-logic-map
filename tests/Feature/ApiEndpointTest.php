<?php

namespace dndark\LogicMap\Tests\Feature;

use dndark\LogicMap\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;

class ApiEndpointTest extends TestCase
{
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
    public function search_endpoint_returns_matching_nodes()
    {
        $response = $this->getJson(route('logic-map.search', ['q' => 'Service']));

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

        $response = $this->getJson(route('logic-map.subgraph', ['id' => $nodeId]));

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
}

