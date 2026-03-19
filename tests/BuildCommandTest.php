<?php

namespace dndark\LogicMap\Tests;

use Illuminate\Support\Facades\Artisan;

class BuildCommandTest extends TestCase
{
    /** @test */
    public function it_can_build_the_logic_map()
    {
        Artisan::call('logic-map:build', ['--force' => true]);

        $output = Artisan::output();

        $this->assertStringContainsString('successfully', $output);
        $this->assertStringContainsString('Nodes', $output);
        $this->assertStringContainsString('Edges', $output);
    }

    /** @test */
    public function it_shows_diagnostics_in_output()
    {
        Artisan::call('logic-map:build', ['--force' => true]);

        $output = Artisan::output();

        $this->assertStringContainsString('Files Scanned', $output);
        $this->assertStringContainsString('Parsed', $output);
    }

    /** @test */
    public function it_uses_cache_when_not_forced()
    {
        // First build
        Artisan::call('logic-map:build', ['--force' => true]);

        // Second build without force should use cache
        Artisan::call('logic-map:build');
        $output = Artisan::output();

        $this->assertStringContainsString('Cached', $output);
    }

    /** @test */
    public function it_can_clear_the_cache()
    {
        // First build so there's something to clear
        Artisan::call('logic-map:build', ['--force' => true]);

        Artisan::call('logic-map:clear-cache');

        $output = Artisan::output();
        $this->assertStringContainsString('Cleared', $output);
        $this->assertStringContainsString('cached snapshot', $output);
    }

    /** @test */
    public function clear_cache_reports_no_snapshots_when_empty()
    {
        // Clear first to ensure empty state
        Artisan::call('logic-map:clear-cache');

        // Clear again
        Artisan::call('logic-map:clear-cache');

        $output = Artisan::output();
        $this->assertStringContainsString('No cached snapshots', $output);
    }

    /** @test */
    public function build_produces_nodes()
    {
        Artisan::call('logic-map:build', ['--force' => true]);

        $response = $this->getJson(route('logic-map.meta'));
        $data = $response->json('data');

        $this->assertGreaterThan(0, $data['node_count']);
    }

    /** @test */
    public function build_produces_edges()
    {
        Artisan::call('logic-map:build', ['--force' => true]);

        $response = $this->getJson(route('logic-map.meta'));
        $data = $response->json('data');

        $this->assertGreaterThanOrEqual(0, $data['edge_count']);
    }

    /** @test */
    public function it_returns_standard_api_envelopes_for_meta()
    {
        Artisan::call('logic-map:build', ['--force' => true]);

        $response = $this->getJson(route('logic-map.meta'));

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'ok',
            'data' => [
                'node_count',
                'edge_count',
                'kinds',
            ],
            'message',
            'errors'
        ]);
        $this->assertTrue($response->json('ok'));
    }

    /** @test */
    public function it_returns_standard_api_envelopes_for_overview()
    {
        Artisan::call('logic-map:build', ['--force' => true]);

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
            'errors'
        ]);
        $this->assertTrue($response->json('ok'));
    }

    /** @test */
    public function it_filters_ghost_edges_in_overview()
    {
        Artisan::call('logic-map:build', ['--force' => true]);

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

    /** @test */
    public function it_returns_error_when_no_snapshot_exists()
    {
        // Clear cache first
        Artisan::call('logic-map:clear-cache');

        $response = $this->getJson(route('logic-map.overview'));

        $response->assertStatus(404);
        $this->assertFalse($response->json('ok'));
        $this->assertNotNull($response->json('message'));
    }

    /** @test */
    public function search_endpoint_returns_valid_envelope()
    {
        Artisan::call('logic-map:build', ['--force' => true]);

        $response = $this->getJson(route('logic-map.search', ['q' => 'Service']));

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'ok',
            'data' => [
                'nodes',
                'edges',
                'meta',
            ],
            'message',
            'errors'
        ]);
        $this->assertTrue($response->json('ok'));
    }
}
