<?php

namespace DNDark\LogicMap\Tests\Feature;

use DNDark\LogicMap\Contracts\SemanticGraphRepository;
use DNDark\LogicMap\Repositories\Sqlite\SqliteConnectionFactory;
use DNDark\LogicMap\Repositories\Sqlite\SqliteGraphRepository;
use DNDark\LogicMap\Support\RepositoryFileDiscovery;
use DNDark\LogicMap\Tests\TestCase;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;

final class IndexCommandTest extends TestCase
{
    private string $repositoryRoot;

    private SqliteGraphRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repositoryRoot = sys_get_temp_dir().'/logic-map-command-'.bin2hex(random_bytes(6));
        File::makeDirectory($this->repositoryRoot.'/app/Services', 0755, true);
        file_put_contents($this->repositoryRoot.'/composer.json', json_encode([
            'autoload' => ['psr-4' => ['App\\' => 'app/']],
        ], JSON_THROW_ON_ERROR));
        $this->writeValidSource();

        config()->set('logic-map.scan_paths', ['app']);
        config()->set('logic-map.excludes', []);
        config()->set('logic-map.evidence.expression_max_length', 32);
        self::assertSame(['app'], config('logic-map.scan_paths'));
        self::assertFalse(config()->has('logic-map.v2'));

        $this->repository = new SqliteGraphRepository(
            new SqliteConnectionFactory($this->repositoryRoot.'/index.sqlite'),
        );
        $this->app->instance(RepositoryFileDiscovery::class, new RepositoryFileDiscovery($this->repositoryRoot));
        $this->app->instance(SemanticGraphRepository::class, $this->repository);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->repositoryRoot);
        parent::tearDown();
    }

    public function test_force_command_indexes_reports_counts_and_activates_the_snapshot(): void
    {
        $this->artisan('logic-map:index', ['--force' => true])
            ->expectsOutputToContain('Snapshot:')
            ->expectsOutputToContain('Nodes:')
            ->expectsOutputToContain('Edges:')
            ->expectsOutputToContain('Evidence:')
            ->expectsOutputToContain('Diagnostics:')
            ->assertExitCode(0);

        $active = $this->repository->active();
        self::assertNotNull($active);
        self::assertNotEmpty($active->graph->nodes());
        self::assertNotEmpty($active->graph->edges());
        self::assertNotEmpty($active->graph->evidence());
        self::assertNotEmpty($active->processSteps);
        $callEvidence = array_values(array_filter(
            $active->graph->evidence(),
            static fn ($evidence): bool => $evidence->detector === 'call-target-resolver',
        ));
        self::assertNotEmpty($callEvidence);
        self::assertLessThanOrEqual(32, max(array_map(
            static fn ($evidence): int => strlen((string) $evidence->expression),
            $callEvidence,
        )));
        self::assertNotEmpty(config('logic-map.scan_paths'));
        self::assertStringEndsWith(
            'storage/framework/logic-map/index.sqlite',
            config('logic-map.storage.sqlite_path'),
        );
        self::assertGreaterThan(0, config('logic-map.evidence.expression_max_length'));
    }

    public function test_parse_failure_is_non_zero_and_preserves_the_previous_active_snapshot(): void
    {
        $this->artisan('logic-map:index', ['--force' => true])->assertExitCode(0);
        $activeId = $this->repository->active()?->id;
        file_put_contents(
            $this->repositoryRoot.'/app/Services/OrderService.php',
            '<?php namespace App\Services; final class Broken { public function nope( }',
        );

        $this->artisan('logic-map:index', ['--force' => true])
            ->expectsOutputToContain('Index failed:')
            ->assertExitCode(1);

        self::assertSame($activeId, $this->repository->active()?->id);
        self::assertCount(1, $this->repository->list());
    }

    public function test_rejects_storage_paths_outside_storage_root(): void
    {
        foreach (['../outside.sqlite', '/tmp/outside.sqlite'] as $path) {
            config()->set('logic-map.storage.sqlite_path', $path);
            $this->app->forgetInstance(SqliteConnectionFactory::class);

            try {
                $this->app->make(SqliteConnectionFactory::class);
                self::fail("Storage path {$path} should be rejected.");
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('SQLite', $exception->getMessage());
            }
        }
    }

    public function test_no_boot_indexes_static_semantics_without_collecting_boot_facts(): void
    {
        $this->artisan('logic-map:index', ['--force' => true, '--no-boot' => true])
            ->assertExitCode(0);

        self::assertSame(
            0,
            $this->repository->active()?->phaseMetrics['collect_laravel_boot']['boot_fact_count'] ?? null,
        );
    }

    private function writeValidSource(): void
    {
        file_put_contents($this->repositoryRoot.'/app/Services/OrderService.php', <<<'PHP'
<?php
namespace App\Services;

use Illuminate\Contracts\Queue\ShouldQueue;

final class Gateway
{
    public function saveWithVeryLongMethodName(object $order): void {}
}

final class OrderService
{
    public function __construct(private Gateway $gateway) {}

    public function cancel(object $order): void
    {
        $this->gateway->saveWithVeryLongMethodName($order);
    }
}

final class ReconcileInventoryJob implements ShouldQueue
{
    public function __construct(private OrderService $orders) {}

    public function handle(object $order): void
    {
        $this->orders->cancel($order);
    }
}
PHP);
    }
}
