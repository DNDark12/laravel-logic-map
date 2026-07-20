<?php

namespace DNDark\LogicMap\Tests\Feature;

use DNDark\LogicMap\Contracts\SemanticGraphRepository;
use DNDark\LogicMap\Repositories\Database\DatabaseGraphRepository;
use DNDark\LogicMap\Services\Indexing\IndexLogicMapService;
use DNDark\LogicMap\Services\Indexing\IndexOptions;
use DNDark\LogicMap\Support\RepositoryFileDiscovery;
use DNDark\LogicMap\Tests\Support\CommerceFixtureTestCase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Artisan;

final class V2CommandSurfaceTest extends CommerceFixtureTestCase
{
    private string $temporaryRoot;

    private DatabaseGraphRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->temporaryRoot = sys_get_temp_dir().'/logic-map-v2-commands-'.bin2hex(random_bytes(6));
        File::makeDirectory($this->temporaryRoot, 0755, true);
        $this->repository = new DatabaseGraphRepository($this->app->make('db')->connection());
        $this->app->instance(SemanticGraphRepository::class, $this->repository);
        $this->app->instance(RepositoryFileDiscovery::class, new RepositoryFileDiscovery($this->fixtureRoot()));
        config()->set('logic-map.scan_paths', ['app', 'routes', 'tests']);
        config()->set('logic-map.excludes', []);
        config()->set('logic-map.export.allow_absolute_paths', false);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->temporaryRoot);
        parent::tearDown();
    }

    public function test_final_command_signatures_are_registered_without_the_transitional_name(): void
    {
        $commands = Artisan::all();

        foreach (['logic-map:index', 'logic-map:status', 'logic-map:workflow', 'logic-map:impact', 'logic-map:clear'] as $name) {
            self::assertArrayHasKey($name, $commands);
        }

        self::assertArrayNotHasKey('logic-map:index-v2', $commands);
        self::assertTrue($commands['logic-map:index']->getDefinition()->hasOption('no-boot'));
        self::assertTrue($commands['logic-map:workflow']->getDefinition()->hasArgument('symbol'));
        self::assertTrue($commands['logic-map:impact']->getDefinition()->hasOption('base'));
        self::assertTrue($commands['logic-map:impact']->getDefinition()->hasOption('head'));
    }

    public function test_status_workflow_and_direct_symbol_impact_use_the_active_snapshot(): void
    {
        $this->index();

        $this->artisan('logic-map:status')
            ->expectsOutputToContain('Snapshot:')
            ->expectsOutputToContain('Nodes:')
            ->assertExitCode(0);
        $this->withoutMockingConsoleOutput();

        self::assertSame(0, Artisan::call('logic-map:workflow', [
            'symbol' => 'route:POST:orders/{order}/cancel',
            '--format' => 'json',
        ]));
        $workflowOutput = Artisan::output();
        self::assertJson($workflowOutput);
        $workflow = json_decode($workflowOutput, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('route:POST:orders/{order}/cancel', $workflow['entrypoint']['node_id']);
        self::assertSame(1, $workflow['summary']['branch_count']);
        self::assertSame(1, $workflow['summary']['transaction_count']);
        self::assertGreaterThanOrEqual(1, $workflow['summary']['async_boundary_count']);

        self::assertSame(0, Artisan::call('logic-map:impact', [
            'symbol' => 'method:Fixtures\\CommerceApp\\Services\\OrderService::cancel',
            '--format' => 'json',
        ]));
        self::assertStringContainsString('"affected_symbols"', Artisan::output());
    }

    public function test_invalid_formats_and_unsafe_outputs_fail_without_writing(): void
    {
        $this->index();
        $missing = $this->temporaryRoot.'/invalid.txt';

        $this->artisan('logic-map:workflow', [
            'symbol' => 'route:POST:orders/{order}/cancel',
            '--format' => 'xml',
            '--output' => $missing,
        ])->assertExitCode(1);
        self::assertFileDoesNotExist($missing);

        $this->artisan('logic-map:workflow', [
            'symbol' => 'route:POST:orders/{order}/cancel',
            '--format' => 'json',
            '--output' => '../escape.json',
        ])->assertExitCode(1);

        $this->artisan('logic-map:impact', [
            'symbol' => 'method:Fixtures\\CommerceApp\\Services\\OrderService::cancel',
            '--format' => 'json',
            '--output' => $missing,
        ])->assertExitCode(1);
        self::assertFileDoesNotExist($missing);
    }

    public function test_safe_output_is_atomic_bounded_and_requires_force_to_overwrite(): void
    {
        $this->index();
        $relative = 'storage/framework/testing/logic-map-workflow.json';
        $absolute = base_path($relative);
        File::delete($absolute);

        try {
            $this->artisan('logic-map:workflow', [
                'symbol' => 'route:POST:orders/{order}/cancel',
                '--format' => 'json',
                '--output' => $relative,
            ])->expectsOutputToContain($absolute)->assertExitCode(0);
            self::assertFileExists($absolute);

            $this->artisan('logic-map:workflow', [
                'symbol' => 'route:POST:orders/{order}/cancel',
                '--format' => 'json',
                '--output' => $relative,
            ])->assertExitCode(1);

            $this->artisan('logic-map:workflow', [
                'symbol' => 'route:POST:orders/{order}/cancel',
                '--format' => 'json',
                '--output' => $relative,
                '--force' => true,
            ])->assertExitCode(0);
            self::assertSame([], glob(dirname($absolute).'/.logic-map-*') ?: []);
        } finally {
            File::delete($absolute);
        }
    }

    public function test_clear_requires_force_in_non_interactive_mode_and_deletes_only_v2_snapshots(): void
    {
        $this->index();

        $this->artisan('logic-map:clear')
            ->expectsConfirmation('Clear the Laravel Logic Map index and runtime evidence?', 'no')
            ->assertExitCode(1);
        self::assertNotNull($this->repository->active());

        $this->artisan('logic-map:clear', ['--force' => true])
            ->expectsOutputToContain('cleared')
            ->assertExitCode(0);
        self::assertNull($this->repository->active());
    }

    private function index(): void
    {
        $this->app->make(IndexLogicMapService::class)->index(new IndexOptions(
            ['app', 'routes', 'tests'],
            [],
            true,
        ));
    }
}
