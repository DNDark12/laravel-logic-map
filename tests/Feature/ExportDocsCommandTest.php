<?php

namespace DNDark\LogicMap\Tests\Feature;

use DNDark\LogicMap\Contracts\SemanticGraphRepository;
use DNDark\LogicMap\Repositories\Database\DatabaseGraphRepository;
use DNDark\LogicMap\Services\Indexing\IndexLogicMapService;
use DNDark\LogicMap\Services\Indexing\IndexOptions;
use DNDark\LogicMap\Support\RepositoryFileDiscovery;
use DNDark\LogicMap\Tests\Support\CommerceFixtureTestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

final class ExportDocsCommandTest extends CommerceFixtureTestCase
{
    private string $relativeOutput;

    private string $absoluteOutput;

    protected function setUp(): void
    {
        parent::setUp();
        $this->relativeOutput = 'storage/framework/testing/logic-map-docs-'.bin2hex(random_bytes(6));
        $this->absoluteOutput = base_path($this->relativeOutput);
        $this->app->instance(
            SemanticGraphRepository::class,
            new DatabaseGraphRepository($this->app->make('db')->connection()),
        );
        $this->app->instance(RepositoryFileDiscovery::class, new RepositoryFileDiscovery($this->fixtureRoot()));
        config()->set('logic-map.scan_paths', ['app', 'routes', 'tests']);
        config()->set('logic-map.excludes', []);
        config()->set('logic-map.export.allow_absolute_paths', false);

        $this->app->make(IndexLogicMapService::class)->index(new IndexOptions(
            ['app', 'routes', 'tests'],
            [],
            true,
        ));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->absoluteOutput);
        parent::tearDown();
    }

    public function test_export_docs_writes_v2_module_and_workflow_dossiers_from_the_active_snapshot(): void
    {
        self::assertArrayHasKey('logic-map:export-docs', Artisan::all());
        self::assertSame(0, Artisan::call('logic-map:export-docs', [
            '--output' => $this->relativeOutput,
        ]));

        self::assertFileExists($this->absoluteOutput.'/overview.md');
        self::assertFileExists($this->absoluteOutput.'/modules/orders.md');
        $module = File::get($this->absoluteOutput.'/modules/orders.md');
        self::assertStringContainsString('# Module workflow Orders', $module);
        self::assertStringContainsString('target_id: "module:Orders"', $module);
        self::assertGreaterThan(1, substr_count($module, '```mermaid'));
        self::assertStringNotContainsString('## Changed symbols', $module);

        $workflows = File::glob($this->absoluteOutput.'/workflows/*.md');
        self::assertGreaterThan(1, count($workflows));
        self::assertStringContainsString('## Diagram', File::get($workflows[0]));
    }

    public function test_export_docs_requires_force_and_rejects_paths_outside_the_repository(): void
    {
        self::assertSame(0, Artisan::call('logic-map:export-docs', [
            '--output' => $this->relativeOutput,
        ]));
        self::assertSame(1, Artisan::call('logic-map:export-docs', [
            '--output' => $this->relativeOutput,
        ]));
        self::assertSame(0, Artisan::call('logic-map:export-docs', [
            '--output' => $this->relativeOutput,
            '--force' => true,
        ]));
        self::assertSame(1, Artisan::call('logic-map:export-docs', [
            '--output' => '../logic-map-escape',
        ]));
    }
}
