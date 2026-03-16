<?php

namespace dndark\LogicMap\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use dndark\LogicMap\Tests\TestCase;

class ExportEndpointTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('logic-map:build', ['--force' => true]);
    }

    /** @test */
    public function export_json_returns_valid_structure()
    {
        $response = $this->getJson(route('logic-map.export.json'));

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'ok',
            'data' => [
                'export_version',
                'generated_at',
                'graph' => ['nodes', 'edges'],
            ],
            'message',
            'errors',
        ]);

        $this->assertTrue($response->json('ok'));
        $this->assertEquals('1.0', $response->json('data.export_version'));
        $this->assertNotEmpty($response->json('data.graph.nodes'));
    }

    /** @test */
    public function export_json_includes_analysis_when_available()
    {
        $response = $this->getJson(route('logic-map.export.json'));

        $this->assertTrue($response->json('ok'));

        // Analysis should be present since build runs analysis
        $data = $response->json('data');
        if (isset($data['analysis'])) {
            $this->assertArrayHasKey('health_score', $data['analysis']);
            $this->assertArrayHasKey('grade', $data['analysis']);
            $this->assertArrayHasKey('summary', $data['analysis']);
            $this->assertArrayHasKey('violations', $data['analysis']);
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

        $this->assertFalse($response->json('ok'));
        $this->assertNotNull($response->json('message'));

        Artisan::call('logic-map:build', ['--force' => true]);
    }

    /** @test */
    public function export_csv_returns_error_without_snapshot()
    {
        Artisan::call('logic-map:clear-cache');

        $response = $this->get(route('logic-map.export.csv'));

        $this->assertFalse(json_decode($response->getContent(), true)['ok'] ?? true);

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
