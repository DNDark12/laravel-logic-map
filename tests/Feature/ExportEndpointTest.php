<?php

namespace dndark\LogicMap\Tests\Feature;

use dndark\LogicMap\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;

class ExportEndpointTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('logic-map:build', ['--force' => true]);
    }

    /** @test */
    public function export_json_alias_returns_bundle_structure()
    {
        $response = $this->getJson(route('logic-map.export.json'));

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'ok',
            'data' => [
                'graph' => [
                    'nodes',
                    'edges',
                    'metadata' => ['fingerprint', 'generated_at'],
                ],
                'analysis' => [
                    'health_score',
                    'grade',
                    'summary',
                    'violations',
                    'node_risk_map',
                    'metadata',
                ],
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
        $this->assertNotEmpty($response->json('data.graph.nodes'));
        $this->assertNotEmpty($response->json('data.analysis'));
        $this->assertNull($response->json('errors'));
    }

    /** @test */
    public function export_graph_returns_only_graph_payload()
    {
        $response = $this->getJson(route('logic-map.export.graph'));

        $response->assertStatus(200);
        $this->assertTrue($response->json('ok'));
        $this->assertArrayHasKey('graph', $response->json('data'));
        $this->assertArrayNotHasKey('analysis', $response->json('data'));
    }

    /** @test */
    public function export_analysis_returns_only_analysis_payload()
    {
        $response = $this->getJson(route('logic-map.export.analysis'));

        $response->assertStatus(200);
        $this->assertTrue($response->json('ok'));
        $this->assertArrayHasKey('analysis', $response->json('data'));
        $this->assertArrayNotHasKey('graph', $response->json('data'));
    }

    /** @test */
    public function export_bundle_returns_same_shape_as_json_alias()
    {
        $bundle = $this->getJson(route('logic-map.export.bundle'));
        $alias = $this->getJson(route('logic-map.export.json'));

        $bundle->assertStatus(200);
        $alias->assertStatus(200);
        $this->assertSame($bundle->json('data'), $alias->json('data'));
    }

    /** @test */
    public function export_bundle_does_not_mutate_graph_nodes_with_risk()
    {
        $response = $this->getJson(route('logic-map.export.bundle'));

        $nodes = $response->json('data.graph.nodes');
        $riskMap = $response->json('data.analysis.node_risk_map');

        $this->assertIsArray($nodes);
        $this->assertIsArray($riskMap);

        foreach ($nodes as $node) {
            $this->assertArrayNotHasKey('risk', $node);
        }
    }

    /** @test */
    public function export_csv_returns_csv_content()
    {
        $response = $this->get(route('logic-map.export.csv'));

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $content = $response->getContent();
        $this->assertStringContainsString('id,kind,name', $content);
        $this->assertStringContainsString('instability', $content);
        $this->assertStringContainsString('coupling', $content);
    }

    /** @test */
    public function export_csv_has_download_header()
    {
        $response = $this->get(route('logic-map.export.csv'));

        $response->assertStatus(200);
        $disposition = $response->headers->get('Content-Disposition');
        $this->assertStringContainsString('attachment', $disposition);
        $this->assertStringContainsString('.csv', $disposition);
    }

    /** @test */
    public function export_json_returns_error_without_snapshot()
    {
        Artisan::call('logic-map:clear-cache');

        $response = $this->getJson(route('logic-map.export.json'));

        $response->assertStatus(404);
        $this->assertFalse($response->json('ok'));
        $this->assertNotNull($response->json('message'));
        $this->assertSame('snapshot_not_found', $response->json('errors.0.type'));

        Artisan::call('logic-map:build', ['--force' => true]);
    }

    /** @test */
    public function export_csv_returns_error_without_snapshot()
    {
        Artisan::call('logic-map:clear-cache');

        $response = $this->get(route('logic-map.export.csv'));

        $response->assertStatus(404);
        $payload = json_decode($response->getContent(), true);

        $this->assertFalse($payload['ok'] ?? true);
        $this->assertSame('snapshot_not_found', $payload['errors'][0]['type'] ?? null);

        Artisan::call('logic-map:build', ['--force' => true]);
    }

    /** @test */
    public function analyze_command_works_with_existing_snapshot()
    {
        $this->artisan('logic-map:analyze')
            ->assertExitCode(0);
    }

    /** @test */
    public function analyze_command_fails_without_snapshot()
    {
        Artisan::call('logic-map:clear-cache');

        $this->artisan('logic-map:analyze')
            ->assertExitCode(1);

        Artisan::call('logic-map:build', ['--force' => true]);
    }

    /** @test */
    public function analyze_command_shows_violations_with_flag()
    {
        $this->artisan('logic-map:analyze', ['--show-violations' => true])
            ->assertExitCode(0);
    }
}
