<?php

namespace dndark\LogicMap\Tests\Feature;

use dndark\LogicMap\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;

class AnalysisEndpointTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('logic-map:build', ['--force' => true]);
    }

    /** @test */
    public function violations_endpoint_returns_valid_envelope()
    {
        $response = $this->getJson(route('logic-map.violations'));

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'ok',
            'data' => [
                'violations',
                'summary',
            ],
            'message',
            'errors',
        ]);

        $this->assertTrue($response->json('ok'));
        $this->assertNull($response->json('errors'));
    }

    /** @test */
    public function violations_summary_has_all_severity_keys()
    {
        $response = $this->getJson(route('logic-map.violations'));
        $summary = $response->json('data.summary');

        $this->assertArrayHasKey('critical', $summary);
        $this->assertArrayHasKey('high', $summary);
        $this->assertArrayHasKey('medium', $summary);
        $this->assertArrayHasKey('low', $summary);
    }

    /** @test */
    public function violations_can_filter_by_severity()
    {
        $response = $this->getJson(route('logic-map.violations', ['severity' => 'critical']));

        $response->assertStatus(200);
        $this->assertTrue($response->json('ok'));

        $violations = $response->json('data.violations');
        foreach ($violations as $v) {
            $this->assertEquals('critical', $v['severity']);
        }
    }

    /** @test */
    public function violations_can_filter_by_type()
    {
        $response = $this->getJson(route('logic-map.violations', ['type' => 'fat_controller']));

        $response->assertStatus(200);
        $this->assertTrue($response->json('ok'));

        $violations = $response->json('data.violations');
        foreach ($violations as $v) {
            $this->assertEquals('fat_controller', $v['type']);
        }
    }

    /** @test */
    public function health_endpoint_returns_valid_envelope()
    {
        $response = $this->getJson(route('logic-map.health'));

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'ok',
            'data' => [
                'score',
                'grade',
                'summary',
                'graph_stats' => [
                    'total_nodes',
                    'total_edges',
                    'avg_fan_out',
                    'max_depth',
                ],
            ],
            'message',
            'errors',
        ]);

        $this->assertTrue($response->json('ok'));
    }

    /** @test */
    public function health_score_is_between_0_and_100()
    {
        $response = $this->getJson(route('logic-map.health'));

        $score = $response->json('data.score');
        $this->assertGreaterThanOrEqual(0, $score);
        $this->assertLessThanOrEqual(100, $score);
    }

    /** @test */
    public function health_grade_is_valid_letter()
    {
        $response = $this->getJson(route('logic-map.health'));

        $grade = $response->json('data.grade');
        $this->assertContains($grade, ['A', 'B', 'C', 'D', 'F']);
    }

    /** @test */
    public function health_has_graph_stats()
    {
        $response = $this->getJson(route('logic-map.health'));

        $stats = $response->json('data.graph_stats');
        $this->assertGreaterThan(0, $stats['total_nodes']);
        $this->assertGreaterThanOrEqual(0, $stats['total_edges']);
    }

    /** @test */
    public function violations_returns_error_without_snapshot()
    {
        Artisan::call('logic-map:clear-cache');

        $response = $this->getJson(route('logic-map.violations'));

        $response->assertJsonStructure(['ok', 'data', 'message', 'errors']);
        $this->assertFalse($response->json('ok'));
        $this->assertNotNull($response->json('message'));

        // Rebuild for other tests
        Artisan::call('logic-map:build', ['--force' => true]);
    }

    /** @test */
    public function health_returns_error_without_snapshot()
    {
        Artisan::call('logic-map:clear-cache');

        $response = $this->getJson(route('logic-map.health'));

        $response->assertJsonStructure(['ok', 'data', 'message', 'errors']);
        $this->assertFalse($response->json('ok'));

        // Rebuild for other tests
        Artisan::call('logic-map:build', ['--force' => true]);
    }

    /** @test */
    public function existing_overview_endpoint_still_works()
    {
        $response = $this->getJson(route('logic-map.overview'));

        $response->assertStatus(200);
        $this->assertTrue($response->json('ok'));
        $this->assertNotEmpty($response->json('data.nodes'));
    }

    /** @test */
    public function existing_meta_endpoint_still_works()
    {
        $response = $this->getJson(route('logic-map.meta'));

        $response->assertStatus(200);
        $this->assertTrue($response->json('ok'));
        $this->assertGreaterThan(0, $response->json('data.node_count'));
    }
}
