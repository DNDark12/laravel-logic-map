<?php

namespace DNDark\LogicMap\Tests\Feature;

use DNDark\LogicMap\Commands\IndexLogicMapCommand;
use DNDark\LogicMap\Contracts\SemanticGraphRepository;
use DNDark\LogicMap\Services\Query\RuntimeEvidenceMerger;
use DNDark\LogicMap\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;

final class V1RemovalContractTest extends TestCase
{
    public function test_only_the_final_v2_http_and_command_surface_remains(): void
    {
        $routeNames = array_values(array_filter(array_map(
            static fn ($route): ?string => $route->getName(),
            iterator_to_array(Route::getRoutes()),
        )));

        foreach ([
            'logic-map.index',
            'logic-map.overview',
            'logic-map.subgraph',
            'logic-map.search',
            'logic-map.meta',
            'logic-map.snapshots',
            'logic-map.diff',
            'logic-map.violations',
            'logic-map.health',
            'logic-map.hotspots',
            'logic-map.trace',
            'logic-map.report.impact',
        ] as $removed) {
            self::assertNotContains($removed, $routeNames, $removed);
        }

        foreach ([
            'logic-map.viewer',
            'logic-map.status',
            'logic-map.symbols.search',
            'logic-map.symbols.context',
            'logic-map.workflows.show',
            'logic-map.impact',
            'logic-map.modules.index',
            'logic-map.modules.show',
        ] as $active) {
            self::assertContains($active, $routeNames, $active);
        }

        $commands = array_keys(Artisan::all());

        foreach ([
            'logic-map:build',
            'logic-map:clear-cache',
            'logic-map:analyze',
            'logic-map:export-docs',
            'logic-map:export-note',
        ] as $removed) {
            self::assertNotContains($removed, $commands, $removed);
        }

        foreach (['logic-map:index', 'logic-map:status', 'logic-map:workflow', 'logic-map:impact', 'logic-map:clear'] as $active) {
            self::assertContains($active, $commands, $active);
        }

        $this->get('/logic-map')->assertOk();
        $this->get('/logic-map/v2')->assertNotFound();
    }

    public function test_v1_classes_and_config_are_absent_while_semantic_v2_remains_autoloadable(): void
    {
        foreach ([
            'DNDark\\LogicMap\\Domain\\Graph',
            'DNDark\\LogicMap\\Domain\\ErrorItem',
            'DNDark\\LogicMap\\Repositories\\CacheGraphRepository',
            'DNDark\\LogicMap\\Services\\BuildLogicMapService',
            'DNDark\\LogicMap\\Http\\Controllers\\LogicMapController',
            'DNDark\\LogicMap\\Commands\\BuildLogicMapCommand',
        ] as $removed) {
            self::assertFalse(class_exists($removed), $removed);
        }

        self::assertFalse(config()->has('logic-map.v2'));

        foreach (['cache_key', 'fingerprint_key', 'analysis_cache_key', 'cache_ttl', 'overview_node_limit'] as $removed) {
            self::assertFalse(config()->has('logic-map.'.$removed), $removed);
        }

        self::assertTrue(class_exists(IndexLogicMapCommand::class));
        self::assertTrue(class_exists(RuntimeEvidenceMerger::class));
        self::assertInstanceOf(SemanticGraphRepository::class, $this->app->make(SemanticGraphRepository::class));
        self::assertIsArray(config('logic-map.scan_paths'));
        self::assertArrayHasKey('connection', config('logic-map.storage'));
    }
}
